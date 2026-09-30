<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\MovementType;
use App\Enums\ShipmentStatus;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Shipment;
use App\Models\ShipmentDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — Shipment = physical dispatch; START = irreversible Goods Issue.
 *
 * Boundaries (docs/PHASE6_DESIGN.md, approved):
 *  - DRAFT ↔ READY is planning: NO inventory effect, NO movement.
 *  - START (READY → IN_TRANSIT) is THE physical boundary: for every attached
 *    Delivery Item, restricted_qty is decremented (grouped per source+product
 *    under lock) and one immutable negative GOODS_ISSUE movement is written
 *    per Delivery Item (reference_type=DELIVERY, reference_no=delivery_no,
 *    reference_item_no=item_no). Unrestricted is never touched. ON HAND drops.
 *  - Deliveries ALLOCATED → SHIPPED (+shipped_at); Shipment → IN_TRANSIT
 *    (+started_on). COMPLETED and POD semantics are out of scope.
 *  - Deliveries may come from different SOs/salespeople/destinations — the
 *    only hard constraints are same company + same source + ALLOCATED +
 *    unshipped + not queued elsewhere + still holding restricted stock.
 *  - Authorization is company/source operational scope, never creator==owner.
 */
class ShipmentService
{
    public function __construct(
        private readonly TransitService $transit,
        private readonly InvoiceService $invoices,
    ) {}

    /** Company-prefixed shipment number: SHP-EMANL-2026-00001. */
    public function nextNumber(string $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "SHP-$companyId-$year-";

        $max = Shipment::where('shipment_no', 'like', $prefix.'%')->max('shipment_no');

        $seq = $max !== null ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /** Create a DRAFT shipment for a valid stock-holder source. */
    public function createShipment(EmployeeMaster $actor, string $companyId, string $sourceCustomerId, array $meta = []): Shipment
    {
        $source = CustomerMaster::findOrFail($sourceCustomerId);

        if (! InventoryService::isValidStockHolder($source) || ! $source->active) {
            abort(422, 'Shipment source must be an active stock holder (PRIMARY, SHIP_TO or VAN).');
        }

        return Shipment::create([
            'shipment_no' => $this->nextNumber($companyId),
            'company_id' => $companyId,
            'source_customer_id' => $source->customer_id,
            'shipment_status' => ShipmentStatus::DRAFT,
            'created_by' => $actor->employee_id,
            'carrier_employee_id' => $meta['carrier_employee_id'] ?? null,
            'vehicle_reference' => $meta['vehicle_reference'] ?? null,
            'remarks' => $meta['remarks'] ?? null,
        ]);
    }

    /** Attach an eligible ALLOCATED delivery to a DRAFT shipment. */
    public function attachDelivery(Shipment $shipment, string $deliveryNo): void
    {
        $result = DB::transaction(function () use ($shipment, $deliveryNo) {
            // Check the LOCKED row — a caller's in-memory model may be stale.
            $lockedShipment = Shipment::whereKey($shipment->shipment_no)->lockForUpdate()->first();
            $this->assertMutable($lockedShipment);

            $delivery = Delivery::whereKey($deliveryNo)->lockForUpdate()->first();

            if ($delivery === null) {
                abort(422, "Delivery {$deliveryNo} does not exist.");
            }

            $this->assertAttachable($lockedShipment, $delivery);

            // A delivery queued in another (DRAFT) shipment is still hidden
            // from candidates; the unique index is the last line of defense.
            ShipmentDelivery::create([
                'shipment_no' => $lockedShipment->shipment_no,
                'delivery_no' => $delivery->delivery_no,
            ]);

            return $delivery;
        });

        unset($result);
    }

    /** Detach a delivery from a DRAFT shipment. */
    public function detachDelivery(Shipment $shipment, string $deliveryNo): void
    {
        DB::transaction(function () use ($shipment, $deliveryNo) {
            $lockedShipment = Shipment::whereKey($shipment->shipment_no)->lockForUpdate()->first();
            $this->assertMutable($lockedShipment);

            ShipmentDelivery::where('shipment_no', $lockedShipment->shipment_no)
                ->where('delivery_no', $deliveryNo)
                ->delete();
        });
    }

    /** DRAFT → READY: locks composition. Planning only — no inventory effect. */
    public function markReady(Shipment $shipment): Shipment
    {
        return DB::transaction(function () use ($shipment) {
            $locked = Shipment::whereKey($shipment->shipment_no)->lockForUpdate()->first();

            if ($locked === null || $locked->shipment_status !== ShipmentStatus::DRAFT) {
                abort(422, 'Only DRAFT shipments can be marked READY.');
            }

            $deliveries = $this->lockedAttachedDeliveries($locked);

            if ($deliveries->isEmpty()) {
                abort(422, 'A shipment needs at least one attached delivery before READY.');
            }

            foreach ($deliveries as $delivery) {
                $this->assertAttachable($locked, $delivery);
            }

            $locked->shipment_status = ShipmentStatus::READY;
            $locked->ready_on = now();
            $locked->save();

            return $locked;
        });
    }

    /** READY → DRAFT: operational correction before START. No inventory effect. */
    public function backToDraft(Shipment $shipment): Shipment
    {
        return DB::transaction(function () use ($shipment) {
            $locked = Shipment::whereKey($shipment->shipment_no)->lockForUpdate()->first();

            if ($locked === null || $locked->shipment_status !== ShipmentStatus::READY) {
                abort(422, 'Only READY shipments can return to DRAFT.');
            }

            $locked->shipment_status = ShipmentStatus::DRAFT;
            $locked->ready_on = null;
            $locked->save();

            return $locked;
        });
    }

    /**
     * THE physical boundary: READY → IN_TRANSIT with Goods Issue.
     *
     * @return array{shipment: Shipment, issued: int, movements: int}
     */
    public function start(EmployeeMaster $actor, Shipment $shipment): array
    {
        // Laravel natively retries DeadlockException (deadlock 1213/40001,
        // MariaDB 1020 "record has changed", lock-wait timeout) with a full
        // rollback between attempts. Every state is re-read and re-validated
        // inside the transaction, so a retry is always safe; the second
        // attempt either serializes cleanly or fails an honest check.
        return DB::transaction(fn () => $this->executeStart($actor, $shipment), attempts: 2);
    }

    /** @return array{shipment: Shipment, issued: int, movements: int} */
    private function executeStart(EmployeeMaster $actor, Shipment $shipment): array
    {
        /** @var array{shipment: Shipment, issued: int, movements: int}|null $result */
        $result = null;

        DB::transaction(function () use (&$result, $actor, $shipment) {
            // 1–2. Lock the shipment; only READY may START.
            $locked = Shipment::whereKey($shipment->shipment_no)->lockForUpdate()->first();

            if ($locked === null) {
                abort(404, 'Shipment not found.');
            }

            if ($locked->shipment_status !== ShipmentStatus::READY) {
                abort(422, 'Only READY shipments can be started.');
            }

            // 3–4. Lock attached deliveries in deterministic delivery_no order.
            $deliveries = $this->lockedAttachedDeliveries($locked)->sortBy('delivery_no')->values();

            if ($deliveries->isEmpty()) {
                abort(422, 'This shipment has no attached deliveries.');
            }

            $sourceId = $locked->source_customer_id;

            foreach ($deliveries as $delivery) {
                if ($delivery->delivery_status !== DeliveryStatus::ALLOCATED || $delivery->shipped_at !== null) {
                    abort(422, $delivery->delivery_no.' is not an ALLOCATED delivery anymore — refresh and re-check the shipment.');
                }

                // Re-validate company/source compatibility under lock.
                if ($delivery->company_id !== $locked->company_id || $delivery->source_customer_id !== $sourceId) {
                    abort(422, $delivery->delivery_no.' does not match the shipment company/source.');
                }
            }

            // 5. Collect items with per-item basic quantities AND their
            // stock/transit portions (Phase 9 ruling 1 — THE centralized
            // derivation in TransitService; ShipmentService never
            // re-implements the formula).
            $items = DeliveryItem::whereIn('delivery_no', $deliveries->pluck('delivery_no'))
                ->with('product')
                ->get()
                ->map(function (DeliveryItem $item) {
                    try {
                        $basicQty = app(ProductUnitService::class)->toBasicById(
                            $item->product_id,
                            (string) $item->allocated_qty,
                            (string) $item->delivery_unit,
                        );
                    } catch (\InvalidArgumentException $e) {
                        abort(422, $e->getMessage());
                    }

                    $portions = $this->transit->portionsBasic($item, $basicQty);

                    return [
                        'item' => $item,
                        'basic_qty' => $basicQty,
                        'stock_basic' => $portions['stock_basic'],
                        'transit_basic' => $portions['transit_basic'],
                    ];
                });

            if ($items->isEmpty()) {
                abort(422, 'This shipment has no items to issue.');
            }

            // 6. Group STOCK requirements per product (single source per
            // shipment). Transit portions need NO source stock — a product
            // covered purely by transit has a zero requirement here.
            $grouped = $items->groupBy('item.product_id')
                ->map(fn ($group) => $group->sum(fn ($entry) => (float) $entry['stock_basic']))
                ->sortKeys();

            // 7–8. Lock inventory rows in deterministic (customer, product)
            // order and verify the TOTAL restricted availability once —
            // multiple delivery items of the same product share one row.
            // Zero-requirement (pure-transit) products are skipped entirely:
            // no inventory row needs to exist for them.
            $inventories = collect();

            foreach ($grouped->keys() as $productId) {
                if (Decimal::compare((string) $grouped[$productId], '0', 3) === 0) {
                    continue;
                }

                $inventory = Inventory::where('customer_id', $sourceId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($inventory === null) {
                    abort(422, 'No inventory record found while issuing — refresh the shipment.');
                }

                $required = (string) $grouped[$productId];

                if (Decimal::compare((string) $inventory->restricted_qty, $required, 3) < 0) {
                    abort(422, 'Insufficient restricted stock for '.$inventory->product->product_description
                        .': required '.Decimal::trimZeros($required).' '.$inventory->basic_unit
                        .', restricted '.Decimal::trimZeros((string) $inventory->restricted_qty).' '.$inventory->basic_unit
                        .'. The stock was changed after this shipment was prepared.');
                }

                $inventories->put($productId, $inventory);
            }

            // 9. One immutable negative GOODS_ISSUE movement PER DELIVERY ITEM
            // — for the STOCK portion ONLY (ruling 1). A pure-transit item
            // legitimately produces no GI row; a mixed item issues only its
            // stock_basic. The transit portion was issued ONCE at its origin.
            $movements = 0;

            foreach ($items as $entry) {
                if (Decimal::compare($entry['stock_basic'], '0', 3) === 0) {
                    continue; // pure-transit item: no inventory movement
                }

                $item = $entry['item'];
                $inventory = $inventories[$item->product_id];

                InventoryMovement::create([
                    'company_id' => $locked->company_id,
                    'customer_id' => $sourceId,
                    'product_id' => $item->product_id,
                    'movement_type' => MovementType::GOODS_ISSUE,
                    'quantity' => Decimal::sub('0', $entry['stock_basic'], 3),
                    'basic_unit' => $inventory->basic_unit,
                    'reference_type' => 'DELIVERY',
                    'reference_no' => $item->delivery_no,
                    'reference_item_no' => $item->item_no,
                    'movement_datetime' => now(),
                    'created_by' => $actor->employee_id,
                    'remarks' => 'Goods issue (stock portion) via shipment '.$locked->shipment_no,
                ]);

                $movements++;
            }

            // 10. Decrement restricted per product (grouped, STOCK portions
            // only), never unrestricted.
            foreach ($grouped->keys() as $productId) {
                if (Decimal::compare((string) $grouped[$productId], '0', 3) === 0) {
                    continue;
                }

                $inventory = $inventories[$productId];
                $newRestricted = Decimal::sub((string) $inventory->restricted_qty, (string) $grouped[$productId], 3);

                if (Decimal::compare($newRestricted, '0', 3) < 0) {
                    abort(422, 'Inventory invariant violated — goods issue rolled back.');
                }

                $inventory->restricted_qty = $newRestricted;
                $inventory->save();
            }

            // 10b. Transit reservations become physically irreversible here
            // (ruling 3): ACTIVE → FINALIZED, transit rows → REALLOCATED.
            // After this point a delivery release can no longer restore them.
            foreach ($deliveries as $delivery) {
                $this->transit->finalizeForDelivery($delivery->delivery_no);
            }

            // 10c. Strict credit rule (audit correction): START is the last safe
            // boundary before physical dispatch — a debtor with outstanding net
            // exposure must not receive additional exposure here either. This
            // runs AFTER every other lock in the transaction (shipment,
            // deliveries, inventory, transit); the debtor customer rows are the
            // LAST locks (sinks), taken in deterministic customer_id order so
            // two concurrent STARTs sharing a debtor can never deadlock. A
            // blocked START rolls back whole — nothing is dispatched, and
            // ALREADY STARTed stock still flows through POD/transit/return.
            foreach ($deliveries->pluck('customer_id')->unique()->sort()->values() as $debtorId) {
                $this->invoices->assertDebtorWithinExposure(
                    (string) $debtorId, $locked->company_id, 'Shipment start for '.$locked->shipment_no,
                );
            }

            // 11. Deliveries ALLOCATED → SHIPPED.
            foreach ($deliveries as $delivery) {
                $delivery->delivery_status = DeliveryStatus::SHIPPED;
                $delivery->shipped_at = now();
                $delivery->save();
            }

            // 12. Shipment READY → IN_TRANSIT.
            $locked->shipment_status = ShipmentStatus::IN_TRANSIT;
            $locked->started_on = now();
            $locked->save();

            $result = [
                'shipment' => $locked,
                'issued' => $items->count(),
                'movements' => $movements,
            ];
        });

        return $result;
    }

    /**
     * Deliveries eligible for attachment to a DRAFT shipment of this source:
     * same company, same source, ALLOCATED, unshipped, not queued anywhere.
     *
     * @return Collection<int, Delivery>
     */
    public function eligibleDeliveries(Shipment $shipment): Collection
    {
        return Delivery::query()
            ->where('company_id', $shipment->company_id)
            ->where('source_customer_id', $shipment->source_customer_id)
            ->where('delivery_status', DeliveryStatus::ALLOCATED->value)
            ->whereNull('shipped_at')
            ->whereNotIn('delivery_no', ShipmentDelivery::select('delivery_no'))
            ->orderBy('delivery_no')
            ->get();
    }

    /** Item-level issue preview grouped per product (basic units). */
    public function issuePreview(Shipment $shipment): Collection
    {
        return DeliveryItem::whereIn('delivery_no', ShipmentDelivery::where('shipment_no', $shipment->shipment_no)->select('delivery_no'))
            ->with('product')
            ->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                $product = $group->first()->product;
                $basic = $group->sum(function (DeliveryItem $item) {
                    try {
                        return (float) app(ProductUnitService::class)->toBasicById(
                            $item->product_id,
                            (string) $item->allocated_qty,
                            (string) $item->delivery_unit,
                        );
                    } catch (\InvalidArgumentException) {
                        return 0.0;
                    }
                });

                return [
                    'product_id' => $product->product_id,
                    'product' => $product->product_description,
                    'basic_unit' => $product->basic_unit,
                    'basic_qty' => Decimal::format($basic, 3),
                    'lines' => $group->count(),
                    'has_free_items' => $group->contains(fn (DeliveryItem $i) => (bool) $i->is_free_item),
                ];
            })
            ->sortKeys();
    }

    /** DRAFT-only mutations (attach/detach). READY is a locked composition. */
    private function assertMutable(Shipment $shipment): void
    {
        if ($shipment->shipment_status !== ShipmentStatus::DRAFT) {
            abort(422, 'Only DRAFT shipments can be edited. Return the shipment to DRAFT first.');
        }
    }

    private function assertAttachable(Shipment $shipment, Delivery $delivery): void
    {
        if ($delivery->company_id !== $shipment->company_id) {
            abort(422, $delivery->delivery_no.' belongs to another company.');
        }

        if ($delivery->source_customer_id !== $shipment->source_customer_id) {
            abort(422, $delivery->delivery_no.' does not load from the shipment source.');
        }

        if ($delivery->delivery_status !== DeliveryStatus::ALLOCATED) {
            abort(422, $delivery->delivery_no.' is not ALLOCATED.');
        }

        if ($delivery->shipped_at !== null) {
            abort(422, $delivery->delivery_no.' has already been shipped.');
        }

        $elsewhere = ShipmentDelivery::where('delivery_no', $delivery->delivery_no)
            ->where('shipment_no', '!=', $shipment->shipment_no)
            ->exists();

        if ($elsewhere) {
            abort(422, $delivery->delivery_no.' is already queued in another shipment.');
        }

        // Still holding its reserved STOCK PORTION at the source (Phase 9:
        // the transit portion is reserved in transit, not at the source).
        foreach ($delivery->items as $item) {
            try {
                $basicQty = app(ProductUnitService::class)->toBasicById(
                    $item->product_id,
                    (string) $item->allocated_qty,
                    (string) $item->delivery_unit,
                );
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }

            $stockBasic = $this->transit->portionsBasic($item, $basicQty)['stock_basic'];

            if (Decimal::compare($stockBasic, '0', 3) === 0) {
                continue; // pure-transit item: nothing reserved at the source
            }

            $inventory = Inventory::where('customer_id', $shipment->source_customer_id)
                ->where('product_id', $item->product_id)
                ->first();

            if ($inventory === null) {
                abort(422, $delivery->delivery_no.' no longer has an inventory record for '.$item->product_id.'.');
            }

            if (Decimal::compare((string) $inventory->restricted_qty, $stockBasic, 3) < 0) {
                abort(422, $delivery->delivery_no.' no longer holds its reserved stock for '.$item->product_id.'.');
            }
        }
    }

    /** @return Collection<int, Delivery> */
    private function lockedAttachedDeliveries(Shipment $shipment): Collection
    {
        return Delivery::whereIn('delivery_no', ShipmentDelivery::where('shipment_no', $shipment->shipment_no)->select('delivery_no'))
            ->lockForUpdate()
            ->get()
            ->each(fn (Delivery $d) => $d->loadMissing('items'));
    }
}
