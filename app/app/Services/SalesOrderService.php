<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\LineSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\RejectionStatus;
use App\Exceptions\ConfirmationConflict;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderRejectionReason;
use App\Models\UnitMaster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales Order workflow (rule 10): SO is DEMAND ONLY.
 *
 * Neither creating, editing nor confirming an order touches inventory:
 * no reservation, no unrestricted/restricted change, no inventory_movement
 * rows. Demand may exceed available stock; availability is informational.
 *
 * Customer semantics (preserved independently):
 *   supplying_customer_id = selected supplying Primary
 *   source_customer_id    = that Primary or one of its SHIP_TOs
 *   sold_to_customer_id   = customer whose demand is captured
 * The invoice debtor mapping stays unresolved (docs risk 3).
 *
 * Supplying-Primary eligibility: a Sales Employee may supply only through a
 * PRIMARY that is assigned to them via `customer_employee` — there is no
 * fixed Primary → Secondary mapping (a Secondary may be served through any
 * of the employee's assigned Primaries, but never through an unassigned one).
 * Customers remain global and carry no company_id.
 *
 * VAN one-cycle rule (audited 2026-10-01): a VAN is commercially a Secondary
 * customer — same SO → Delivery → Shipment START → POD → Invoice → Payment
 * lifecycle, no VAN inventory-count / closing-stock / route-settlement
 * subsystem — but it may hold only ONE unresolved cycle at a time. A new VAN
 * SO is refused while the previous cycle still has operational work
 * (unallocated demand, a released allocation, an undispatched allocation,
 * dispatched quantity awaiting POD, POD-accepted quantity not yet invoiced)
 * or a positive Phase 8 net exposure. The rule is server-side, derived from
 * existing states only and enforced under the VAN's own customer_master row
 * lock; ordinary Secondary customers are never restricted this way.
 */
class SalesOrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly DealService $deals,
        private readonly ProductUnitService $units,
        private readonly InvoiceService $invoices,
        private readonly DealEntitlementService $entitlements,
        private readonly SalesLifecycleService $lifecycle,
    ) {}

    /**
     * Primaries the employee is authorized to supply through
     * (assigned via `customer_employee`). The capture UI and the server-side
     * validations share this single definition of eligibility.
     *
     * @return Collection<int, string>
     */
    public function eligibleSupplyingIds(EmployeeMaster $employee): Collection
    {
        return CustomerEmployee::where('employee_id', $employee->employee_id)
            ->whereIn('customer_id', CustomerMaster::where('customer_type', CustomerType::PRIMARY)->where('active', true)->select('customer_id'))
            ->pluck('customer_id')
            ->unique()
            ->values();
    }

    /**
     * Recent-purchase GUIDANCE for order capture (read-only).
     *
     * GUIDANCE ONLY: the caller must never auto-create lines, mandate a product
     * or constrain a quantity from this data. Scoped to
     *   - the employee's COMPANY (customers are global — a customer's history
     *     at another company is never exposed), and
     *   - the employee's product scope (`employee_product`; empty = all
     *     company products).
     * Only non-DRAFT orders count as purchases; the newest `$limit` order
     * lines are returned.
     *
     * @return Collection<int, array{sales_order_no: string, product_id: string, product: string, quantity: string, unit: string, is_free_item: bool, date: ?string}>
     */
    public function recentPurchaseHistory(EmployeeMaster $employee, string $customerId, int $limit = 5): Collection
    {
        if ($limit < 1) {
            return collect();
        }

        $scopedProducts = EmployeeProduct::where('employee_id', $employee->employee_id)->pluck('product_id');

        $orders = SalesOrder::query()
            ->where('company_id', $employee->company_id)
            ->where('sold_to_customer_id', $customerId)
            ->where('order_status', '!=', OrderStatus::DRAFT->value)
            ->with([
                'items' => function ($query) use ($scopedProducts) {
                    if ($scopedProducts->isNotEmpty()) {
                        $query->whereIn('product_id', $scopedProducts);
                    }

                    $query->orderBy('item_no');
                },
                'items.product',
            ])
            ->orderByDesc('order_date')
            ->orderByDesc('sales_order_no')
            ->limit($limit)
            ->get();

        return $orders
            ->flatMap(fn (SalesOrder $order) => $order->items->map(fn (SalesOrderItem $item) => [
                'sales_order_no' => $order->sales_order_no,
                'product_id' => $item->product_id,
                'product' => $item->product?->product_description ?? $item->product_id,
                'quantity' => (string) $item->order_qty,
                'unit' => (string) $item->order_unit,
                'is_free_item' => (bool) $item->is_free_item,
                'date' => ($order->confirmed_at ?? $order->order_date)?->toDateString(),
            ]))
            ->take($limit)
            ->values();
    }

    /**
     * Create a DRAFT order. Returns [order, warnings].
     *
     * @param  Collection<int, array{product_id: string, qty: string, unit: string, unit_price?: ?string, price_override_reason?: ?string}>  $lines
     * @return array{order: SalesOrder, warnings: array<int, string>}
     */
    public function createDraft(
        EmployeeMaster $employee,
        string $supplyingCustomerId,
        string $sourceCustomerId,
        string $soldToCustomerId,
        $lines,
        OrderType $orderType = OrderType::STANDARD,
        ?string $idempotencyInfo = null,
    ): array {
        [$supplying, $source, $soldTo] = $this->validateCustomers(
            $employee, $supplyingCustomerId, $sourceCustomerId, $soldToCustomerId,
        );

        // The SO company is the SELLING company (the employee's employer):
        // customers are global and carry no company_id (see ddl.sql).
        $companyId = $employee->company_id;

        $order = null;

        DB::transaction(function () use (&$order, $employee, $supplying, $source, $soldTo, $lines, $orderType, $companyId) {
            $order = SalesOrder::create([
                'sales_order_no' => $this->nextNumber($companyId),
                'company_id' => $companyId,
                'supplying_customer_id' => $supplying->customer_id,
                'source_customer_id' => $source->customer_id,
                'sold_to_customer_id' => $soldTo->customer_id,
                'sales_employee_id' => $employee->employee_id,
                'order_type' => $orderType,
                'order_status' => OrderStatus::DRAFT,
                'order_date' => now(),
                'pricing_date' => today(),
                'currency' => 'NGN',
            ]);

            $this->replaceItems($order, $lines, $companyId, $soldTo->sales_region);

            // VAN one-cycle rule — LAST lock of this transaction (see
            // assertVanCycleClear): the lines are already written, so no
            // insert can wait on a lock while the VAN row is held. Drafts are
            // excluded from the check itself: a DRAFT is not demand and carries
            // no physical/financial obligation, so it can never trap a VAN
            // (and the confirmation boundary re-checks authoritatively).
            $this->assertVanCycleClear($soldTo->customer_id, $companyId, $order->sales_order_no);
        });

        return ['order' => $order->fresh(['items']), 'warnings' => []];
    }

    /**
     * Update a DRAFT's lines. Confirmed demand is immutable (rule 10):
     * only DRAFT orders can be edited.
     *
     * @param  Collection<int, array{product_id: string, qty: string, unit: string, unit_price?: ?string, price_override_reason?: ?string}>  $lines
     * @return array{order: SalesOrder, warnings: array<int, string>}
     */
    public function updateDraft(SalesOrder $order, $lines): array
    {
        // Re-read under lock: an in-memory model may predate a concurrent confirm.
        $current = SalesOrder::whereKey($order->sales_order_no)->lockForUpdate()->first();

        if ($current === null || $current->order_status !== OrderStatus::DRAFT) {
            abort(422, 'Only DRAFT orders can be edited. Confirmed demand is immutable.');
        }

        // The supplying Primary must STILL be assigned to the order's sales
        // employee (an assignment removed after drafting blocks the edit too).
        $employee = EmployeeMaster::findOrFail($order->sales_employee_id);

        if (! $this->eligibleSupplyingIds($employee)->contains($order->supplying_customer_id)) {
            abort(403, 'The supplying Primary is no longer assigned to you. Order capture is blocked until assignment is restored.');
        }

        $soldTo = CustomerMaster::findOrFail($order->sold_to_customer_id);

        DB::transaction(function () use ($order, $lines, $soldTo) {
            $this->replaceItems($order, $lines, $order->company_id, $soldTo->sales_region);
        });

        return ['order' => $order->fresh(['items']), 'warnings' => []];
    }

    /**
     * Authoritative confirmation: re-resolves pricing and re-evaluates deals
     * against CURRENT server master data, applies overrides, snapshots
     * recommendations, then locks the demand (rule 10/28).
     *
     * @return array{order: SalesOrder, warnings: array<int, string>, conflicts: array<int, string>}
     */
    public function confirm(SalesOrder $order): array
    {
        if ($order->order_status !== OrderStatus::DRAFT) {
            abort(422, 'Order is not a draft.');
        }

        $warnings = [];
        $conflicts = [];

        try {
            DB::transaction(function () use ($order, &$warnings, &$conflicts) {
                $conflicts = []; // reset on a concurrency retry

                $order = SalesOrder::whereKey($order->sales_order_no)->lockForUpdate()->firstOrFail();

                if ($order->order_status !== OrderStatus::DRAFT) {
                    abort(422, 'Order was already confirmed.');
                }

                // VAN one-cycle rule (audited 2026-10-01) — FIRST statement
                // after the order lock, BEFORE any plain read of this
                // transaction. The customer row is locked with the SAME
                // locking read that identifies the customer, so the guard's
                // (non-locking) determination afterwards runs on a read view
                // created AFTER the lock: a competing VAN confirmation that
                // committed while this request waited on that row is therefore
                // always visible, and the loser is refused with the blocking
                // document(s). For a VAN order this row IS the Phase 8 debtor
                // row (sold-to = debtor), so the lock order is unchanged — it
                // is still taken as a sink, and nothing below ever WAITS on a
                // lock while holding it (only this DRAFT's own rows, already
                // locked above, are written).
                $soldToCustomer = CustomerMaster::whereKey($order->sold_to_customer_id)
                    ->lockForUpdate()
                    ->first();

                if ($soldToCustomer !== null && $soldToCustomer->customer_type === CustomerType::VAN) {
                    $vanBlock = $this->vanCycleStatus(
                        $soldToCustomer->customer_id, $order->company_id, $order->sales_order_no, $order->currency,
                    );

                    if ($vanBlock !== null) {
                        $conflicts[] = $vanBlock['message'];

                        throw new ConfirmationConflict($conflicts);
                    }
                }

                // Product scope re-validation (employee_product; empty = all
                // company products): a scope removed after drafting becomes a
                // confirmation conflict, exactly like a revoked assignment.
                $scopedProducts = EmployeeProduct::where('employee_id', $order->sales_employee_id)->pluck('product_id');

                if ($scopedProducts->isNotEmpty()) {
                    $outOfScope = $order->items()->pluck('product_id')->diff($scopedProducts);

                    if ($outOfScope->isNotEmpty()) {
                        $conflicts[] = 'Product(s) '.$outOfScope->implode(', ')
                            .' are outside the sales employee\'s product scope. Restore the product scope or re-draft.';

                        throw new ConfirmationConflict($conflicts);
                    }
                }

                $soldTo = CustomerMaster::findOrFail($order->sold_to_customer_id);

                $companyId = $order->company_id;

                // Re-validate assignments/products against CURRENT server state.
                // Supplying eligibility is re-checked against the CURRENT
                // employee assignment: a Primary unassigned after drafting
                // becomes a confirmation conflict (never a silent confirm).
                $employee = EmployeeMaster::findOrFail($order->sales_employee_id);

                if (! $this->eligibleSupplyingIds($employee)->contains($order->supplying_customer_id)) {
                    $conflicts[] = 'Supplying Primary '.$order->supplying_customer_id
                        .' is no longer assigned to the sales employee. Restore the assignment or re-draft against an eligible Primary.';

                    throw new ConfirmationConflict($conflicts);
                }

                // Credit exposure (Phase 8, ruled): a sold-to debtor with
                // positive NET exposure (outstanding − available credit) may
                // not confirm another order. Available customer credit
                // offsets exposure; no configurable limit facility exists.
                //
                // Serialize with exposure CREATION: lock the debtor row the
                // same way allocation / START / invoice generation do. It is
                // the LAST lock this transaction takes (no other lock follows),
                // so the lock order stays acyclic.
                $this->invoices->lockDebtor($order->sold_to_customer_id);

                // current read (locking) — see InvoiceService::exposure().
                $exposure = $this->invoices->exposure($order->sold_to_customer_id, $companyId, true);

                if (Decimal::compare($exposure['net_exposure'], '0', 2) > 0) {
                    $conflicts[] = sprintf(
                        'Credit exposure: %s has a net exposure of %s %s (invoice outstanding %s, available credit %s). Settle existing invoices or apply customer credit before confirming another order.',
                        $order->sold_to_customer_id,
                        $exposure['net_exposure'],
                        $order->currency,
                        $exposure['outstanding'],
                        $exposure['available_credit'],
                    );

                    throw new ConfirmationConflict($conflicts);
                }

                [$supplyingCheck, $sourceCheck, $soldToCheck] = $this->validateCustomers(
                    $employee,
                    $order->supplying_customer_id,
                    $order->source_customer_id,
                    $order->sold_to_customer_id,
                );

                // Manual lines re-priced server-side; overrides preserved with reasons.
                $manualLines = $order->items()->where('line_source', LineSource::MANUAL)->get();

                foreach ($manualLines as $item) {
                    $resolution = $this->pricing->resolve(
                        $item->product,
                        $order->pricing_date ?? today(),
                        $soldToCheck->sales_region,
                    );

                    if ($resolution['ambiguous']) {
                        $conflicts[] = 'Pricing for '.$item->product->product_description.' is ambiguous: '
                            .count($resolution['candidates']).' conditions apply ('
                            .implode(', ', array_column($resolution['candidates'], 'condition_price_no'))
                            .'). Resolve master data before confirming.';
                    }

                    $item->recommended_price = $resolution['price'];

                    if ($item->price_overridden) {
                        // Override requires a reason (rule 8) — enforced at input.
                        $item->unit_price = $item->unit_price ?: $resolution['price'] ?? '0';
                    } else {
                        $item->unit_price = $resolution['price'] ?? '0';
                        $item->condition_price_no = $resolution['condition_price_no'];
                    }

                    $item->subtotal_amount = $this->lineSubtotal($item);
                }

                // Recompute deals on current data (rule 9).
                $order->items()->where('line_source', LineSource::DEAL)->delete();

                $cart = $manualLines->map(fn (SalesOrderItem $i) => [
                    'product_id' => $i->product_id,
                    'qty' => (string) $i->order_qty,
                    'unit' => (string) $i->order_unit,
                ]);

                $evaluation = $this->deals->evaluate($companyId, $cart, $order->pricing_date ?? today());

                if ($evaluation['ambiguous']) {
                    $conflicts[] = 'Multiple trade deals qualify ('.implode(', ', $evaluation['qualifying_deals'])
                        .') and no stacking rule is defined. Business decision required before confirming.';
                }

                $nextItemNo = (int) $manualLines->max('item_no') + 1;

                foreach ($evaluation['rewards'] as $reward) {
                    $parent = $manualLines->firstWhere('product_id', $reward['parent_product_id']);

                    SalesOrderItem::create([
                        'sales_order_no' => $order->sales_order_no,
                        'item_no' => $nextItemNo++,
                        'product_id' => $reward['product_id'],
                        'line_source' => LineSource::DEAL,
                        'is_free_item' => true,
                        'parent_item_no' => $parent?->item_no,
                        'order_qty' => $reward['reward_qty'],
                        'order_unit' => $reward['reward_unit'],
                        'unit_price' => '0',
                        'deal_no' => $reward['deal_no'],
                        'recommended_price' => null,
                        'subtotal_amount' => '0',
                    ]);
                }

                // Header totals from MANUAL + DEAL lines (free lines add 0).
                $order->gross_amount = Decimal::format((float) $order->items()->sum('subtotal_amount'));
                $order->discount_amount = Decimal::format((float) $order->items()->sum('discount_amount'));
                $order->tax_amount = Decimal::format((float) $order->items()->sum('tax_amount'));
                $order->net_amount = Decimal::sub(
                    Decimal::add((string) $order->gross_amount, (string) $order->tax_amount),
                    (string) $order->discount_amount,
                );

                // CONFIRMATION IS ALL-OR-NOTHING: any unresolved ambiguity aborts
                // the transaction — the order stays an untouched DRAFT.
                if ($conflicts !== []) {
                    throw new ConfirmationConflict($conflicts);
                }

                $order->order_status = OrderStatus::CONFIRMED;
                $order->confirmed_at = now();
                $order->save();
            }, attempts: 2); // current-read exposure check may surface MariaDB 1020 → retry
        } catch (ConfirmationConflict $e) {
            // Transaction rolled back: order remains an untouched DRAFT.
            return ['order' => $order->fresh(['items']), 'warnings' => $warnings, 'conflicts' => $e->conflicts];
        }

        return ['order' => $order->fresh(['items']), 'warnings' => $warnings, 'conflicts' => $conflicts];
    }

    /**
     * Reject a CONFIRMED item (rule 11): marks remaining demand rejected.
     * Original order_qty is preserved; existing delivery allocations are not
     * touched (Phase 5 continues from here).
     *
     * Reasons are CONTROLLED master data — free text is never accepted from a
     * user. `rejection_reason` keeps a readable snapshot of the reason name
     * (legacy history keeps its original text), while `rejection_reason_id`
     * links the authoritative lookup row.
     *
     * Dependent DEAL/free demand is closed AUTOMATICALLY: a free line can only
     * be fulfilled out of what its paid parent line actually earned, so once
     * the parent's remaining demand is terminally rejected every dependent
     * free line that is no longer backed (and that has committed nothing
     * physically) becomes non-fulfillable and is rejected with the
     * automation-only SYSTEM_DEFAULT reason. The employee never has to reject
     * each generated free line by hand, and no historical allocation, Goods
     * Issue or custody row is ever erased.
     */
    public function rejectItem(SalesOrderItem $item, SalesOrderRejectionReason $reason, EmployeeMaster $rejectedBy): SalesOrderItem
    {
        if ($item->salesOrder->order_status === OrderStatus::DRAFT) {
            abort(422, 'Draft items are edited, not rejected.');
        }

        if ($item->isRejected()) {
            abort(422, 'Item is already rejected.');
        }

        DB::transaction(function () use ($item, $reason, $rejectedBy) {
            $this->applyRejection($item, $reason, $rejectedBy);

            // Dependent free demand dies with the parent's remaining demand.
            foreach ($this->entitlements->closableFreeLines($item) as $free) {
                $this->applyRejection($free, SalesOrderRejectionReason::systemDefault(), $rejectedBy);
            }

            $this->refreshOrderStatus($item->salesOrder);

            // Billing boundary (Phase 8, RULED): a rejection can be the FINAL
            // disposition of a partially-shipped order — generate the single
            // final invoice here when every item is now terminally
            // dispositioned. Idempotent; uq_invoice_so backstops races.
            $order = SalesOrder::whereKey($item->sales_order_no)->lockForUpdate()->first();

            if ($order !== null) {
                $this->invoices->generateIfTerminal($order);
            }
        });

        return $item->fresh();
    }

    /**
     * Write one rejection — the ONLY place that touches the rejection audit
     * columns, so a user rejection and an automatic system closure are
     * recorded identically.
     */
    private function applyRejection(SalesOrderItem $item, SalesOrderRejectionReason $reason, EmployeeMaster $rejectedBy): void
    {
        $item->rejection_status = RejectionStatus::REJECTED;
        $item->rejection_reason_id = $reason->reason_id;
        $item->rejection_reason = $reason->reason_name;
        $item->rejected_by = $rejectedBy->employee_id;
        $item->rejected_at = now();
        $item->save();
    }

    /** Recompute header status from item rejection/delivery state. */
    public function refreshOrderStatus(SalesOrder $order): void
    {
        $order->refresh();
        $items = $order->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $allRejected = $items->every(fn (SalesOrderItem $i) => $i->isRejected());
        $someRejected = $items->contains(fn (SalesOrderItem $i) => $i->isRejected());

        $order->order_status = match (true) {
            $allRejected => OrderStatus::COMPLETELY_REJECTED,
            $someRejected => OrderStatus::PARTIALLY_REJECTED,
            $order->order_status === OrderStatus::DRAFT => OrderStatus::DRAFT,
            default => $order->order_status,
        };

        $order->save();
    }

    /**
     * Customer-relationship validation (server-side, rule 28):
     *  - employee must be assigned to the sold-to customer
     *  - supplying customer must be an active PRIMARY the employee is
     *    authorized to supply through (assigned via `customer_employee`)
     *  - source must be the supplying Primary or one of its SHIP_TOs
     *
     * @return array{0: CustomerMaster, 1: CustomerMaster, 2: CustomerMaster}
     */
    public function validateCustomers(
        EmployeeMaster $employee,
        string $supplyingCustomerId,
        string $sourceCustomerId,
        string $soldToCustomerId,
    ): array {
        $supplying = CustomerMaster::where('customer_id', $supplyingCustomerId)->first();
        $source = CustomerMaster::where('customer_id', $sourceCustomerId)->first();
        $soldTo = CustomerMaster::where('customer_id', $soldToCustomerId)->first();

        if ($supplying === null || ! $supplying->active || $supplying->customer_type !== CustomerType::PRIMARY) {
            abort(422, 'Supplying customer must be an active PRIMARY customer.');
        }

        // Eligibility: only Primaries assigned to this employee may supply.
        if (! $this->eligibleSupplyingIds($employee)->contains($supplying->customer_id)) {
            abort(403, 'You are not authorized to supply through this Primary. It must be assigned to you.');
        }

        if ($source === null || ! $source->active) {
            abort(422, 'Unknown or inactive source customer.');
        }

        // Source = the Primary itself, or a SHIP_TO whose parent is that Primary.
        $sourceIsValid = $source->customer_id === $supplying->customer_id
            || ($source->customer_type === CustomerType::SHIP_TO && $source->parent_customer_id === $supplying->customer_id);

        if (! $sourceIsValid) {
            abort(422, 'Source customer must be the supplying Primary or one of its SHIP_TO locations.');
        }

        if ($soldTo === null || ! $soldTo->active) {
            abort(422, 'Unknown or inactive sold-to customer.');
        }

        // The employee must be assigned to the customer whose demand is captured.
        $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->where('customer_id', $soldTo->customer_id)
            ->exists();

        if (! $assigned) {
            abort(403, 'You are not assigned to the sold-to customer.');
        }

        return [$supplying, $source, $soldTo];
    }

    /**
     * VAN one-cycle status (READ-ONLY, no locks): null when a new VAN cycle may
     * start, otherwise the blocking documents + the finance numbers.
     *
     * "Unfinished" is derived from EXISTING states only — no new status is
     * invented, and the derivation is the same `SalesLifecycleService` analysis
     * the orders screen uses (one source of truth):
     *
     *   - open demand (confirmed, not yet allocated)            → block
     *   - released allocation awaiting re-allocation             → block
     *   - allocated delivery not yet dispatched                  → block
     *   - dispatched quantity awaiting its POD outcome           → block
     *   - POD-accepted quantity whose invoice is not generated    → block
     *   - positive Phase 8 NET exposure                          → block
     *       (outstanding − available credit, so a fully settled invoice or
     *        sufficient customer credit never blocks)
     *
     * Terminal history never blocks: a COMPLETELY_REJECTED order with nothing
     * left to process, or a cycle whose outcomes are all recorded and whose
     * invoice is settled, classify as COMPLETED. DRAFT orders are ignored (not
     * demand). A POD difference that has moved into the implemented Phase 9
     * transit/custody workflow manufactures no VAN stock and is NOT a cycle
     * step: it is parallel accountability for the delivering employee
     * (`SalesLifecycleService::unfinishedOperationalReasons`).
     *
     * The company scope mirrors the finance exposure rule (customers are global
     * but the cycle belongs to the trading company).
     *
     * @return array{
     *     customer_id: string,
     *     message: string,
     *     blocking: array<int, array{sales_order_no: string, order_status: string, reasons: array<int, string>}>,
     *     exposure: array{outstanding: string, available_credit: string, net_exposure: string}
     * }|null
     */
    public function vanCycleStatus(string $soldToCustomerId, string $companyId, ?string $ignoreOrderNo = null, string $currency = 'NGN'): ?array
    {
        $customer = CustomerMaster::where('customer_id', $soldToCustomerId)->first();

        // VAN-only: an ordinary Secondary (or any other customer type) is
        // never restricted to one unresolved cycle.
        if ($customer === null || $customer->customer_type !== CustomerType::VAN) {
            return null;
        }

        $orders = SalesOrder::query()
            ->where('sold_to_customer_id', $customer->customer_id)
            ->where('company_id', $companyId)
            ->where('order_status', '!=', OrderStatus::DRAFT->value)
            ->when($ignoreOrderNo !== null, fn ($q) => $q->where('sales_order_no', '!=', $ignoreOrderNo))
            ->orderBy('sales_order_no')
            ->get();

        $analysis = $this->lifecycle->classifyOrders($orders);

        $blocking = [];

        foreach ($orders as $order) {
            $orderAnalysis = $analysis[$order->sales_order_no] ?? null;

            if ($orderAnalysis === null) {
                continue;
            }

            $reasons = $this->lifecycle->unfinishedOperationalReasons($orderAnalysis);

            if ($reasons !== []) {
                $blocking[] = [
                    'sales_order_no' => $order->sales_order_no,
                    'order_status' => $order->order_status->value,
                    'reasons' => $reasons,
                ];
            }
        }

        // Financial condition: the EXISTING Phase 8 net exposure — a settled
        // invoice or sufficient credit offsets the outstanding amount.
        $exposure = $this->invoices->exposure($customer->customer_id, $companyId, true);
        $hasExposure = Decimal::compare($exposure['net_exposure'], '0', 2) > 0;

        if ($blocking === [] && ! $hasExposure) {
            return null;
        }

        $message = 'This VAN still has an unfinished transaction. Complete delivery/POD and settle the outstanding balance before starting another order.';

        if ($blocking !== []) {
            $documents = array_map(
                fn (array $doc) => $doc['sales_order_no'].' ('.$doc['order_status'].': '.implode(', ', $doc['reasons']).')',
                array_slice($blocking, 0, 3),
            );

            $message .= ' Blocking: '.implode('; ', $documents).(count($blocking) > 3 ? ' …' : '').'.';
        }

        if ($hasExposure) {
            $message .= sprintf(
                ' Net exposure: %s %s (invoice outstanding %s, available credit %s).',
                $exposure['net_exposure'], $currency, $exposure['outstanding'], $exposure['available_credit'],
            );
        }

        return [
            'customer_id' => $customer->customer_id,
            'message' => $message,
            'blocking' => $blocking,
            'exposure' => $exposure,
        ];
    }

    /**
     * Enforce the VAN one-cycle rule for a NEW order. Must run INSIDE the
     * caller's transaction, as its LAST lock: the VAN's customer_master row is
     * locked FOR UPDATE (the same serialization point the Phase 8 exposure rule
     * uses — for a VAN order the sold-to IS the debtor), so two concurrent
     * attempts to start a new VAN cycle cannot both pass: the loser waits,
     * re-reads the winner's committed state through a read view created after
     * the lock, and is refused. Nothing is locked afterwards, so the row stays
     * a lock-order sink and can never take part in a deadlock cycle.
     */
    private function assertVanCycleClear(string $soldToCustomerId, string $companyId, ?string $ignoreOrderNo = null): void
    {
        if (! $this->isVanCustomer($soldToCustomerId)) {
            return;
        }

        $this->invoices->lockDebtor($soldToCustomerId);

        $block = $this->vanCycleStatus($soldToCustomerId, $companyId, $ignoreOrderNo);

        if ($block !== null) {
            abort(422, $block['message']);
        }
    }

    /** True when the customer is a VAN (the trigger of the one-cycle rule). */
    private function isVanCustomer(string $customerId): bool
    {
        return CustomerMaster::where('customer_id', $customerId)
            ->where('customer_type', CustomerType::VAN)
            ->exists();
    }

    /**
     * Replace manual lines of a draft; deal lines are (re-)evaluated on
     * confirmation, drafts show proposed deal lines as information.
     *
     * @param  Collection<int, array{product_id: string, qty: string, unit: string, unit_price?: ?string, price_override_reason?: ?string}>  $lines
     */
    private function replaceItems(SalesOrder $order, $lines, string $companyId, ?string $salesRegion): void
    {
        SalesOrderItem::where('sales_order_no', $order->sales_order_no)->delete();

        // Server-side product scope (employee_product; empty = all company
        // products). The capture UI filters products, but a spoofed/offline
        // payload must be rejected too — never trust the client.
        $scopedProducts = EmployeeProduct::where('employee_id', $order->sales_employee_id)->pluck('product_id');

        $itemNo = 1;

        foreach ($lines as $line) {
            if ($scopedProducts->isNotEmpty() && ! $scopedProducts->contains($line['product_id'])) {
                abort(403, "Product '{$line['product_id']}' is outside your product scope.");
            }

            $product = ProductMaster::where('product_id', $line['product_id'])->first();

            if ($product === null || $product->company_id !== $companyId) {
                abort(422, "Product '{$line['product_id']}' does not belong to the order's company.");
            }

            if (! $product->active) {
                abort(422, "Product '{$line['product_id']}' is inactive.");
            }

            $unit = UnitMaster::where('unit_code', $line['unit'])->first();

            if ($unit === null) {
                abort(422, "Unknown unit '{$line['unit']}'.");
            }

            if ((float) $line['qty'] <= 0) {
                abort(422, 'Order quantity must be greater than zero.');
            }

            // Unit conversion must exist for internal calculations (basic qty).
            try {
                $this->units->toBasic($product, (string) $line['qty'], (string) $line['unit']);
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }

            $resolution = $this->pricing->resolve($product, $order->pricing_date ?? today(), $salesRegion);

            if ($resolution['ambiguous']) {
                // Drafts tolerate ambiguity (advisory), confirmation blocks.
                $recommended = null;
            } else {
                $recommended = $resolution['price'];
            }

            $overrideRequested = isset($line['unit_price']) && $line['unit_price'] !== null && $line['unit_price'] !== '';
            $reason = $line['price_override_reason'] ?? null;

            if ($overrideRequested && trim((string) $reason) === '') {
                abort(422, 'A price override requires a reason.');
            }

            $unitPrice = $overrideRequested ? (string) $line['unit_price'] : ($recommended ?? '0');

            $item = new SalesOrderItem([
                'sales_order_no' => $order->sales_order_no,
                'item_no' => $itemNo++,
                'product_id' => $product->product_id,
                'line_source' => LineSource::MANUAL,
                'is_free_item' => false,
                'order_qty' => (string) $line['qty'],
                'order_unit' => (string) $line['unit'],
                'recommended_price' => $recommended,
                'unit_price' => $unitPrice,
                'price_overridden' => $overrideRequested,
                'price_override_reason' => $overrideRequested ? $reason : null,
                'condition_price_no' => $resolution['condition_price_no'],
            ]);

            $item->subtotal_amount = $this->lineSubtotal($item);
            $item->save();
        }

        $order->gross_amount = (string) $order->items()->sum('subtotal_amount');
        $order->discount_amount = (string) $order->items()->sum('discount_amount');
        $order->tax_amount = (string) $order->items()->sum('tax_amount');
        $order->net_amount = Decimal::sub(
            Decimal::add((string) $order->gross_amount, (string) $order->tax_amount),
            (string) $order->discount_amount,
        );
        $order->save();
    }

    private function lineSubtotal(SalesOrderItem $item): string
    {
        // subtotal = basic-qty-in-order-unit × unit price − discount + tax
        $gross = Decimal::mul((string) $item->order_qty, (string) $item->unit_price);

        return Decimal::sub($gross, (string) $item->discount_amount);
    }

    /** Company-prefixed document number: SO-EMANL-2026-00001 (transaction-guarded). */
    private function nextNumber(string $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "SO-$companyId-$year-";

        $max = SalesOrder::where('sales_order_no', 'like', $prefix.'%')
            ->max('sales_order_no');

        $seq = $max !== null ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
