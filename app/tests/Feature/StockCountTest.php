<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\StockCount;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Services\StockCountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $secondary;

    private ProductMaster $product;

    private SalesOrderService $orders;

    private DeliveryService $deliveries;

    private StockCountService $counts;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();
        $this->secondary = CustomerMaster::factory()->forEmployee($this->employee)->create();

        $this->product = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        ProductUnitConversion::create([
            'product_id' => $this->product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        $this->orders = app(SalesOrderService::class);
        $this->deliveries = app(DeliveryService::class);
        $this->counts = app(StockCountService::class);
        $this->inventory = app(InventoryService::class);
    }

    // ---- Helpers ------------------------------------------------------------

    private function inventoryAt(?CustomerMaster $holder = null, ?ProductMaster $product = null): ?Inventory
    {
        return Inventory::where('customer_id', ($holder ?? $this->primary)->customer_id)
            ->where('product_id', ($product ?? $this->product)->product_id)
            ->first();
    }

    private function countLines(string $qty = '85', string $unit = 'PCS', ?ProductMaster $product = null): array
    {
        return [[
            'product_id' => ($product ?? $this->product)->product_id,
            'counted_qty' => $qty,
            'count_unit' => $unit,
        ]];
    }

    private function createConfirmedOrderWithAllocation(string $qty = '100'): void
    {
        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $this->orders->confirm($order);

        $this->deliveries->createAndAllocate(
            $order->fresh(['items']),
            $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => '30', 'unit' => 'PCS']]),
        );
    }

    // ---- Authoritative primary counts (23, 27) --------------------------------

    public function test_primary_operational_count_becomes_authoritative_baseline(): void
    {
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $this->product->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);

        // HTTP round-trip: create DRAFT count, then submit it.
        $this->actingAs($this->seller)->post('/inventory/counts', [
            'customer_id' => $this->primary->customer_id,
            'count_type' => 'PRIMARY_OPERATIONAL',
            'lines' => $this->countLines('85'),
        ])->assertRedirect();

        $count = StockCount::orderByDesc('created_at')->first();
        $this->assertSame('DRAFT', $count->count_status->value);
        $this->assertSame('85.000', (string) $count->items->first()->counted_qty, 'Counts are stored in basic units.');

        $this->actingAs($this->seller)->post('/inventory/counts/'.$count->count_no.'/submit')->assertRedirect();

        $count->refresh();
        $this->assertSame('SUBMITTED', $count->count_status->value);

        $inv = $this->inventoryAt();
        $this->assertSame('85.000', (string) $inv->unrestricted_qty, 'Counted qty becomes the new baseline.');
        $this->assertSame('0.000', (string) $inv->restricted_qty);

        $movement = InventoryMovement::where('reference_type', 'STOCK_COUNT')
            ->where('reference_no', $count->count_no)
            ->first();
        $this->assertNotNull($movement, 'Authoritative posting writes an immutable movement.');
        $this->assertSame('STOCK_COUNT_BASELINE', $movement->movement_type->value);
        $this->assertSame('85.000', (string) $movement->quantity);
    }

    public function test_primary_count_establishes_inventory_row_that_does_not_exist(): void
    {
        $this->assertNull($this->inventoryAt());

        $count = $this->counts->createCount(
            $this->employee,
            $this->primary->customer_id,
            'PRIMARY_OPERATIONAL',
            collect($this->countLines('12')),
        );
        $this->counts->submitCount($this->employee, $count);

        $inv = $this->inventoryAt();
        $this->assertNotNull($inv);
        $this->assertSame('12.000', (string) $inv->unrestricted_qty);
    }

    public function test_count_scope_is_customer_plus_product_not_whole_customer(): void
    {
        $otherProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $this->product->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $otherProduct->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);

        // Count ONLY the first product — the second SKU at the same customer
        // must stay untouched (lock/count scope = customer + product).
        $count = $this->counts->createCount(
            $this->employee,
            $this->primary->customer_id,
            'PRIMARY_OPERATIONAL',
            collect($this->countLines('70')),
        );
        $this->counts->submitCount($this->employee, $count);

        $this->assertSame('70.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertSame('100.000', (string) $this->inventoryAt($this->primary, $otherProduct)->unrestricted_qty,
            'Counting one SKU must never reset another product at the same customer.');
    }

    // ---- Restricted-stock guard (24) -------------------------------------------

    public function test_primary_count_submission_blocked_while_restricted_stock_exists(): void
    {
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $this->product->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);
        $this->createConfirmedOrderWithAllocation(); // allocates 30 → restricted

        $inv = $this->inventoryAt();
        $this->assertSame('70.000', (string) $inv->unrestricted_qty);
        $this->assertSame('30.000', (string) $inv->restricted_qty);

        $count = $this->counts->createCount(
            $this->employee,
            $this->primary->customer_id,
            'PRIMARY_OPERATIONAL',
            collect($this->countLines('100')),
        );

        try {
            $this->counts->submitCount($this->employee, $count);
            $this->fail('Expected the authoritative submission to be blocked.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsStringIgnoringCase('restricted', $e->getMessage());
        }

        // Active allocation survives; count stays DRAFT; nothing was posted.
        $inv = $this->inventoryAt();
        $this->assertSame('70.000', (string) $inv->unrestricted_qty);
        $this->assertSame('30.000', (string) $inv->restricted_qty);
        $this->assertSame('DRAFT', $count->fresh()->count_status->value);
        $this->assertSame(
            0,
            InventoryMovement::where('movement_type', MovementType::STOCK_COUNT_BASELINE)->count(),
        );
    }

    // ---- Secondary observational counts (25) ------------------------------------

    public function test_secondary_observation_count_never_touches_inventory(): void
    {
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $this->product->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);
        $movementsBefore = InventoryMovement::where('movement_type', MovementType::STOCK_COUNT_BASELINE)->count();

        $count = $this->counts->createCount(
            $this->employee,
            $this->secondary->customer_id, // SECONDARY customer
            'SECONDARY_OBSERVATION',
            collect($this->countLines('5')),
        );
        $this->counts->submitCount($this->employee, $count);

        $this->assertSame('SUBMITTED', $count->fresh()->count_status->value, 'Stored and reportable.');
        $this->assertSame('100.000', (string) $this->inventoryAt()->unrestricted_qty, 'Authoritative inventory unchanged.');
        $this->assertSame(
            $movementsBefore,
            InventoryMovement::where('movement_type', MovementType::STOCK_COUNT_BASELINE)->count(),
            'Observational counts create no physical movements.',
        );
        $this->assertNull(
            Inventory::where('customer_id', $this->secondary->customer_id)->first(),
            'SECONDARY customers are not stock holders — no inventory row may appear.',
        );
    }

    // ---- Immutability (26) -------------------------------------------------------

    public function test_submitted_count_is_immutable(): void
    {
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $this->product->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);

        $count = $this->counts->createCount(
            $this->employee,
            $this->primary->customer_id,
            'PRIMARY_OPERATIONAL',
            collect($this->countLines('85')),
        );
        $this->counts->submitCount($this->employee, $count);

        $movementsAfterFirst = InventoryMovement::where('reference_no', $count->count_no)->count();

        try {
            $this->counts->submitCount($this->employee, $count);
            $this->fail('Expected re-submission of a submitted count to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('SUBMITTED', $count->fresh()->count_status->value);
        $this->assertSame(
            $movementsAfterFirst,
            InventoryMovement::where('reference_no', $count->count_no)->count(),
            'No double posting.',
        );
        $this->assertSame('85.000', (string) $this->inventoryAt()->unrestricted_qty);
    }

    // ---- Count authorization / scope ------------------------------------------------

    public function test_employee_cannot_count_products_outside_scope(): void
    {
        $scopedProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        EmployeeProduct::create(['employee_id' => $this->employee->employee_id, 'product_id' => $scopedProduct->product_id]);

        try {
            $this->counts->createCount(
                $this->employee,
                $this->primary->customer_id,
                'PRIMARY_OPERATIONAL',
                collect($this->countLines('10', 'PCS', $this->product)), // out of scope
            );
            $this->fail('Expected out-of-scope count creation to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, StockCount::count());
    }

    public function test_count_type_must_match_customer_type(): void
    {
        $this->expectException(HttpException::class);

        $this->counts->createCount(
            $this->employee,
            $this->secondary->customer_id, // SECONDARY customer…
            'PRIMARY_OPERATIONAL', // …counted as a Primary → mismatch
            collect($this->countLines('10')),
        );
    }

    public function test_other_employee_cannot_view_or_submit_another_employees_count(): void
    {
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherSeller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $otherEmployee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $count = $this->counts->createCount(
            $this->employee,
            $this->primary->customer_id,
            'PRIMARY_OPERATIONAL',
            collect($this->countLines('85')),
        );

        $this->actingAs($otherSeller)->get('/inventory/counts/'.$count->count_no)->assertForbidden();
        $this->actingAs($otherSeller)->post('/inventory/counts/'.$count->count_no.'/submit')->assertForbidden();
        $this->assertSame('DRAFT', $count->fresh()->count_status->value);
    }

    // ---- Physical adjustments (28, 29) -------------------------------------------------

    public function test_adjustment_creates_immutable_ledger_movement(): void
    {
        $result = $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id,
            $this->product->product_id, '25', 'PCS', MovementType::ADJUSTMENT, ['remarks' => 'cycle fix']);

        $this->assertSame('25.000', (string) $this->inventoryAt()->unrestricted_qty);

        $movement = $result['movement'];
        $this->assertSame('ADJUSTMENT', $movement->movement_type->value);
        $this->assertSame('25.000', (string) $movement->quantity);
        $this->assertSame('PCS', $movement->basic_unit);
        $this->assertSame($this->primary->customer_id, $movement->customer_id);
        $this->assertSame($this->employee->employee_id, $movement->created_by);
        $this->assertSame('cycle fix', $movement->remarks);
    }

    public function test_negative_adjustment_cannot_consume_restricted_stock(): void
    {
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id, $this->product->product_id,
            '100', 'PCS', MovementType::GOODS_RECEIPT);
        $this->createConfirmedOrderWithAllocation(); // 70 unrestricted / 30 restricted

        $movementsBefore = InventoryMovement::count();

        // −80 would push unrestricted below zero (i.e. consume restricted) → rejected.
        try {
            $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id,
                $this->product->product_id, '-80', 'PCS', MovementType::DAMAGE);
            $this->fail('Expected the negative adjustment to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $inv = $this->inventoryAt();
        $this->assertSame('70.000', (string) $inv->unrestricted_qty, 'Rejected adjustment changed nothing.');
        $this->assertSame('30.000', (string) $inv->restricted_qty, 'Restricted stock is untouchable.');
        $this->assertSame($movementsBefore, InventoryMovement::count());

        // −70 (exactly the unrestricted balance) is legal: restricted survives.
        $this->inventory->adjustPhysical($this->employee, $this->primary->customer_id,
            $this->product->product_id, '-70', 'PCS', MovementType::DAMAGE);

        $inv = $this->inventoryAt();
        $this->assertSame('0.000', (string) $inv->unrestricted_qty);
        $this->assertSame('30.000', (string) $inv->restricted_qty, 'Allocation intact for Phase 6 shipment.');
        $this->assertSame('30.000', $inv->onHandQty(), 'ON HAND never drops below restricted.');
    }
}
