<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\MovementType;
use App\Enums\ShipmentStatus;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\ShipmentDelivery;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliveryAllocationTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $shipTo;

    private CustomerMaster $secondary;

    private ProductMaster $product;

    private SalesOrderService $orders;

    private DeliveryService $deliveries;

    private SyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        // Supplying eligibility (Phase 4 correction): primaries must be assigned.
        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();
        $this->shipTo = CustomerMaster::factory()->create([
            'customer_type' => CustomerType::SHIP_TO,
            'parent_customer_id' => $this->primary->customer_id,
        ]);
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
        $this->sync = app(SyncService::class);
    }

    // ---- Helpers ------------------------------------------------------------

    private function receiveStock(string $qty = '1000', string $unit = 'PCS', ?CustomerMaster $holder = null, ?ProductMaster $product = null): void
    {
        $inventoryService = app(InventoryService::class);
        $inventoryService->adjustPhysical(
            $this->employee,
            ($holder ?? $this->primary)->customer_id,
            ($product ?? $this->product)->product_id,
            $qty,
            $unit,
            MovementType::GOODS_RECEIPT,
        );
    }

    private function inventoryAt(?CustomerMaster $holder = null, ?ProductMaster $product = null): Inventory
    {
        return Inventory::where('customer_id', ($holder ?? $this->primary)->customer_id)
            ->where('product_id', ($product ?? $this->product)->product_id)
            ->firstOrFail();
    }

    private function lines(string $qty = '10', string $unit = 'CTN', int $itemNo = 1): Collection
    {
        return collect([[
            'sales_order_item_no' => $itemNo,
            'qty' => $qty,
            'unit' => $unit,
        ]]);
    }

    private function confirmedOrder(string $qty = '100', string $unit = 'PCS', ?CustomerMaster $source = null): SalesOrder
    {
        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            ($source ?? $this->primary)->customer_id,
            $this->secondary->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $this->orders->confirm($order);

        return $order->fresh(['items']);
    }

    // ---- Free deal lines (physical stock, zero price) ---------------------------

    /** A second product to act as the deal REWARD product. */
    private function rewardProduct(string $basicUnit = 'PCS'): ProductMaster
    {
        $reward = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => $basicUnit]);
        ProductUnitConversion::create([
            'product_id' => $reward->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        return $reward;
    }

    /** Buy X $qUnit qualifier → get Y $rUnit reward free. */
    private function makeDeal(ProductMaster $qualifier, string $qQty, string $qUnit, ProductMaster $reward, string $rQty, string $rUnit): void
    {
        $dealNo = 'DT-'.bin2hex(random_bytes(3));

        DealCondition::create([
            'deal_no' => $dealNo,
            'company_id' => $this->company->company_id,
            'deal_description' => "Buy $qQty $qUnit get $rQty $rUnit free",
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => $dealNo,
            'product_id' => $qualifier->product_id,
            'minimum_qty' => $qQty,
            'qualifier_unit' => $qUnit,
        ]);
        DealReward::create([
            'deal_no' => $dealNo,
            'product_id' => $reward->product_id,
            'reward_qty' => $rQty,
            'reward_unit' => $rUnit,
            'for_each_qty' => null,
            'for_each_unit' => null,
        ]);
    }

    private function confirmedDealOrder(string $qty = '10', string $unit = 'CTN'): SalesOrder
    {
        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $this->orders->confirm($order);

        return $order->fresh(['items']);
    }

    public function test_free_deal_line_is_physically_allocated_with_its_own_stock(): void
    {
        $reward = $this->rewardProduct();
        $this->receiveStock('240'); // A: 240 PCS = 10 CTN
        $this->receiveStock('48', 'PCS', $this->primary, $reward); // B: 48 PCS = 2 CTN
        $this->makeDeal($this->product, '10', 'CTN', $reward, '1', 'CTN');

        $order = $this->confirmedDealOrder('10', 'CTN'); // buy 10 CTN A → 1 CTN B free

        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);
        $this->assertNotNull($free, 'Confirmation inserted the free reward line.');
        $this->assertSame($reward->product_id, $free->product_id);

        $movesBefore = InventoryMovement::count();

        $result = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN'],
            ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
        ]));

        $this->assertSame('ALLOCATED', $result['delivery']->delivery_status->value);

        // The free line got its OWN delivery item: own product, own business qty/unit.
        $items = $result['delivery']->items;
        $this->assertSame(2, $items->count());
        $freeItem = $items->first(fn ($i) => (bool) $i->is_free_item);
        $this->assertNotNull($freeItem);
        $this->assertSame($reward->product_id, $freeItem->product_id);
        $this->assertSame('1.000', (string) $freeItem->allocated_qty);
        $this->assertSame('CTN', $freeItem->delivery_unit);

        // Parent and reward maintain INDEPENDENT inventory...
        $a = $this->inventoryAt();
        $b = $this->inventoryAt($this->primary, $reward);
        $this->assertSame('0.000', (string) $a->unrestricted_qty, 'A: 240 − 240.');
        $this->assertSame('240.000', (string) $a->restricted_qty);
        $this->assertSame('24.000', (string) $b->unrestricted_qty, 'B: 48 − 24 (its own conversion).');
        $this->assertSame('24.000', (string) $b->restricted_qty);

        // ...and NO inventory movement is created merely by allocating the free line.
        $this->assertSame($movesBefore, InventoryMovement::count());
    }

    public function test_free_line_allocation_touches_only_the_reward_product(): void
    {
        $reward = $this->rewardProduct();
        $this->receiveStock('240');
        $this->receiveStock('48', 'PCS', $this->primary, $reward);
        $this->makeDeal($this->product, '10', 'CTN', $reward, '1', 'CTN');

        $order = $this->confirmedDealOrder('10', 'CTN');
        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);

        $result = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
        ]));

        $this->assertSame(1, $result['delivery']->items->count());

        $a = $this->inventoryAt();
        $b = $this->inventoryAt($this->primary, $reward);
        $this->assertSame('240.000', (string) $a->unrestricted_qty, 'Parent stock untouched — free line is not "allocated with its parent".');
        $this->assertSame('0.000', (string) $a->restricted_qty);
        $this->assertSame('24.000', (string) $b->unrestricted_qty);
        $this->assertSame('24.000', (string) $b->restricted_qty);
    }

    public function test_free_line_cannot_allocate_without_its_own_unrestricted_stock(): void
    {
        $reward = $this->rewardProduct();
        $this->receiveStock('240'); // only A has stock — B has none
        $this->makeDeal($this->product, '10', 'CTN', $reward, '1', 'CTN');

        $order = $this->confirmedDealOrder('10', 'CTN');
        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);

        try {
            $this->deliveries->createAndAllocate($order, $this->employee, collect([
                ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN'],
                ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
            ]));
            $this->fail('Expected the missing reward stock to block the whole delivery.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('No inventory record', $e->getMessage());
        }

        // All-or-nothing: no delivery invented, parent stock untouched,
        // no stock invented for the reward, order back at CONFIRMED.
        $this->assertSame(0, Delivery::count());
        $this->assertSame(0, DeliveryItem::count());
        $this->assertSame('240.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertNull(Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $reward->product_id)->first(), 'No reward stock was invented.');
        $this->assertSame('CONFIRMED', $order->fresh()->order_status->value);
    }

    // ---- Inventory model -----------------------------------------------------

    public function test_on_hand_is_calculated_not_stored(): void
    {
        $this->receiveStock('100');
        $inv = $this->inventoryAt();

        $this->assertSame('100.000', $inv->unrestricted_qty);
        $this->assertSame('0.000', $inv->restricted_qty);
        $this->assertSame('100.000', $inv->onHandQty());
        $this->assertSame('PCS', $inv->basic_unit);
    }

    public function test_adjustment_rejects_invalid_stock_holder(): void
    {
        $secondary = $this->secondary; // SECONDARY — not a stock holder

        $this->expectException(HttpException::class);

        app(InventoryService::class)->adjustPhysical(
            $this->employee,
            $secondary->customer_id,
            $this->product->product_id,
            '10',
            'PCS',
            MovementType::GOODS_RECEIPT,
        );
    }

    public function test_company_product_isolation_on_adjustment(): void
    {
        $otherCompany = CompanyMaster::factory()->create();
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $otherCompany->company_id]);
        $otherProduct = ProductMaster::factory()->forCompany($otherCompany)->create(['basic_unit' => 'PCS']);

        $this->expectException(HttpException::class);

        app(InventoryService::class)->adjustPhysical(
            $this->employee, // belongs to $this->company
            $this->primary->customer_id,
            $otherProduct->product_id, // other company's product
            '10',
            'PCS',
            MovementType::GOODS_RECEIPT,
        );
    }

    // ---- Allocation semantics --------------------------------------------------

    public function test_allocation_converts_alternative_unit_to_basic(): void
    {
        $this->receiveStock('1000'); // 1000 PCS
        $order = $this->confirmedOrder('48', 'PCS'); // 2 CTN in PCS terms

        $result = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('2', 'CTN'));

        $item = $result['delivery']->items->first();
        $this->assertSame('2.000', (string) $item->allocated_qty, 'Business-facing qty stays 2 CTN.');
        $this->assertSame('CTN', $item->delivery_unit, 'Business-facing unit stays CTN.');

        $inv = $this->inventoryAt();
        $this->assertSame('952.000', (string) $inv->unrestricted_qty, '1000 − 48 PCS.');
        $this->assertSame('48.000', (string) $inv->restricted_qty);
    }

    public function test_allocation_moves_unrestricted_to_restricted_and_keeps_on_hand(): void
    {
        $this->receiveStock('120');
        $before = $this->inventoryAt();
        $order = $this->confirmedOrder('30');

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'));

        $after = $this->inventoryAt();
        $this->assertSame('90.000', (string) $after->unrestricted_qty);
        $this->assertSame('30.000', (string) $after->restricted_qty);
        $this->assertSame($before->onHandQty(), $after->onHandQty(), 'ON HAND must not change.');
    }

    public function test_allocation_creates_no_inventory_movement(): void
    {
        $this->receiveStock('120');
        $movementsBefore = InventoryMovement::count();
        $order = $this->confirmedOrder('30');

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'));

        $this->assertSame($movementsBefore, InventoryMovement::count(), 'Allocation is a stock-state transfer, not a movement.');
    }

    public function test_sales_order_confirmation_has_zero_inventory_effect(): void
    {
        $this->receiveStock('100');

        $before = $this->inventoryAt()->only(['unrestricted_qty', 'restricted_qty']);
        $movementsBefore = InventoryMovement::count();

        $this->confirmedOrder('50');

        $after = $this->inventoryAt()->only(['unrestricted_qty', 'restricted_qty']);
        $this->assertSame($before, $after, 'SO confirmation must not reserve or deduct stock.');
        $this->assertSame($movementsBefore, InventoryMovement::count());
    }

    public function test_demand_may_exceed_inventory(): void
    {
        $this->receiveStock('20');

        // Demand of 100 is fine with only 20 on hand (SO = demand only).
        $order = $this->confirmedOrder('100');
        $this->assertSame('CONFIRMED', $order->order_status->value);
    }

    public function test_partial_allocation_keeps_open_demand(): void
    {
        $this->receiveStock('30');
        $order = $this->confirmedOrder('100'); // demand 100, stock 30

        $result = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'));

        $this->assertSame('ALLOCATED', $result['delivery']->delivery_status->value);

        $remaining = $this->deliveries->remainingBasicQty($order->items->first());
        $this->assertSame('70.000', (string) $remaining, 'SO stays 100 — 70 remains open demand.');

        $order->refresh();
        $this->assertSame('OPEN_DELIVERY', $order->order_status->value);
    }

    public function test_cumulative_allocations_cannot_exceed_demand(): void
    {
        $this->receiveStock('1000');
        $order = $this->confirmedOrder('100');

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('60', 'PCS'));
        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('40', 'PCS'));

        $this->expectException(HttpException::class);

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('1', 'PCS'));
    }

    public function test_allocation_cannot_exceed_unrestricted_stock(): void
    {
        $this->receiveStock('20');
        $order = $this->confirmedOrder('50');

        $this->expectException(HttpException::class);

        try {
            $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('21', 'PCS'));
        } finally {
            $inv = $this->inventoryAt();
            $this->assertSame('20.000', (string) $inv->unrestricted_qty, 'Failed allocation must not touch stock.');
            $this->assertSame('0.000', (string) $inv->restricted_qty);
        }
    }

    public function test_allocation_cannot_make_inventory_negative(): void
    {
        $this->receiveStock('5');
        $order = $this->confirmedOrder('50');

        try {
            $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('10', 'PCS'));
            $this->fail('Expected allocation to fail.');
        } catch (HttpException) {
            $inv = $this->inventoryAt();
            $this->assertGreaterThanOrEqual(0, (float) $inv->unrestricted_qty);
            $this->assertGreaterThanOrEqual(0, (float) $inv->restricted_qty);
        }
    }

    // ---- Source compatibility -------------------------------------------------

    public function test_allocation_uses_only_the_so_source_inventory(): void
    {
        // SO source = the SHIP_TO. Its inventory is used — never the parent's.
        $order = $this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->shipTo->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        $this->receiveStock('500', 'PCS', $this->shipTo); // stock at SHIP_TO
        $this->receiveStock('500', 'PCS', $this->primary); // stock at parent

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('10', 'PCS'));

        $shipTo = $this->inventoryAt($this->shipTo);
        $primary = $this->inventoryAt($this->primary);

        $this->assertSame('490.000', (string) $shipTo->unrestricted_qty, 'SHIP_TO stock is used.');
        $this->assertSame('10.000', (string) $shipTo->restricted_qty);
        $this->assertSame('500.000', (string) $primary->unrestricted_qty, 'Parent Primary stock untouched — no fallback.');
    }

    public function test_no_automatic_fallback_from_empty_ship_to_to_primary(): void
    {
        $order = $this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->shipTo->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        $this->receiveStock('500', 'PCS', $this->primary); // only the parent has stock

        $this->expectException(HttpException::class);

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('10', 'PCS'));
    }

    // ---- Rejection interaction ------------------------------------------------

    public function test_rejected_item_blocks_new_allocation(): void
    {
        $this->receiveStock('1000');
        $order = $this->confirmedOrder('100');
        $item = $order->items->first();

        $this->orders->rejectItem($item, 'Customer cancelled', $this->employee);

        $this->expectException(HttpException::class);

        $this->deliveries->createAndAllocate($order->fresh(['items']), $this->employee, $this->lines('30', 'PCS'));
    }

    public function test_existing_allocation_survives_later_rejection(): void
    {
        $this->receiveStock('1000');
        $order = $this->confirmedOrder('30');
        $item = $order->items->first();

        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('10', 'PCS'));

        $this->orders->rejectItem($item, 'Cancel the remainder', $this->employee);

        // Existing 10 PCS allocation stays restricted and valid.
        $inv = $this->inventoryAt();
        $this->assertSame('10.000', (string) $inv->restricted_qty, 'Rejection must not return existing allocations.');
        $this->assertSame('990.000', (string) $inv->unrestricted_qty);

        // Remaining demand is zero (no NEW allocation possible).
        $this->assertSame('0.000', (string) $this->deliveries->remainingBasicQty($item->fresh()));

        $order->refresh();
        // Phase 4 semantics preserved: the single item is rejected, so the ORDER
        // reads COMPLETELY_REJECTED even though one allocation survives for
        // Phase 6 shipment (enum cannot express "rejected but partially allocated").
        $this->assertSame('COMPLETELY_REJECTED', $order->order_status->value);
    }

    // ---- Release of an allocated delivery ---------------------------------------

    public function test_allocated_delivery_releases_back_to_draft_and_restores_stock(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('100');

        $result = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('80', 'PCS'));
        $delivery = $result['delivery'];

        $this->assertSame('20.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertSame('80.000', (string) $this->inventoryAt()->restricted_qty);

        $movementsBefore = InventoryMovement::count();

        $released = $this->deliveries->releaseDelivery($delivery);

        // Delivery back to DRAFT, allocation stamp cleared.
        $this->assertSame('DRAFT', $released->delivery_status->value);
        $this->assertNull($released->allocated_at);

        // restricted → unrestricted, ON HAND unchanged, no movement.
        $inv = $this->inventoryAt();
        $this->assertSame('100.000', (string) $inv->unrestricted_qty, 'restricted returned to unrestricted.');
        $this->assertSame('0.000', (string) $inv->restricted_qty);
        $this->assertSame('100.000', $inv->onHandQty(), 'ON HAND must not change on release.');
        $this->assertSame($movementsBefore, InventoryMovement::count(), 'Release is a stock-state transfer, not a movement.');

        // Released demand is free again → SO returns to CONFIRMED.
        $this->assertSame('CONFIRMED', $order->fresh()->order_status->value);
        $this->assertSame('100.000', (string) $this->deliveries->remainingBasicQty($order->items->first()));
    }

    public function test_release_restores_multi_item_and_free_deal_stock_independently(): void
    {
        $reward = $this->rewardProduct();
        $this->receiveStock('240'); // A: 10 CTN
        $this->receiveStock('48', 'PCS', $this->primary, $reward); // B: 2 CTN
        $this->makeDeal($this->product, '10', 'CTN', $reward, '1', 'CTN');

        $order = $this->confirmedDealOrder('10', 'CTN');
        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);

        $result = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN'],
            ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
        ]));

        $this->assertSame('0.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertSame('24.000', (string) $this->inventoryAt($this->primary, $reward)->unrestricted_qty);

        $released = $this->deliveries->releaseDelivery($result['delivery']);

        $this->assertSame('DRAFT', $released->delivery_status->value);

        // Each product's own stock restored independently (free item too).
        $this->assertSame('240.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertSame('0.000', (string) $this->inventoryAt()->restricted_qty);
        $this->assertSame('48.000', (string) $this->inventoryAt($this->primary, $reward)->unrestricted_qty);
        $this->assertSame('0.000', (string) $this->inventoryAt($this->primary, $reward)->restricted_qty);
    }

    public function test_released_delivery_can_be_edited_and_reallocated(): void
    {
        // Spec scenario: 100 CTN available; allocate 80 to A, release,
        // re-allocate 40 to A and 50 to B → unrestricted 10, restricted 90.
        $this->receiveStock('100');

        $orderA = $this->confirmedOrder('100');
        $first = $this->deliveries->createAndAllocate($orderA, $this->employee, $this->lines('80', 'PCS'))['delivery'];
        $this->assertSame('20.000', (string) $this->inventoryAt()->unrestricted_qty);

        $released = $this->deliveries->releaseDelivery($first);
        $this->assertSame('DRAFT', $released->delivery_status->value);

        // Edit + allocate the SAME (released) delivery at 40.
        $result = $this->deliveries->reallocateDraft(
            $released,
            $orderA->fresh(['items']),
            $this->employee,
            $this->lines('40', 'PCS'),
        );

        $this->assertSame('ALLOCATED', $result['delivery']->delivery_status->value);
        $this->assertSame($first->delivery_no, $result['delivery']->delivery_no, 'The released document is re-used, not duplicated.');
        $this->assertSame('40.000', (string) $result['delivery']->items->first()->allocated_qty);

        // Separate delivery for B's urgent requirement (its own confirmed SO).
        $orderB = $this->confirmedOrder('50');
        $this->deliveries->createAndAllocate($orderB, $this->employee, $this->lines('50', 'PCS'));

        $inv = $this->inventoryAt();
        $this->assertSame('10.000', (string) $inv->unrestricted_qty, '100 − 40 − 50.');
        $this->assertSame('90.000', (string) $inv->restricted_qty, '40 + 50.');
        $this->assertSame('100.000', $inv->onHandQty(), 'ON HAND never changed through release + re-allocation.');
        $this->assertSame(1, Delivery::where('sales_order_no', $orderA->sales_order_no)->count(), 'Release + re-allocation keeps ONE delivery document for the order.');
        $this->assertSame('OPEN_DELIVERY', $orderA->fresh()->order_status->value);
    }

    public function test_shipped_delivery_cannot_be_released(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('30');
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'))['delivery'];

        // Simulate the Phase 6 boundary: attached to a shipment that is no
        // longer DRAFT (i.e. started) — the goods-issue frontier.
        $shipment = Shipment::create([
            'shipment_no' => 'SH-T-1',
            'company_id' => $this->company->company_id,
            'source_customer_id' => $this->primary->customer_id,
            'shipment_status' => ShipmentStatus::READY,
            'created_by' => $this->employee->employee_id,
        ]);
        ShipmentDelivery::create([
            'shipment_no' => $shipment->shipment_no,
            'delivery_no' => $delivery->delivery_no,
        ]);

        try {
            $this->deliveries->releaseDelivery($delivery);
            $this->fail('Expected the release of a shipment-attached delivery to be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no longer be released', $e->getMessage());
        }

        // Stock untouched: still allocated, still restricted.
        $this->assertSame('70.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertSame('30.000', (string) $this->inventoryAt()->restricted_qty);
        $this->assertSame('ALLOCATED', $delivery->fresh()->delivery_status->value);

        // Also guarded when shipped_at is set directly (post-issue marker).
        $delivery->shipped_at = now();
        $delivery->save();

        $this->expectException(HttpException::class);
        $this->deliveries->releaseDelivery($delivery);
    }

    public function test_release_auto_detaches_delivery_from_draft_shipment(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('30');
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'))['delivery'];

        // Queued in a DRAFT shipment — planning only, no physical issue.
        $shipment = Shipment::create([
            'shipment_no' => 'SH-DRAFT-1',
            'company_id' => $this->company->company_id,
            'source_customer_id' => $this->primary->customer_id,
            'shipment_status' => ShipmentStatus::DRAFT,
            'created_by' => $this->employee->employee_id,
        ]);
        ShipmentDelivery::create(['shipment_no' => $shipment->shipment_no, 'delivery_no' => $delivery->delivery_no]);

        $released = $this->deliveries->releaseDelivery($delivery);

        // Release succeeded and the association is GONE in the same transaction.
        $this->assertSame('DRAFT', $released->delivery_status->value);
        $this->assertSame(0, ShipmentDelivery::where('delivery_no', $delivery->delivery_no)->count());
        $this->assertSame('DRAFT', $shipment->fresh()->shipment_status->value, 'The (now empty) shipment itself is untouched.');

        // Stock restored (70 + 30 released); no movement.
        $inv = $this->inventoryAt();
        $this->assertSame('100.000', (string) $inv->unrestricted_qty);
        $this->assertSame('0.000', (string) $inv->restricted_qty);
        $this->assertSame(1, InventoryMovement::count()); // only the setup receipt
    }

    public function test_release_rejected_for_ready_shipment_with_guidance(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('30');
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'))['delivery'];

        $shipment = Shipment::create([
            'shipment_no' => 'SH-READY-1',
            'company_id' => $this->company->company_id,
            'source_customer_id' => $this->primary->customer_id,
            'shipment_status' => ShipmentStatus::READY,
            'created_by' => $this->employee->employee_id,
        ]);
        ShipmentDelivery::create(['shipment_no' => $shipment->shipment_no, 'delivery_no' => $delivery->delivery_no]);

        try {
            $this->deliveries->releaseDelivery($delivery);
            $this->fail('Expected release from a READY shipment to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('READY', $e->getMessage());
            $this->assertStringContainsString('Move the shipment back to DRAFT', $e->getMessage());
        }

        // STILL attached — the failed release must not have detached it.
        $this->assertSame(1, ShipmentDelivery::where('delivery_no', $delivery->delivery_no)->count());
        $this->assertSame('ALLOCATED', $delivery->fresh()->delivery_status->value);
        $this->assertSame('30.000', (string) $this->inventoryAt()->restricted_qty);
    }

    public function test_unauthorized_release_is_rejected(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('30');
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('30', 'PCS'))['delivery'];

        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherSeller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $otherEmployee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $this->actingAs($otherSeller)
            ->post('/deliveries/'.$delivery->delivery_no.'/release', ['idempotency_key' => 'release-evil'])
            ->assertForbidden();

        $this->assertSame('ALLOCATED', $delivery->fresh()->delivery_status->value);
        $this->assertSame('30.000', (string) $this->inventoryAt()->restricted_qty);
    }

    public function test_release_retry_does_not_release_twice(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('80');
        $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('80', 'PCS'));

        $payload = ['idempotency_key' => 'release-key-1'];

        $first = $this->actingAs($this->seller)->post('/deliveries/'.Delivery::first()->delivery_no.'/release', $payload);
        $first->assertRedirect();

        $retry = $this->actingAs($this->seller)->post('/deliveries/'.Delivery::first()->delivery_no.'/release', $payload);
        $retry->assertRedirect();

        // A double release would push unrestricted to 180 — it must stay 100.
        $inv = $this->inventoryAt();
        $this->assertSame('100.000', (string) $inv->unrestricted_qty, 'Retry must not return restricted stock twice.');
        $this->assertSame('0.000', (string) $inv->restricted_qty);
        $this->assertSame('DRAFT', Delivery::first()->delivery_status->value);

        $retry->assertSessionHas('status', fn ($status) => str_contains($status, 'idempotent replay'));
    }

    public function test_concurrent_double_release_cannot_corrupt_inventory(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('80');
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, $this->lines('80', 'PCS'))['delivery'];

        // Both "racing" calls run against the same loaded ALLOCATED model —
        // the second must lose at the under-lock status check, not corrupt
        // the balance by returning restricted stock twice.
        $first = $this->deliveries->releaseDelivery($delivery);
        $this->assertSame('DRAFT', $first->delivery_status->value);

        try {
            $this->deliveries->releaseDelivery($delivery);
            $this->fail('Expected the second release to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $inv = $this->inventoryAt();
        $this->assertSame('100.000', (string) $inv->unrestricted_qty, 'Stock restored exactly once.');
        $this->assertSame('0.000', (string) $inv->restricted_qty);
        $this->assertSame('100.000', $inv->onHandQty());
    }

    // ---- Transaction integrity -------------------------------------------------

    public function test_multi_line_delivery_is_all_or_nothing(): void
    {
        $this->receiveStock('100');
        $otherProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);

        $order = $this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([
                ['product_id' => $this->product->product_id, 'qty' => '50', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null],
                ['product_id' => $otherProduct->product_id, 'qty' => '50', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null],
            ]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        // Line A (50 of product) can allocate; line B has NO stock at all.
        try {
            $this->deliveries->createAndAllocate($order, $this->employee, collect([
                ['sales_order_item_no' => 1, 'qty' => '50', 'unit' => 'PCS'],
                ['sales_order_item_no' => 2, 'qty' => '50', 'unit' => 'PCS'],
            ]));
            $this->fail('Expected the multi-line delivery to fail.');
        } catch (HttpException) {
            $this->assertSame(0, Delivery::count(), 'No delivery document may survive a failed multi-line allocation.');
            $inv = $this->inventoryAt();
            $this->assertSame('100.000', (string) $inv->unrestricted_qty, 'Line A stock must be rolled back too.');
            $this->assertSame('0.000', (string) $inv->restricted_qty);
        }
    }

    public function test_idempotent_retry_does_not_double_allocate(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('50');

        $payload = [
            'idempotency_key' => 'alloc-key-1',
            'lines' => [['sales_order_item_no' => 1, 'qty' => '20', 'unit' => 'PCS']],
        ];

        $first = $this->actingAs($this->seller)->post('/orders/'.$order->sales_order_no.'/deliveries', $payload);
        $first->assertRedirect();

        $retry = $this->actingAs($this->seller)->post('/orders/'.$order->sales_order_no.'/deliveries', $payload);

        // The retry replays: exactly ONE delivery, ONE item, no double deduction.
        $this->assertSame(1, Delivery::count());
        $this->assertSame(1, DeliveryItem::count());

        $inv = $this->inventoryAt();
        $this->assertSame('80.000', (string) $inv->unrestricted_qty, 'Retry must not decrement again.');
        $this->assertSame('20.000', (string) $inv->restricted_qty);
        $this->assertSame(0, InventoryMovement::where('reference_type', 'DELIVERY')->count());

        $retry->assertSessionHas('status', fn ($status) => str_contains($status, 'idempotent replay'));
    }

    public function test_idempotency_composite_key_never_exceeds_column_length(): void
    {
        // A long client key (e.g. an oversized UUID) previously overflowed the
        // 64-char idempotency_keys column with a fatal SQL error before the
        // allocation could even validate. The FULL stored key — endpoint|
        // user|client — must fit; the client portion is truncated first.
        $request = Request::create('/orders/x/deliveries', 'POST', [
            'idempotency_key' => 'HEADMARKER'.str_repeat('K', 200),
        ]);

        $first = $this->sync->process('delivery_allocation', $request, fn () => ['ok' => true]);
        $this->assertFalse($first['replayed']);

        $row = DB::table('idempotency_keys')->where('endpoint', 'delivery_allocation')->first();

        $this->assertNotNull($row);
        $this->assertLessThanOrEqual(64, strlen($row->idempotency_key));
        $this->assertStringStartsWith('delivery_allocation|', $row->idempotency_key, 'Endpoint scope prefix survives.');
        $this->assertStringContainsString('HEADMARKER', $row->idempotency_key, 'The client key HEAD is kept — the tail is what gets cut.');

        // Same oversized key retried (lost response) → replay, single row.
        $retry = $this->sync->process('delivery_allocation', $request, fn () => ['ok' => true]);
        $this->assertTrue($retry['replayed']);
        $this->assertSame(1, DB::table('idempotency_keys')->where('endpoint', 'delivery_allocation')->count());
    }

    // ---- Authorization ---------------------------------------------------------

    public function test_other_employees_order_cannot_be_fulfilled(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('50');

        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherSeller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $otherEmployee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $this->actingAs($otherSeller)
            ->get('/orders/'.$order->sales_order_no.'/deliveries/create')
            ->assertForbidden();

        $this->actingAs($otherSeller)
            ->post('/orders/'.$order->sales_order_no.'/deliveries', [
                'idempotency_key' => 'evil-key',
                'lines' => [['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS']],
            ])
            ->assertForbidden();

        $this->assertSame('100.000', (string) $this->inventoryAt()->unrestricted_qty);
    }

    public function test_employee_product_scope_enforced_for_allocation(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('50');

        // The employee is scoped to a DIFFERENT product → the SO product is out of scope.
        $otherProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        EmployeeProduct::create(['employee_id' => $this->employee->employee_id, 'product_id' => $otherProduct->product_id]);

        $this->actingAs($this->seller)
            ->post('/orders/'.$order->sales_order_no.'/deliveries', [
                'idempotency_key' => 'scope-key',
                'lines' => [['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS']],
            ])
            ->assertForbidden();

        $this->assertSame('100.000', (string) $this->inventoryAt()->unrestricted_qty);
    }

    public function test_admin_of_other_company_cannot_allocate(): void
    {
        $this->receiveStock('100');
        $order = $this->confirmedOrder('50');

        $foreignAdmin = AppUser::factory()->companyAdmin()->create();

        $this->actingAs($foreignAdmin)
            ->post('/orders/'.$order->sales_order_no.'/deliveries', [
                'idempotency_key' => 'foreign-key',
                'lines' => [['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS']],
            ])
            ->assertForbidden();
    }
}
