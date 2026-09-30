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
 */
class SalesOrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly DealService $deals,
        private readonly ProductUnitService $units,
        private readonly InvoiceService $invoices,
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
                $order = SalesOrder::whereKey($order->sales_order_no)->lockForUpdate()->firstOrFail();

                if ($order->order_status !== OrderStatus::DRAFT) {
                    abort(422, 'Order was already confirmed.');
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
                $exposure = $this->invoices->exposure($order->sold_to_customer_id, $companyId);

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
            });
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
     */
    public function rejectItem(SalesOrderItem $item, string $reason, EmployeeMaster $rejectedBy): SalesOrderItem
    {
        if ($item->salesOrder->order_status === OrderStatus::DRAFT) {
            abort(422, 'Draft items are edited, not rejected.');
        }

        if ($item->isRejected()) {
            abort(422, 'Item is already rejected.');
        }

        DB::transaction(function () use ($item, $reason, $rejectedBy) {
            $item->rejection_status = RejectionStatus::REJECTED;
            $item->rejection_reason = $reason;
            $item->rejected_by = $rejectedBy->employee_id;
            $item->rejected_at = now();
            $item->save();

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
