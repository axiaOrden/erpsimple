<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\MovementType;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — physical stock administration (minimum viable).
 *
 * Only authoritative PHYSICAL stock changes go through here; each operation
 * writes an immutable `inventory_movement` ledger row. Delivery allocation
 * deliberately does NOT (it is an unrestricted → restricted transfer, not a
 * physical change).
 *
 * Guards:
 *  - stock holder must be PRIMARY / SHIP_TO / VAN
 *  - quantities in the product's BASIC unit (converted from the entered unit)
 *  - a negative adjustment can never push unrestricted below zero —
 *    restricted stock is untouchable by administration
 *  - no silent direct editing of inventory quantities anywhere else
 */
class InventoryService
{
    public function __construct(
        private readonly ProductUnitService $units,
    ) {}

    /** Stock holders allowed to carry inventory rows. */
    public static function isValidStockHolder(CustomerMaster $customer): bool
    {
        return in_array($customer->customer_type->value, [
            CustomerType::PRIMARY->value,
            CustomerType::SHIP_TO->value,
            CustomerType::VAN->value,
        ], true);
    }

    /**
     * Apply a signed physical stock change and write the immutable movement.
     *
     * @param  array{reference_type?: ?string, reference_no?: ?string, reference_item_no?: ?int, remarks?: ?string}  $meta
     * @return array{inventory: Inventory, movement: InventoryMovement}
     */
    public function adjustPhysical(
        EmployeeMaster $actor,
        string $customerId,
        string $productId,
        string $qty,
        string $unit,
        MovementType $movementType,
        array $meta = [],
    ): array {
        if (! in_array($movementType, [
            MovementType::GOODS_RECEIPT,
            MovementType::ADJUSTMENT,
            MovementType::DAMAGE,
        ], true)) {
            abort(422, 'Movement type not supported for administrative adjustments.');
        }

        $customer = CustomerMaster::findOrFail($customerId);

        if (! self::isValidStockHolder($customer) || ! $customer->active) {
            abort(422, 'Stock holder must be an active PRIMARY, SHIP_TO or VAN customer.');
        }

        $product = ProductMaster::findOrFail($productId);

        if ($product->company_id !== $actor->company_id) {
            abort(403, 'Product belongs to another company.');
        }

        try {
            $basicQty = $this->units->toBasic($product, $qty, $unit);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        if (Decimal::compare($basicQty, '0', 3) === 0) {
            abort(422, 'Adjustment quantity must not be zero.');
        }

        /** @var array{inventory: Inventory, movement: InventoryMovement}|null $result */
        $result = null;

        DB::transaction(function () use (&$result, $actor, $customer, $product, $basicQty, $movementType, $meta) {
            // Lock the single customer+product row (never the whole customer).
            $inventory = Inventory::where('customer_id', $customer->customer_id)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                if (Decimal::compare($basicQty, '0', 3) < 0) {
                    abort(422, 'Cannot remove stock that does not exist.');
                }

                // First physical receipt establishes the row at the baseline.
                $inventory = new Inventory([
                    'customer_id' => $customer->customer_id,
                    'product_id' => $product->product_id,
                    'unrestricted_qty' => '0',
                    'restricted_qty' => '0',
                    'basic_unit' => $product->basic_unit,
                ]);
            }

            $newUnrestricted = Decimal::add((string) $inventory->unrestricted_qty, $basicQty, 3);

            // A negative adjustment must never consume restricted stock:
            // restricted is physical stock already allocated to deliveries.
            if (Decimal::compare($newUnrestricted, '0', 3) < 0) {
                abort(422, 'Adjustment would reduce unrestricted stock below zero ('
                    .Decimal::trimZeros((string) $inventory->unrestricted_qty).' '
                    .$inventory->basic_unit.' available, restricted '
                    .Decimal::trimZeros((string) $inventory->restricted_qty).' untouched).');
            }

            $inventory->unrestricted_qty = $newUnrestricted;
            $inventory->save();

            $movement = InventoryMovement::create([
                'company_id' => $actor->company_id,
                'customer_id' => $customer->customer_id,
                'product_id' => $product->product_id,
                'movement_type' => $movementType,
                'quantity' => $basicQty,
                'basic_unit' => $inventory->basic_unit,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_no' => $meta['reference_no'] ?? null,
                'reference_item_no' => $meta['reference_item_no'] ?? null,
                'movement_datetime' => now(),
                'created_by' => $actor->employee_id,
                'remarks' => $meta['remarks'] ?? null,
            ]);

            $result = ['inventory' => $inventory, 'movement' => $movement];
        });

        return $result;
    }
}
