<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\RejectionStatus;
use App\Enums\ShipmentStatus;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\ShipmentDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — Delivery = HARD STOCK ALLOCATION against confirmed SO demand.
 *
 * Invariants (docs/ARCHITECTURE.md "Delivery = allocation"):
 *  - SO = demand, Delivery = allocation, Shipment = physical issue.
 *  - Allocation moves unrestricted → restricted inside a locked transaction;
 *    ON HAND (unrestricted + restricted) never changes; no inventory_movement.
 *  - All-or-nothing per Delivery; inventory rows are locked with
 *    SELECT ... FOR UPDATE; quantities are decimal-safe in BASIC units.
 *  - Cumulative allocations never exceed confirmed demand; rejected items
 *    take no NEW allocation but keep their existing allocations.
 *  - Source compatibility: allocation uses ONLY the SO's source customer
 *    inventory (Primary or its SHIP_TO); no silent fallback either way.
 *  - Idempotency: a retried allocation request replays the original result
 *    instead of double-allocating (controller-level, SyncService architecture).
 *  - Release: an ALLOCATED delivery can be released back to DRAFT before
 *    physical issue — restricted → unrestricted, ON HAND unchanged, no
 *    movement, demand freed for re-allocation. Never after goods issue.
 */
class DeliveryService
{
    public function __construct(
        private readonly ProductUnitService $units,
        private readonly TransitService $transit,
    ) {}

    /** Company-prefixed delivery number: DEL-EMANL-2026-00001. */
    public function nextNumber(string $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "DEL-$companyId-$year-";

        $max = Delivery::where('delivery_no', 'like', $prefix.'%')->max('delivery_no');

        $seq = $max !== null ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /** Convert an SO item's quantity (in its own business unit) to basic units. */
    public function toBasicForItem(SalesOrderItem $item, string $qty, string $unit): string
    {
        return $this->units->toBasicById($item->product_id, $qty, $unit);
    }

    /**
     * Already-allocated quantity for an SO item, in BASIC units.
     * DRAFT deliveries hold no stock (not yet allocated / released), so
     * their lines do not count against demand — this is what makes a
     * released allocation available for re-allocation.
     */
    public function allocatedBasicQty(SalesOrderItem $item): string
    {
        $sum = DeliveryItem::where('sales_order_no', $item->sales_order_no)
            ->where('sales_order_item_no', $item->item_no)
            ->whereHas('delivery', fn ($q) => $q->where('delivery_status', '!=', DeliveryStatus::DRAFT->value))
            ->get()
            ->sum(fn (DeliveryItem $d) => (float) $this->toBasicForItem($item, (string) $d->allocated_qty, (string) $d->delivery_unit));

        return Decimal::format((float) $sum, 3);
    }

    /**
     * Remaining fulfillable demand for an SO item, in BASIC units:
     * confirmed order qty − allocated qty, and 0 for a REJECTED item
     * (existing allocations survive; the remainder is rejected demand).
     * The original order_qty is never mutated.
     */
    public function remainingBasicQty(SalesOrderItem $item): string
    {
        $orderedBasic = $this->toBasicForItem($item, (string) $item->order_qty, (string) $item->order_unit);
        $open = Decimal::sub($orderedBasic, $this->allocatedBasicQty($item), 3);

        if ($item->rejection_status === RejectionStatus::REJECTED) {
            return '0.000';
        }

        return $open;
    }

    /**
     * Allocation preview per SO item: ordered / allocated / remaining / stock
     * position at the SO source. Drives the Create-Delivery screen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function allocationContext(SalesOrder $order): Collection
    {
        $order->load(['items.product', 'items.orderUnit']);

        $stockHolder = CustomerMaster::findOrFail($order->source_customer_id);

        return $order->items->map(function (SalesOrderItem $item) use ($stockHolder) {
            $inventory = Inventory::where('customer_id', $stockHolder->customer_id)
                ->where('product_id', $item->product_id)
                ->first();

            $orderedBasic = $this->toBasicForItem($item, (string) $item->order_qty, (string) $item->order_unit);
            $allocatedBasic = $this->allocatedBasicQty($item);
            $remaining = $this->remainingBasicQty($item);

            return [
                'item_no' => $item->item_no,
                'product_id' => $item->product_id,
                'product' => $item->product->product_description,
                'is_free_item' => $item->is_free_item,
                'rejection_status' => $item->rejection_status->value,
                'order_qty' => (string) $item->order_qty,
                'order_unit' => (string) $item->order_unit,
                'ordered_basic' => $orderedBasic,
                'allocated_basic' => $allocatedBasic,
                'remaining_basic' => $remaining,
                'rejected_basic' => $item->rejection_status === RejectionStatus::REJECTED
                    ? Decimal::sub($orderedBasic, $allocatedBasic, 3)
                    : '0.000',
                'unrestricted_qty' => $inventory?->unrestricted_qty ?? '0.000',
                'restricted_qty' => $inventory?->restricted_qty ?? '0.000',
                'on_hand_qty' => $inventory?->onHandQty() ?? '0.000',
                'basic_unit' => $inventory->basic_unit ?? $item->product->basic_unit,
            ];
        });
    }

    /**
     * Create + allocate a Delivery in ONE all-or-nothing transaction.
     * The Delivery source is ALWAYS the SO's source customer (validated
     * server-side); lines reference SO item numbers, quantities carry the
     * user's business unit and are converted to basic units for stock math.
     *
     * @param  Collection<int, array{sales_order_item_no: int|string, qty: string, unit: string}>  $lines
     * @return array{delivery: Delivery, warnings: array<int, string>}
     */
    public function createAndAllocate(
        SalesOrder $order,
        EmployeeMaster $employee,
        $lines,
    ): array {
        if ($order->order_status === OrderStatus::DRAFT || ! $order->order_status->isConfirmed()) {
            abort(422, 'Deliveries can only be allocated against a CONFIRMED order.');
        }

        // Source compatibility (never trusted from the client): the Delivery
        // allocates from EXACTLY the SO's source customer — the Primary itself
        // or its SHIP_TO. No fallback between them, ever.
        $stockHolder = CustomerMaster::findOrFail($order->source_customer_id);

        $validHolder = in_array($stockHolder->customer_type->value, [
            CustomerType::PRIMARY->value,
            CustomerType::SHIP_TO->value,
            CustomerType::VAN->value,
        ], true);

        if (! $stockHolder->active || ! $validHolder) {
            abort(422, 'The order source is not a valid stock holder (PRIMARY, SHIP_TO or VAN).');
        }

        $prepared = $this->prepareLines($order, $lines);

        $delivery = null;

        DB::transaction(function () use (&$delivery, $order, $employee, $stockHolder, $prepared) {
            // Demand anchor for mixed stock+transit allocation (Phase 9):
            // every allocation serializes on the SO row, so concurrent stock,
            // transit or mixed allocations can never exceed remaining demand.
            SalesOrder::whereKey($order->sales_order_no)->lockForUpdate()->first();

            $delivery = Delivery::create([
                'delivery_no' => $this->nextNumber($order->company_id),
                'company_id' => $order->company_id,
                'sales_order_no' => $order->sales_order_no,
                'source_customer_id' => $stockHolder->customer_id,
                'customer_id' => $order->sold_to_customer_id,
                'delivery_status' => DeliveryStatus::DRAFT,
                'created_by' => $employee->employee_id,
            ]);

            foreach ($prepared->values() as $index => $line) {
                $this->allocateLine($delivery, $order, $stockHolder, $line, $index + 1, $employee);
            }

            $delivery->delivery_status = DeliveryStatus::ALLOCATED;
            $delivery->allocated_at = now();
            $delivery->save();

            $this->refreshOrderFulfillmentStatus($order);
        });

        return ['delivery' => $delivery->fresh(['items']), 'warnings' => []];
    }

    /**
     * Release an ALLOCATED delivery: restricted stock returns to
     * unrestricted at the delivery's source customer, the delivery falls
     * back to DRAFT (no allocation) and the SO fulfillment status is
     * recomputed — released demand becomes allocatable again.
     *
     * This is a stock-STATE transfer, like allocation: ON HAND never
     * changes and NO inventory_movement is written. It is only possible
     * BEFORE physical issue — a shipped delivery can never be released.
     *
     * Shipment interaction: a delivery queued in a DRAFT shipment (mere
     * planning) is AUTO-DETACHED inside the same transaction; attachment to
     * a READY/IN_TRANSIT/COMPLETED shipment forbids release — the shipment
     * must first be deliberately moved back to DRAFT (before START).
     *
     * Concurrency: the delivery row AND every affected inventory row are
     * locked FOR UPDATE (rows inside the transaction, deterministic
     * product_id order), so a release can never race a Shipment START into
     * double-spend or a stuck restricted balance.
     */
    public function releaseDelivery(Delivery $delivery): Delivery
    {
        if ($delivery->delivery_status !== DeliveryStatus::ALLOCATED) {
            abort(422, 'Only ALLOCATED deliveries can be released.');
        }

        $order = SalesOrder::findOrFail($delivery->sales_order_no);
        $stockHolder = CustomerMaster::findOrFail($delivery->source_customer_id);

        DB::transaction(function () use ($delivery, $order, $stockHolder) {
            // Lock the delivery itself; state may have changed since load.
            $locked = Delivery::whereKey($delivery->delivery_no)->lockForUpdate()->first();

            if ($locked === null || $locked->delivery_status !== DeliveryStatus::ALLOCATED) {
                abort(422, 'Only ALLOCATED deliveries can be released.');
            }

            // Inspect the shipment attachment UNDER LOCK. A DRAFT shipment is
            // mere planning: the delivery is AUTO-DETACHED as part of the
            // release (a released delivery no longer represents reserved
            // stock, so it must not stay queued). READY/IN_TRANSIT/COMPLETED
            // mean the dispatch plan is finalized or the goods physically
            // issued: release is rejected — the shipment must first be
            // deliberately moved back to DRAFT through the shipment workflow.
            $attachment = ShipmentDelivery::where('delivery_no', $delivery->delivery_no)->first();
            $attachedStatus = $attachment?->shipment()->value('shipment_status'); // ShipmentStatus cast

            if ($attachedStatus !== null && $attachedStatus !== ShipmentStatus::DRAFT) {
                abort(422, 'This delivery is queued in a '.$attachedStatus->value.' shipment and can no longer be released. Move the shipment back to DRAFT first.');
            }

            if ($locked->shipped_at !== null) {
                abort(422, 'This delivery has been physically issued and can no longer be released.');
            }

            // Convert each item to basic units and lock its inventory row
            // INSIDE the transaction, in deterministic (product_id) order.
            $lines = $locked->items()->with('product')->get()
                ->map(function (DeliveryItem $item) {
                    try {
                        $basicQty = $this->units->toBasicById(
                            $item->product_id,
                            (string) $item->allocated_qty,
                            (string) $item->delivery_unit,
                        );
                    } catch (\InvalidArgumentException $e) {
                        abort(422, $e->getMessage());
                    }

                    // Restore the STOCK PORTION only (Phase 9 ruling 3): the
                    // transit portion is handled by releaseForDelivery below.
                    $stockQty = $this->transit->portionsBasic($item, $basicQty)['stock_basic'];

                    return ['product_id' => $item->product_id, 'basic_qty' => $stockQty];
                })
                ->filter(fn ($line) => Decimal::compare($line['basic_qty'], '0', 3) > 0)
                ->sortBy('product_id')
                ->values();

            foreach ($lines as $line) {
                $inventory = Inventory::where('customer_id', $stockHolder->customer_id)
                    ->where('product_id', $line['product_id'])
                    ->lockForUpdate()
                    ->first();

                if ($inventory === null) {
                    abort(422, 'Missing inventory record for an item of this delivery.');
                }

                $newRestricted = Decimal::sub((string) $inventory->restricted_qty, $line['basic_qty'], 3);
                $newUnrestricted = Decimal::add((string) $inventory->unrestricted_qty, $line['basic_qty'], 3);

                if (Decimal::compare($newRestricted, '0', 3) < 0 || Decimal::compare($newUnrestricted, '0', 3) < 0) {
                    abort(422, 'Inventory invariant violated — release rolled back.');
                }

                $inventory->restricted_qty = $newRestricted;
                $inventory->unrestricted_qty = $newUnrestricted;
                $inventory->save();
            }

            // Phase 9 (ruling 3): restore any transit reservations too —
            // allocation is reversible until Shipment START. Each child row
            // becomes a standalone REUSABLE balance; allocation history is
            // kept (RELEASED), never deleted.
            $this->transit->releaseForDelivery($delivery->delivery_no);

            // Auto-detach from a DRAFT shipment INSIDE the same transaction —
            // the detachment rolls back if anything above fails.
            ShipmentDelivery::where('delivery_no', $delivery->delivery_no)->delete();

            $locked->delivery_status = DeliveryStatus::DRAFT;
            $locked->allocated_at = null;
            $locked->save();

            $this->refreshOrderFulfillmentStatus($order);
        });

        return $delivery->fresh(['items']);
    }

    /**
     * Allocate an existing DRAFT delivery (a released one): same checks and
     * stock effects as createAndAllocate, but re-uses the delivery document
     * and its number. All-or-nothing.
     *
     * @param  Collection<int, array{sales_order_item_no: int|string, qty: string, unit: string}>  $lines
     * @return array{delivery: Delivery, warnings: array<int, string>}
     */
    public function reallocateDraft(Delivery $delivery, SalesOrder $order, EmployeeMaster $employee, $lines): array
    {
        if ($delivery->delivery_status !== DeliveryStatus::DRAFT) {
            abort(422, 'Only DRAFT deliveries can be (re-)allocated.');
        }

        $stockHolder = CustomerMaster::findOrFail($delivery->source_customer_id);

        $prepared = $this->prepareLines($order, $lines);

        DB::transaction(function () use ($delivery, $order, $stockHolder, $prepared, $employee) {
            // Demand anchor for mixed stock+transit allocation (Phase 9).
            SalesOrder::whereKey($order->sales_order_no)->lockForUpdate()->first();

            $locked = Delivery::whereKey($delivery->delivery_no)->lockForUpdate()->first();

            if ($locked === null || $locked->delivery_status !== DeliveryStatus::DRAFT) {
                abort(422, 'Only DRAFT deliveries can be (re-)allocated.');
            }

            // Re-allocation REPLACES the (released) draft lines wholesale.
            // Transit reservations from any released allocation were already
            // released back to the transit pool by releaseDelivery().
            DeliveryItem::where('delivery_no', $delivery->delivery_no)->delete();

            foreach ($prepared->values() as $index => $line) {
                $this->allocateLine($delivery, $order, $stockHolder, $line, $index + 1, $employee);
            }

            $delivery->delivery_status = DeliveryStatus::ALLOCATED;
            $delivery->allocated_at = now();
            $delivery->save();

            $this->refreshOrderFulfillmentStatus($order);
        });

        return ['delivery' => $delivery->fresh(['items']), 'warnings' => []];
    }

    /**
     * Validate + convert delivery lines BEFORE the transaction: SO item
     * ownership, product lookup, unit conversion to basic units.
     *
     * @param  Collection<int, array{sales_order_item_no: int|string, qty: string, unit: string}>  $lines
     * @return Collection<int, array{item: SalesOrderItem, product: ProductMaster, requested_qty: string, requested_unit: string, basic_qty: string}>
     */
    private function prepareLines(SalesOrder $order, $lines): Collection
    {
        $prepared = collect($lines)
            ->filter(fn ($l) => (float) $l['qty'] > 0)
            ->map(function ($line) use ($order) {
                $item = $order->items()->where('item_no', (int) $line['sales_order_item_no'])->first();

                if ($item === null) {
                    abort(422, "Sales order item {$line['sales_order_item_no']} does not belong to this order.");
                }

                $product = ProductMaster::findOrFail($item->product_id);

                try {
                    $basicQty = $this->units->toBasic($product, (string) $line['qty'], (string) $line['unit']);
                } catch (\InvalidArgumentException $e) {
                    abort(422, $e->getMessage());
                }

                // Optional transit portion (Phase 9): basic-converted and
                // capped at the requested quantity; the remainder is stock.
                $transitQty = (string) ($line['transit_qty'] ?? '0');

                if (trim($transitQty) === '') {
                    $transitQty = '0';
                }

                if ((float) $transitQty < 0) {
                    abort(422, 'The transit portion cannot be negative.');
                }

                try {
                    $transitBasic = $transitQty === '0'
                        ? '0.000'
                        : $this->units->toBasic($product, $transitQty, (string) $line['unit']);
                } catch (\InvalidArgumentException $e) {
                    abort(422, $e->getMessage());
                }

                if (Decimal::compare($transitBasic, $basicQty, 3) > 0) {
                    abort(422, 'The transit portion cannot exceed the requested quantity for '.$product->product_description.'.');
                }

                return [
                    'item' => $item,
                    'product' => $product,
                    'requested_qty' => (string) $line['qty'],
                    'requested_unit' => (string) $line['unit'],
                    'basic_qty' => $basicQty,
                    'transit_basic' => $transitBasic,
                ];
            });

        if ($prepared->isEmpty()) {
            abort(422, 'A delivery needs at least one line with a quantity.');
        }

        return $prepared;
    }

    /**
     * Authoritative single-line allocation. Runs INSIDE the delivery
     * transaction: locks the inventory row FOR UPDATE (customer + product
     * scope ONLY), validates remaining demand and unrestricted stock under
     * the lock, then transfers unrestricted → restricted.
     */
    private function allocateLine(Delivery $delivery, SalesOrder $order, CustomerMaster $stockHolder, array $line, int $itemNo, ?EmployeeMaster $employee = null): void
    {
        $item = $line['item'];
        $product = $line['product'];
        $transitBasic = (string) ($line['transit_basic'] ?? '0.000');
        $stockBasic = Decimal::sub($line['basic_qty'], $transitBasic, 3);

        // Item eligibility: rejected items take no NEW allocation.
        if ($item->rejection_status === RejectionStatus::REJECTED) {
            abort(422, $product->product_description.' was rejected — no new allocation is allowed. Existing allocations are unaffected.');
        }

        // Demand is checked ONCE for the FULL requested quantity — the
        // combined stock + transit portions (Phase 9 ruling 1).
        $remainingBasic = $this->remainingBasicQty($item);

        if (Decimal::compare($line['basic_qty'], $remainingBasic, 3) > 0) {
            abort(422, 'Requested '.Decimal::trimZeros($line['basic_qty']).' '.$product->basic_unit
                .' exceeds the remaining demand of '.Decimal::trimZeros($remainingBasic).' '.$product->basic_unit
                .' for '.$product->product_description.'.');
        }

        // STOCK portion: lock the authoritative inventory row (customer +
        // product scope ONLY). A pure-transit line never touches inventory.
        $inventory = null;

        if (Decimal::compare($stockBasic, '0', 3) > 0) {
            $inventory = Inventory::where('customer_id', $stockHolder->customer_id)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                abort(422, 'No inventory record for '.$product->product_description.' at the source. Receive stock first.');
            }

            // Sufficient unrestricted stock — authoritative check under the lock.
            if (Decimal::compare($stockBasic, (string) $inventory->unrestricted_qty, 3) > 0) {
                abort(422, 'Insufficient unrestricted stock for '.$product->product_description
                    .': requested '.Decimal::trimZeros($stockBasic).' '.$inventory->basic_unit
                    .', available '.Decimal::trimZeros((string) $inventory->unrestricted_qty).' '.$inventory->basic_unit
                    .'. Reduce the quantity — allocation never silently shrinks demand.');
            }
        }

        // Transfer unrestricted → restricted for the STOCK portion only
        // (ON HAND unchanged). Guarded so no quantity can ever go negative,
        // even under racing transactions.
        if ($inventory !== null) {
            $newUnrestricted = Decimal::sub((string) $inventory->unrestricted_qty, $stockBasic, 3);
            $newRestricted = Decimal::add((string) $inventory->restricted_qty, $stockBasic, 3);

            if (Decimal::compare($newUnrestricted, '0', 3) < 0 || Decimal::compare($newRestricted, '0', 3) < 0) {
                abort(422, 'Inventory invariant violated — allocation rolled back.');
            }

            $inventory->unrestricted_qty = $newUnrestricted;
            $inventory->restricted_qty = $newRestricted;
            $inventory->save();
        }

        // Delivery item in the USER's business-facing qty/unit (never the
        // silently-converted basic quantity). The FULL requested quantity is
        // stored — the stock/transit split lives in the portion derivation.
        $deliveryItem = DeliveryItem::create([
            'delivery_no' => $delivery->delivery_no,
            'item_no' => $itemNo,
            'sales_order_no' => $order->sales_order_no,
            'sales_order_item_no' => $item->item_no,
            'product_id' => $product->product_id,
            'is_free_item' => $item->is_free_item,
            'allocated_qty' => $line['requested_qty'],
            'delivery_unit' => $line['requested_unit'],
        ]);

        // TRANSIT portion: reserve reusable transit (FIFO, dedicated child
        // rows) — reversible until Shipment START finalizes it (ruling 3).
        if (Decimal::compare($transitBasic, '0', 3) > 0) {
            if ($employee === null) {
                abort(422, 'A transit allocation requires the allocating employee.');
            }

            $this->transit->reserveForDeliveryItem($employee, $deliveryItem, $transitBasic);
        }
    }

    /**
     * SO fulfillment status from ALLOCATION state (Phase 5):
     *   CONFIRMED        — nothing allocated yet
     *   OPEN_DELIVERY    — any allocation exists (partially OR fully allocated)
     *   PARTIALLY/COMPLETELY_REJECTED — Phase 4 semantics, preserved
     *
     * PARTIALLY_DELIVERED / COMPLETELY_DELIVERED are intentionally NOT set
     * here: goods are not delivered until Shipment/POD confirms physical
     * delivery (Phase 6). The enum cannot express "fully allocated, awaiting
     * shipment" distinctly, so OPEN_DELIVERY carries that meaning for now —
     * a documented ambiguity, not misleading "delivered" semantics.
     */
    public function refreshOrderFulfillmentStatus(SalesOrder $order): void
    {
        $order->refresh();
        $order->load('items');

        $items = $order->items;

        if ($items->isEmpty() || $order->order_status === OrderStatus::DRAFT) {
            return;
        }

        $allRejected = $items->every(fn (SalesOrderItem $i) => $i->isRejected());
        $anyRejected = $items->contains(fn (SalesOrderItem $i) => $i->isRejected());

        if ($allRejected) {
            $order->order_status = OrderStatus::COMPLETELY_REJECTED;
        } elseif ($anyRejected) {
            $order->order_status = OrderStatus::PARTIALLY_REJECTED;
        } else {
            $anyAllocated = $items->contains(
                fn (SalesOrderItem $i) => Decimal::compare($this->allocatedBasicQty($i), '0', 3) > 0,
            );

            $order->order_status = $anyAllocated ? OrderStatus::OPEN_DELIVERY : OrderStatus::CONFIRMED;
        }

        $order->save();
    }
}
