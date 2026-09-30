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
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentDelivery;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShipmentTest extends TestCase
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

    private ShipmentService $shipments;

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
        $this->shipments = app(ShipmentService::class);
        $this->inventory = app(InventoryService::class);
    }

    // ---- Helpers ------------------------------------------------------------

    private function receiveStock(string $qty = '1000', string $unit = 'PCS', ?CustomerMaster $holder = null, ?ProductMaster $product = null): void
    {
        $this->inventory->adjustPhysical(
            $this->employee,
            ($holder ?? $this->primary)->customer_id,
            ($product ?? $this->product)->product_id,
            $qty,
            $unit,
            MovementType::GOODS_RECEIPT,
        );
    }

    private function confirmedOrder(string $qty = '100', string $unit = 'PCS', ?CustomerMaster $source = null)
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

    /** Allocate a delivery with a CLEAN quantity against a dedicated SO. */
    private function allocatedDelivery(string $qty, string $unit = 'PCS', ?CustomerMaster $source = null)
    {
        $order = $this->confirmedOrder($qty, $unit, $source);

        return $this->deliveries->createAndAllocate(
            $order,
            $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => $qty, 'unit' => $unit]]),
        )['delivery'];
    }

    private function draftShipment(): Shipment
    {
        return $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
    }

    private function readyShipmentWith(string $deliveryQty): array
    {
        $delivery = $this->allocatedDelivery($deliveryQty);
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);

        return [$shipment->fresh(['deliveries.items']), $delivery];
    }

    private function inventoryAt(?CustomerMaster $holder = null, ?ProductMaster $product = null): Inventory
    {
        return Inventory::where('customer_id', ($holder ?? $this->primary)->customer_id)
            ->where('product_id', ($product ?? $this->product)->product_id)->first();
    }

    /**
     * Seed a SO directly (bypasses capture validation) — used for sources the
     * capture rule does not emit (VAN). Quantities are records of demand, not
     * authorization claims.
     */
    private function seedOrder(string $sourceId, string $qty, string $unit = 'PCS')
    {
        $order = SalesOrder::create([
            'sales_order_no' => 'SO-T-'.bin2hex(random_bytes(4)),
            'company_id' => $this->company->company_id,
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $sourceId,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'sales_employee_id' => $this->employee->employee_id,
            'order_status' => 'CONFIRMED',
            'pricing_date' => today(),
        ]);
        SalesOrderItem::create([
            'sales_order_no' => $order->sales_order_no,
            'item_no' => 1,
            'product_id' => $this->product->product_id,
            'order_qty' => $qty,
            'order_unit' => $unit,
            'order_price' => '0',
            'is_free_item' => false,
        ]);

        return $order->fresh(['items']);
    }

    // ---- Composition (DRAFT) --------------------------------------------------

    public function test_draft_composition_rules_are_enforced(): void
    {
        $this->receiveStock('500');
        $delivery = $this->allocatedDelivery('30');
        $shipment = $this->draftShipment();

        // Happy attach + detach.
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->assertSame(1, ShipmentDelivery::where('shipment_no', $shipment->shipment_no)->count());
        $this->shipments->detachDelivery($shipment, $delivery->delivery_no);
        $this->assertSame(0, ShipmentDelivery::where('shipment_no', $shipment->shipment_no)->count());

        // Wrong source: a legitimate SHIP_TO of this Primary is a valid SO
        // source, but it is NOT the shipment's source (the Primary itself) —
        // that is the mismatch the composition rule must reject.
        $this->receiveStock('500', 'PCS', $this->shipTo);
        $foreignSource = $this->allocatedDelivery('10', 'PCS', $this->shipTo);

        try {
            $this->shipments->attachDelivery($shipment, $foreignSource->delivery_no);
            $this->fail('Expected wrong-source attach to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('does not load from the shipment source', $e->getMessage());
        }

        // Non-ALLOCATED: release a delivery back to DRAFT, then try to attach.
        $draftDelivery = $this->allocatedDelivery('5');
        $this->deliveries->releaseDelivery($draftDelivery);

        try {
            $this->shipments->attachDelivery($shipment, $draftDelivery->delivery_no);
            $this->fail('Expected DRAFT delivery attach to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('is not ALLOCATED', $e->getMessage());
        }

        // Shipped delivery (post-issue marker) is rejected.
        $delivery->shipped_at = now();
        $delivery->save();

        try {
            $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
            $this->fail('Expected shipped delivery attach to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('already been shipped', $e->getMessage());
        }

        // Already queued in another shipment is rejected.
        $delivery->shipped_at = null;
        $delivery->save();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);

        $secondShipment = $this->draftShipment();

        try {
            $this->shipments->attachDelivery($secondShipment, $delivery->delivery_no);
            $this->fail('Expected double-queue to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('another shipment', $e->getMessage());
        }
    }

    public function test_delivery_of_another_salesperson_at_same_source_can_be_attached(): void
    {
        // Ruling §3: shipments combine deliveries from different salespeople —
        // the source is shared operational ground, not creator-owned.
        $this->receiveStock('500');

        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);

        $order = $this->confirmedOrder('20');
        $delivery = $this->deliveries->createAndAllocate($order, $otherEmployee,
            collect([['sales_order_item_no' => 1, 'qty' => '20', 'unit' => 'PCS']]))['delivery'];

        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);

        $result = $this->shipments->start($this->employee, $shipment);

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $result['shipment']->shipment_status);
        $this->assertSame('SHIPPED', $delivery->fresh()->delivery_status->value);
    }

    // ---- READY / back to DRAFT -------------------------------------------------

    public function test_ready_locks_composition_without_inventory_effect(): void
    {
        $this->receiveStock('100');
        [$shipment, $delivery] = $this->readyShipmentWith('30');

        $movementsBefore = InventoryMovement::count();
        $this->assertSame('70.000', (string) $this->inventoryAt()->unrestricted_qty);
        $this->assertSame('30.000', (string) $this->inventoryAt()->restricted_qty);

        // Composition locked while READY: attach is rejected.
        try {
            $this->shipments->attachDelivery($shipment, $this->allocatedDelivery('10')->delivery_no);
            $this->fail('Expected attach while READY to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Return the shipment to DRAFT', $e->getMessage());
        }

        // ...and detach is rejected too.
        try {
            $this->shipments->detachDelivery($shipment, $delivery->delivery_no);
            $this->fail('Expected detach while READY to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Return the shipment to DRAFT', $e->getMessage());
        }

        // No inventory effect, no movement from READY itself.
        $this->assertSame($movementsBefore, InventoryMovement::count());
        $this->assertSame('READY', $shipment->fresh()->shipment_status->value);

        // READY → DRAFT unlocks; attach works again; still no inventory effect.
        $this->shipments->backToDraft($shipment);
        $this->assertSame('DRAFT', $shipment->fresh()->shipment_status->value);
        $this->assertNull($shipment->fresh()->ready_on);

        $this->shipments->attachDelivery($shipment, $this->allocatedDelivery('10')->delivery_no);
        $this->assertSame(2, ShipmentDelivery::where('shipment_no', $shipment->shipment_no)->count());
        $this->assertSame($movementsBefore, InventoryMovement::count());
    }

    public function test_release_from_ready_shipment_blocks_then_flows_after_back_to_draft(): void
    {
        $this->receiveStock('100');
        [$shipment, $delivery] = $this->readyShipmentWith('30');

        // Phase 5 rule: READY attachment forbids release.
        try {
            $this->deliveries->releaseDelivery($delivery);
            $this->fail('Expected release from READY shipment to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Move the shipment back to DRAFT', $e->getMessage());
        }

        // Deliberate un-queue: READY → DRAFT, then release auto-detaches.
        $this->shipments->backToDraft($shipment);
        $this->deliveries->releaseDelivery($delivery);
        $this->assertSame(0, ShipmentDelivery::where('delivery_no', $delivery->delivery_no)->count());

        // Re-READY with nothing attached is refused by the composition rule,
        // so START-from-empty is untestable at READY; assert that instead.
        try {
            $this->shipments->markReady($shipment->fresh());
            $this->fail('Expected READY without deliveries to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('at least one attached delivery', $e->getMessage());
        }
    }

    // ---- START: the physical boundary --------------------------------------------

    public function test_start_groups_inventory_decrement_but_keeps_per_item_movements(): void
    {
        // The ruling's example: Delivery A item 1 = 10 PCS, Delivery B item 1
        // = 5 PCS, same product → ONE grouped −15 state write, TWO ledger rows.
        $this->receiveStock('100');
        $deliveryA = $this->allocatedDelivery('10');
        $deliveryB = $this->allocatedDelivery('5');

        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $deliveryA->delivery_no);
        $this->shipments->attachDelivery($shipment, $deliveryB->delivery_no);
        $this->shipments->markReady($shipment);

        $receiptMovements = InventoryMovement::count();

        $result = $this->shipments->start($this->employee, $shipment);

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $result['shipment']->shipment_status);
        $this->assertNotNull($result['shipment']->started_on);

        $inv = $this->inventoryAt();
        $this->assertSame('85.000', (string) $inv->unrestricted_qty, 'Unrestricted unchanged by goods issue.');
        $this->assertSame('0.000', (string) $inv->restricted_qty, '10 + 5 restricted issued.');
        $this->assertSame('85.000', $inv->onHandQty(), 'ON HAND dropped 100 → 85.');

        // Ledger: one immutable negative GOODS_ISSUE row PER DELIVERY ITEM.
        $issues = InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)
            ->orderBy('movement_id')->get();
        $this->assertSame(2, $issues->count());
        $this->assertSame('-10.000', (string) $issues[0]->quantity);
        $this->assertSame('DELIVERY', $issues[0]->reference_type);
        $this->assertSame($deliveryA->delivery_no, $issues[0]->reference_no);
        $this->assertSame(1, (int) $issues[0]->reference_item_no);
        $this->assertSame('-5.000', (string) $issues[1]->quantity);
        $this->assertSame($deliveryB->delivery_no, $issues[1]->reference_no);
        $this->assertStringContainsString($shipment->shipment_no, (string) $issues[1]->remarks);

        $this->assertSame($receiptMovements + 2, InventoryMovement::count());

        // Deliveries SHIPPED; SO header NOT touched (no false "delivered").
        $this->assertSame('SHIPPED', $deliveryA->fresh()->delivery_status->value);
        $this->assertNotNull($deliveryA->fresh()->shipped_at);
        $this->assertSame('SHIPPED', $deliveryB->fresh()->delivery_status->value);
        $this->assertSame('OPEN_DELIVERY', $deliveryA->salesOrder->fresh()->order_status->value);
        $this->assertSame('OPEN_DELIVERY', $deliveryB->salesOrder->fresh()->order_status->value);
    }

    public function test_start_requires_ready_and_blocks_restart(): void
    {
        $this->receiveStock('100');
        $delivery = $this->allocatedDelivery('30');
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);

        // DRAFT cannot START.
        try {
            $this->shipments->start($this->employee, $shipment);
            $this->fail('Expected START from DRAFT to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Only READY shipments', $e->getMessage());
        }

        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        // IN_TRANSIT cannot START again (status guard, independent of idempotency).
        try {
            $this->shipments->start($this->employee, $shipment->fresh());
            $this->fail('Expected second START to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Only READY shipments', $e->getMessage());
        }

        // Exactly one issue set despite the attempts.
        $this->assertSame(1, InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count());
        $this->assertSame('70.000', (string) $this->inventoryAt()->onHandQty());
    }

    public function test_start_is_all_or_nothing_when_restricted_stock_drifted(): void
    {
        $this->receiveStock('100');
        $deliveryA = $this->allocatedDelivery('30');
        $deliveryB = $this->allocatedDelivery('20');
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $deliveryA->delivery_no);
        $this->shipments->attachDelivery($shipment, $deliveryB->delivery_no);
        $this->shipments->markReady($shipment);

        // Simulate external drift: restricted drops below the 50 required.
        DB::table('inventory')
            ->where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)
            ->update(['restricted_qty' => 40.000]);

        $movementsBefore = InventoryMovement::count();

        try {
            $this->shipments->start($this->employee, $shipment);
            $this->fail('Expected START to fail on insufficient restricted stock.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Insufficient restricted stock', $e->getMessage());
        }

        // NOTHING changed: statuses, stock, ledger.
        $this->assertSame('READY', $shipment->fresh()->shipment_status->value);
        $this->assertSame('ALLOCATED', $deliveryA->fresh()->delivery_status->value);
        $this->assertSame('ALLOCATED', $deliveryB->fresh()->delivery_status->value);
        $this->assertSame($movementsBefore, InventoryMovement::count());
        $this->assertSame('40.000', (string) $this->inventoryAt()->restricted_qty);
        // START changed nothing further: on-hand stayed at the drifted 90
        // (unrestricted 70 was already below the original 100 from the drift).
        $this->assertSame('90.000', (string) $this->inventoryAt()->onHandQty());
    }

    public function test_van_source_shipment_issues_from_van_stock(): void
    {
        $van = CustomerMaster::factory()->van()->forEmployee($this->employee)->create();
        $this->receiveStock('60', 'PCS', $van);

        // VAN cannot be an SO capture source (Phase 4 rule), so seed the
        // confirmed demand record directly — it carries no authorization.
        $order = $this->seedOrder($van->customer_id, '50');
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => '50', 'unit' => 'PCS']]))['delivery'];

        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $van->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        $inv = $this->inventoryAt($van);
        $this->assertSame('10.000', (string) $inv->unrestricted_qty);
        $this->assertSame('0.000', (string) $inv->restricted_qty);
        $this->assertSame('10.000', $inv->onHandQty());

        $issue = InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->first();
        $this->assertSame($van->customer_id, $issue->customer_id);
    }

    public function test_start_issues_free_deal_items_physically(): void
    {
        $reward = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        ProductUnitConversion::create([
            'product_id' => $reward->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);
        $this->receiveStock('240'); // A: 10 CTN
        $this->receiveStock('24', 'PCS', $this->primary, $reward); // B: 1 CTN

        DealCondition::create([
            'deal_no' => 'DT-START-1',
            'company_id' => $this->company->company_id,
            'deal_description' => 'buy 10 CTN get 1 CTN',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => 'DT-START-1',
            'product_id' => $this->product->product_id,
            'minimum_qty' => '10',
            'qualifier_unit' => 'CTN',
        ]);
        DealReward::create([
            'deal_no' => 'DT-START-1',
            'product_id' => $reward->product_id,
            'reward_qty' => '1',
            'reward_unit' => 'CTN',
        ]);

        $order = $this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id, $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'CTN',
                'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN'],
            ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
        ]))['delivery'];

        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        // Both products physically issued (free item at its own basic qty).
        $issues = InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->get();
        $this->assertSame(2, $issues->count());

        $rewardIssue = $issues->first(fn ($m) => $m->product_id === $reward->product_id);
        $this->assertNotNull($rewardIssue);
        $this->assertSame('-24.000', (string) $rewardIssue->quantity, '1 CTN of B = 24 PCS issued.');

        $rewardInv = $this->inventoryAt($this->primary, $reward);
        $this->assertSame('0.000', (string) $rewardInv->restricted_qty);
        $this->assertSame('0.000', (string) $rewardInv->unrestricted_qty);
    }

    // ---- Idempotency (HTTP layer) ------------------------------------------------

    public function test_start_idempotent_retry_replays_without_double_issue(): void
    {
        $this->receiveStock('100');
        $delivery = $this->allocatedDelivery('30');
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);

        $payload = ['idempotency_key' => 'start-key-1'];

        $first = $this->actingAs($this->seller)->post('/shipments/'.$shipment->shipment_no.'/start', $payload);
        $first->assertRedirect();

        $retry = $this->actingAs($this->seller)->post('/shipments/'.$shipment->shipment_no.'/start', $payload);
        $retry->assertRedirect();

        $this->assertSame(1, InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count());
        $this->assertSame('70.000', (string) $this->inventoryAt()->unrestricted_qty, 'Restricted decremented exactly once.');
        $this->assertSame('0.000', (string) $this->inventoryAt()->restricted_qty);

        $retry->assertSessionHas('status', fn ($status) => str_contains($status, 'idempotent replay'));
    }

    // ---- Authorization ---------------------------------------------------------

    public function test_sales_employee_source_scope_enforced(): void
    {
        $this->receiveStock('100');
        $delivery = $this->allocatedDelivery('30');
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);

        // Another employee with NO assignment to the source.
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherSeller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $otherEmployee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $this->actingAs($otherSeller)->get('/shipments/'.$shipment->shipment_no)->assertForbidden();
        $this->actingAs($otherSeller)->post('/shipments/'.$shipment->shipment_no.'/start', ['idempotency_key' => 'x1'])->assertForbidden();
        $this->actingAs($otherSeller)->post('/shipments/'.$shipment->shipment_no.'/ready')->assertForbidden();

        $this->assertSame('READY', $shipment->fresh()->shipment_status->value);
        $this->assertSame(0, InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count());
    }

    public function test_foreign_company_admin_cannot_operate(): void
    {
        $this->receiveStock('100');
        $delivery = $this->allocatedDelivery('30');
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);

        $foreignAdmin = AppUser::factory()->companyAdmin()->create();

        $this->actingAs($foreignAdmin)->get('/shipments/'.$shipment->shipment_no)->assertForbidden();
        $this->actingAs($foreignAdmin)->post('/shipments/'.$shipment->shipment_no.'/deliveries', [
            'delivery_no' => $delivery->delivery_no,
        ])->assertForbidden();
    }

    public function test_sales_employee_can_operate_assigned_source_shipment(): void
    {
        $this->receiveStock('100');
        $delivery = $this->allocatedDelivery('30');
        $shipment = $this->draftShipment();
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);

        $this->actingAs($this->seller)->get('/shipments/'.$shipment->shipment_no)->assertOk();
        $this->actingAs($this->seller)->post('/shipments/'.$shipment->shipment_no.'/ready')->assertRedirect();
        $this->actingAs($this->seller)->post('/shipments/'.$shipment->shipment_no.'/start', ['idempotency_key' => 'own-1'])->assertRedirect();

        $this->assertSame('IN_TRANSIT', $shipment->fresh()->shipment_status->value);
    }
}
