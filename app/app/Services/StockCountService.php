<?php

namespace App\Services;

use App\Enums\CountStatus;
use App\Enums\CountType;
use App\Enums\CustomerType;
use App\Enums\MovementType;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — stock counts.
 *
 * Authority rules:
 *  - PRIMARY_OPERATIONAL counts CAN become the authoritative physical
 *    baseline — but only for customer+product rows with restricted_qty = 0
 *    (a submitted count must never destroy an active Delivery allocation).
 *  - SECONDARY_OBSERVATION counts are stored/reported only; they never touch
 *    inventory and never create movements.
 *  - VAN_CLOSING is stored for Phase 5; its authoritative posting follows the
 *    VAN workflow and is intentionally NOT invented here.
 *
 * Count scope is customer + product (per item); one counted SKU never locks
 * or resets another product at the same customer.
 */
class StockCountService
{
    public function __construct(
        private readonly ProductUnitService $units,
    ) {}

    public function nextNumber(string $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "SC-$companyId-$year-";

        $max = StockCount::where('count_no', 'like', $prefix.'%')->max('count_no');

        $seq = $max !== null ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a DRAFT count for an eligible customer. Sales employees may only
     * count products of their company within their employee_product scope.
     *
     * @param  Collection<int, array{product_id: string, counted_qty: string, count_unit: string}>  $lines
     */
    public function createCount(
        EmployeeMaster $employee,
        string $customerId,
        string $countType,
        $lines,
    ): StockCount {
        $customer = CustomerMaster::findOrFail($customerId);

        $expectedHolder = match ($countType) {
            CountType::PRIMARY_OPERATIONAL->value => CustomerType::PRIMARY,
            CountType::SECONDARY_OBSERVATION->value => CustomerType::SECONDARY,
            CountType::VAN_CLOSING->value => CustomerType::VAN,
            default => null,
        };

        if ($expectedHolder === null || $customer->customer_type !== $expectedHolder) {
            abort(422, 'Count type does not match the customer type.');
        }

        // Company + employee-product scope (no rows = all company products).
        $scoped = EmployeeProduct::where('employee_id', $employee->employee_id)->pluck('product_id');

        $products = ProductMaster::where('company_id', $employee->company_id)
            ->when($scoped->isNotEmpty(), fn ($q) => $q->whereIn('product_id', $scoped))
            ->get()
            ->keyBy('product_id');

        $count = null;

        DB::transaction(function () use (&$count, $employee, $customer, $countType, $lines, $products) {
            $count = StockCount::create([
                'count_no' => $this->nextNumber($employee->company_id),
                'company_id' => $employee->company_id,
                'customer_id' => $customer->customer_id,
                'employee_id' => $employee->employee_id,
                'count_type' => $countType,
                'count_status' => CountStatus::DRAFT,
                'count_date' => today(),
            ]);

            foreach ($lines->values() as $index => $line) {
                $product = $products->get($line['product_id']);

                if ($product === null) {
                    abort(403, 'Product '.$line['product_id'].' is outside your company/product scope.');
                }

                try {
                    $countedBasic = $this->units->toBasic($product, (string) $line['counted_qty'], (string) $line['count_unit']);
                } catch (\InvalidArgumentException $e) {
                    abort(422, $e->getMessage());
                }

                $inventory = Inventory::where('customer_id', $customer->customer_id)
                    ->where('product_id', $product->product_id)
                    ->first();

                StockCountItem::create([
                    'count_no' => $count->count_no,
                    'item_no' => $index + 1,
                    'product_id' => $product->product_id,
                    'expected_qty' => $inventory?->onHandQty(),
                    'counted_qty' => $countedBasic,
                    'variance_qty' => $inventory !== null
                        ? Decimal::sub($countedBasic, (string) $inventory->onHandQty(), 3)
                        : $countedBasic,
                    'count_unit' => $product->basic_unit,
                ]);
            }
        });

        return $count->fresh('items');
    }

    /**
     * Submit a count. Authoritative for PRIMARY_OPERATIONAL (guarded),
     * observational otherwise. Submitted counts are immutable afterwards.
     */
    public function submitCount(EmployeeMaster $actor, StockCount $count): array
    {
        if ($count->count_status !== CountStatus::DRAFT) {
            abort(422, 'Only DRAFT counts can be submitted.');
        }

        /** @var array{inventory: array<int, Inventory>, movements: int}|null $result */
        $result = null;

        DB::transaction(function () use (&$result, $actor, $count) {
            $count = StockCount::whereKey($count->count_no)->lockForUpdate()->firstOrFail();

            if ($count->count_status !== CountStatus::DRAFT) {
                abort(422, 'Only DRAFT counts can be submitted.');
            }

            $posted = 0;

            if ($count->count_type === CountType::PRIMARY_OPERATIONAL) {
                $posted = $this->postAuthoritativePrimaryCount($count, $actor);
            }

            $count->count_status = CountStatus::SUBMITTED;
            $count->submitted_at = now();
            $count->save();

            $result = ['inventory' => [], 'movements' => $posted];
        });

        return $result;
    }

    /**
     * Authoritative PRIMARY posting, per customer+product row:
     *  - rejected outright when restricted stock exists (an active Delivery
     *    allocation must never be destroyed by a count)
     *  - otherwise the counted quantity becomes the new physical baseline:
     *    on_hand := counted, restricted stays 0, unrestricted := counted
     *  - an immutable STOCK_COUNT_BASELINE movement records the posting
     */
    private function postAuthoritativePrimaryCount(StockCount $count, EmployeeMaster $actor): int
    {
        $posted = 0;

        foreach ($count->items as $item) {
            $inventory = Inventory::where('customer_id', $count->customer_id)
                ->where('product_id', $item->product_id)
                ->lockForUpdate()
                ->first();

            if ($inventory !== null && Decimal::compare((string) $inventory->restricted_qty, '0', 3) > 0) {
                abort(422, 'Count blocked for '.$item->product->product_description
                    .': restricted stock exists (active delivery allocation). Submit the count after the allocation is issued.');
            }

            $countedBasic = (string) $item->counted_qty;

            if ($inventory === null) {
                if (Decimal::compare($countedBasic, '0', 3) === 0) {
                    continue; // counting zero where no stock exists — nothing to post
                }

                $inventory = new Inventory([
                    'customer_id' => $count->customer_id,
                    'product_id' => $item->product_id,
                    'unrestricted_qty' => '0',
                    'restricted_qty' => '0',
                    'basic_unit' => $item->product->basic_unit,
                ]);
            }

            $inventory->unrestricted_qty = $countedBasic;
            $inventory->restricted_qty = '0';
            $inventory->save();

            InventoryMovement::create([
                'company_id' => $count->company_id,
                'customer_id' => $count->customer_id,
                'product_id' => $item->product_id,
                'movement_type' => MovementType::STOCK_COUNT_BASELINE,
                'quantity' => $countedBasic,
                'basic_unit' => $inventory->basic_unit,
                'reference_type' => 'STOCK_COUNT',
                'reference_no' => $count->count_no,
                'reference_item_no' => $item->item_no,
                'movement_datetime' => now(),
                'created_by' => $actor->employee_id,
                'remarks' => 'Authoritative primary operational count',
            ]);

            $posted++;
        }

        return $posted;
    }
}
