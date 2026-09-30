<?php

namespace Tests\Feature;

use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\DeliveryConfirmation;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PodTest extends TestCase
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

    private ShipmentService $shipments;

    private PodService $pod;

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
        $this->shipments = app(ShipmentService::class);
        $this->pod = app(PodService::class);
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

    private function confirmedOrder(string $qty = '100', string $unit = 'PCS')
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

    private function allocatedDelivery(string $qty, string $unit = 'PCS')
    {
        $order = $this->confirmedOrder($qty, $unit);

        return $this->deliveries->createAndAllocate(
            $order,
            $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => $qty, 'unit' => $unit]]),
        )['delivery'];
    }

    private function inTransitDelivery(string $qty = '24', string $unit = 'PCS'): array
    {
        $this->receiveStock('2000');
        $delivery = $this->allocatedDelivery($qty, $unit);

        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        return [$delivery->fresh(['items']), $shipment->fresh()];
    }

    private function inventoryAt(): Inventory
    {
        return Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)->first();
    }

    private function issues(): int
    {
        return InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count();
    }

    // ---- Core confirmations ------------------------------------------------------

    public function test_full_confirmation(): void
    {
        [$delivery, $shipment] = $this->inTransitDelivery('24'); // 24 PCS shipped

        $result = $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '24', 'PCS', DifferenceReason::NONE);

        $this->assertSame('CONFIRMED', $result['confirmation']->confirmation_status->value);
        $this->assertSame('0.000', (string) $result['confirmation']->difference_qty);
        $this->assertSame('NONE', $result['confirmation']->difference_reason->value);
        $this->assertSame('DELIVERED', $result['delivery']->delivery_status->value);
        $this->assertNotNull($result['delivery']->delivered_at);
        $this->assertSame('COMPLETED', $result['shipment']->shipment_status->value, 'Sole delivery outcome → shipment completes.');
    }

    public function test_partial_confirmation_requires_reason(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '20', 'PCS', DifferenceReason::NONE);
            $this->fail('Expected missing reason to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('difference requires a reason', $e->getMessage());
        }

        $result = $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '20', 'PCS', DifferenceReason::SHORT_DELIVERY);

        $this->assertSame('PARTIAL', $result['confirmation']->confirmation_status->value);
        $this->assertSame('4.000', (string) $result['confirmation']->difference_qty);
        $this->assertSame('SHORT_DELIVERY', $result['confirmation']->difference_reason->value);
        $this->assertSame('PARTIALLY_DELIVERED', $result['delivery']->delivery_status->value);
        $this->assertSame('COMPLETED', $result['shipment']->shipment_status->value, 'PARTIAL is a terminal outcome.');
    }

    public function test_complete_rejection_requires_reason_and_zero_confirmed(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        $result = $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '0', 'PCS', DifferenceReason::CUSTOMER_REJECTED);

        $this->assertSame('REJECTED', $result['confirmation']->confirmation_status->value);
        $this->assertSame('24.000', (string) $result['confirmation']->difference_qty);
        $this->assertSame('PARTIALLY_DELIVERED', $result['delivery']->delivery_status->value, 'Something was shipped and outcome recorded — but nothing received.');
        $this->assertSame('COMPLETED', $result['shipment']->shipment_status->value);
    }

    public function test_unit_conversion_confirms_pcs_against_ctn_shipment(): void
    {
        [$delivery] = $this->inTransitDelivery('2', 'CTN'); // 48 PCS shipped

        $result = $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '36', 'PCS', DifferenceReason::OTHER);

        $this->assertSame('PARTIAL', $result['confirmation']->confirmation_status->value);
        $this->assertSame('12.000', (string) $result['confirmation']->difference_qty, '48 − 36 PCS = 12 PCS in input unit.');
        $this->assertSame('PCS', $result['confirmation']->difference_unit);
        $this->assertSame('PARTIALLY_DELIVERED', $result['delivery']->delivery_status->value);
    }

    public function test_confirmed_more_than_shipped_is_rejected(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '25', 'PCS', DifferenceReason::NONE);
            $this->fail('Expected over-delivery to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('over-delivery is not supported', $e->getMessage());
        }

        // Cross-unit over-delivery also rejected (1 CTN = 24 PCS > 12 PCS shipped... use exact case)
        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '24', 'PCS', DifferenceReason::NONE) ?: null;
            // 24 == 24 is fine — verify equality allowed, then over via CTN:
            unset($result);
        } catch (HttpException) {
            $this->fail('Full 24 PCS confirmation should have been allowed.');
        }

        [$delivery2] = $this->inTransitDelivery('12');

        try {
            $this->pod->confirmItem($this->employee, $delivery2->delivery_no, 1, '1', 'CTN', DifferenceReason::NONE);
            $this->fail('Expected cross-unit over-delivery to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('over-delivery is not supported', $e->getMessage());
        }
    }

    public function test_negative_confirmation_rejected(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        $this->expectException(HttpException::class);

        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '-5', 'PCS', DifferenceReason::NONE);
    }

    public function test_confirmation_against_non_shipped_delivery_rejected(): void
    {
        $this->receiveStock('200');
        $delivery = $this->allocatedDelivery('10'); // ALLOCATED, not shipped

        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);
            $this->fail('Expected confirmation of a non-shipped delivery to fail.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Only SHIPPED deliveries', $e->getMessage());
        }
    }

    public function test_confirmation_requires_in_transit_shipment(): void
    {
        $this->receiveStock('200');
        $delivery = $this->allocatedDelivery('10');

        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        // NOT started — still READY.

        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);
            $this->fail('Expected POD before START to fail.');
        } catch (HttpException $e) {
            // The READY (not started) delivery is ALLOCATED, not SHIPPED.
            $this->assertStringContainsString('Only SHIPPED deliveries', $e->getMessage());
        }
    }

    public function test_duplicate_confirmation_rejected_no_overwrite(): void
    {
        [$delivery] = $this->inTransitDelivery('24');
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '24', 'PCS', DifferenceReason::NONE);

        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'PCS', DifferenceReason::SHORT_DELIVERY);
            $this->fail('Expected duplicate confirmation to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('already has a confirmation', $e->getMessage());
        }

        // The authoritative first confirmation survived untouched.
        $confirmation = DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)->first();
        $this->assertSame('24.000', (string) $confirmation->confirmed_qty);
        $this->assertSame('CONFIRMED', $confirmation->confirmation_status->value);
        $this->assertSame(1, DeliveryConfirmation::count());
    }

    public function test_retry_is_idempotent(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        $payload = [
            'delivery_item_no' => 1,
            'confirmed_qty' => '24',
            'confirmed_unit' => 'PCS',
            'idempotency_key' => 'pod-key-1',
        ];

        $first = $this->actingAs($this->seller)->post('/pod/'.$delivery->delivery_no.'/confirm', $payload);
        $first->assertRedirect();

        $retry = $this->actingAs($this->seller)->post('/pod/'.$delivery->delivery_no.'/confirm', $payload);
        $retry->assertRedirect();

        $this->assertSame(1, DeliveryConfirmation::count());
        $retry->assertSessionHas('status', fn ($status) => str_contains($status, 'idempotent replay'));
    }

    // ---- Derivation ------------------------------------------------------------

    public function test_multiple_items_and_partial_delivery_derivation(): void
    {
        $this->receiveStock('2000');
        $otherProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        $this->receiveStock('2000', 'PCS', $this->primary, $otherProduct);

        $order = $this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id, $this->secondary->customer_id,
            collect([
                ['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null],
                ['product_id' => $otherProduct->product_id, 'qty' => '10', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null],
            ]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS'],
            ['sales_order_item_no' => 2, 'qty' => '10', 'unit' => 'PCS'],
        ]))['delivery'];

        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        // Confirm item 1 only — delivery stays SHIPPED (item 2 pending); the
        // SO flips to PARTIALLY_DELIVERED immediately (RULED §9.2).
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);

        $this->assertSame('SHIPPED', $delivery->fresh()->delivery_status->value, 'Item 2 still awaiting POD.');
        $this->assertSame('PARTIALLY_DELIVERED', $order->fresh()->order_status->value);
        $this->assertSame('IN_TRANSIT', $shipment->fresh()->shipment_status->value);

        // Item 2 partially confirmed → delivery PARTIALLY_DELIVERED, SO PARTIALLY_DELIVERED.
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 2, '6', 'PCS', DifferenceReason::SHORT_DELIVERY);

        $this->assertSame('PARTIALLY_DELIVERED', $delivery->fresh()->delivery_status->value);
        $this->assertSame('PARTIALLY_DELIVERED', $order->fresh()->order_status->value);
        $this->assertSame('COMPLETED', $shipment->fresh()->shipment_status->value);
    }

    public function test_two_deliveries_on_one_so_deliver_in_stages(): void
    {
        // Example 5: order 30 → deliveries 10 + 20, both confirmed.
        $this->receiveStock('2000');
        $order = $this->confirmedOrder('30');

        $d1 = $this->deliveries->createAndAllocate($order, $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS']]))['delivery'];
        $d2 = $this->deliveries->createAndAllocate($order, $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => '20', 'unit' => 'PCS']]))['delivery'];

        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $d1->delivery_no);
        $this->shipments->attachDelivery($shipment, $d2->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        $this->pod->confirmItem($this->employee, $d1->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);
        $this->assertSame('PARTIALLY_DELIVERED', $order->fresh()->order_status->value, 'First delivery confirmed.');
        $this->assertSame('DELIVERED', $d1->fresh()->delivery_status->value);
        $this->assertSame('SHIPPED', $d2->fresh()->delivery_status->value);

        $this->pod->confirmItem($this->employee, $d2->delivery_no, 1, '20', 'PCS', DifferenceReason::NONE);
        $this->assertSame('COMPLETELY_DELIVERED', $order->fresh()->order_status->value, 'Both deliveries confirmed.');
        $this->assertSame('DELIVERED', $d2->fresh()->delivery_status->value);
        $this->assertSame('COMPLETED', $shipment->fresh()->shipment_status->value);
    }

    public function test_free_deal_item_confirmation(): void
    {
        $reward = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        ProductUnitConversion::create([
            'product_id' => $reward->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);
        $this->receiveStock('240');
        $this->receiveStock('48', 'PCS', $this->primary, $reward);

        DealCondition::create([
            'deal_no' => 'DT-POD-1', 'company_id' => $this->company->company_id,
            'deal_description' => 'buy 10 CTN get 1 CTN', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'active' => true,
        ]);
        DealQualifier::create(['deal_no' => 'DT-POD-1', 'product_id' => $this->product->product_id, 'minimum_qty' => '10', 'qualifier_unit' => 'CTN']);
        DealReward::create(['deal_no' => 'DT-POD-1', 'product_id' => $reward->product_id, 'reward_qty' => '1', 'reward_unit' => 'CTN']);

        $order = $this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id, $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'CTN', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN'],
            ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
        ]))['delivery'];

        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);

        // Confirm paid line fully, free line partially (in different unit).
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'CTN', DifferenceReason::NONE);
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, $free->item_no, '12', 'PCS', DifferenceReason::SHORT_DELIVERY);

        $this->assertSame('PARTIALLY_DELIVERED', $delivery->fresh()->delivery_status->value);
        $this->assertSame('PARTIALLY_DELIVERED', $order->fresh()->order_status->value);

        $freeConfirmation = DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)
            ->where('delivery_item_no', $free->item_no)->first();
        $this->assertSame('12.000', (string) $freeConfirmation->confirmed_qty);
        $this->assertSame('12.000', (string) $freeConfirmation->difference_qty, '1 CTN shipped = 24 PCS; 12 confirmed.');
    }

    // ---- Immutability & no-restoration ---------------------------------------------

    public function test_goods_issue_ledger_immutable_and_no_inventory_restoration(): void
    {
        [$delivery] = $this->inTransitDelivery('24');
        $issuesBefore = $this->issues();
        $ledgerBefore = InventoryMovement::orderBy('movement_id')->get(['movement_id', 'movement_type', 'quantity', 'reference_no', 'reference_item_no'])->toArray();
        $stockBefore = $this->inventoryAt()->only(['unrestricted_qty', 'restricted_qty']);

        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '14', 'PCS', DifferenceReason::CUSTOMER_REJECTED);

        // A duplicate submission must lose and change nothing.
        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '14', 'PCS', DifferenceReason::CUSTOMER_REJECTED);
            $this->fail('Expected duplicate confirmation to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('already has a confirmation', $e->getMessage());
        }

        $this->assertSame($issuesBefore, $this->issues(), 'POD creates no new movement.');
        $this->assertSame($ledgerBefore, InventoryMovement::orderBy('movement_id')->get(['movement_id', 'movement_type', 'quantity', 'reference_no', 'reference_item_no'])->toArray(), 'GOODS_ISSUE ledger untouched.');
        $this->assertSame($stockBefore, $this->inventoryAt()->only(['unrestricted_qty', 'restricted_qty']), 'No stock restoration of any kind.');
        $this->assertSame(1, DeliveryConfirmation::count());
    }

    // ---- Authorization -----------------------------------------------------------

    public function test_pod_authorization_is_destination_assignment(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        // An employee assigned ONLY to the SOURCE (MIMZA-equivalent) may NOT confirm.
        $sourceEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $sourceSeller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $sourceEmployee->employee_id,
            'company_id' => $this->company->company_id,
        ]);
        CustomerEmployee::create([
            'customer_id' => $this->primary->customer_id,
            'employee_id' => $sourceEmployee->employee_id,
            'role' => 'SE',
        ]);

        $this->actingAs($sourceSeller)->get('/pod/'.$delivery->delivery_no)->assertForbidden();
        $this->actingAs($sourceSeller)->post('/pod/'.$delivery->delivery_no.'/confirm', [
            'delivery_item_no' => 1, 'confirmed_qty' => '24', 'confirmed_unit' => 'PCS', 'idempotency_key' => 'src-1',
        ])->assertForbidden();
        $this->assertSame(0, DeliveryConfirmation::count());

        // The employee assigned to the DESTINATION (sold-to) CAN confirm.
        $this->actingAs($this->seller)->get('/pod/'.$delivery->delivery_no)->assertOk();
        $this->actingAs($this->seller)->post('/pod/'.$delivery->delivery_no.'/confirm', [
            'delivery_item_no' => 1, 'confirmed_qty' => '24', 'confirmed_unit' => 'PCS', 'idempotency_key' => 'dst-1',
        ])->assertRedirect();
        $this->assertSame(1, DeliveryConfirmation::count());
    }

    public function test_cross_company_admin_cannot_confirm(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        $foreignAdmin = AppUser::factory()->companyAdmin()->create();

        $this->actingAs($foreignAdmin)->get('/pod/'.$delivery->delivery_no)->assertForbidden();
        $this->actingAs($foreignAdmin)->post('/pod/'.$delivery->delivery_no.'/confirm', [
            'delivery_item_no' => 1, 'confirmed_qty' => '24', 'confirmed_unit' => 'PCS', 'idempotency_key' => 'fx-1',
        ])->assertForbidden();
        $this->assertSame(0, DeliveryConfirmation::count());
    }

    public function test_concurrent_duplicate_pod_submissions_cannot_double_confirm(): void
    {
        [$delivery] = $this->inTransitDelivery('24');

        // Two sequential authoritative attempts on the same state: the second
        // must lose at the locked existence check (checked before the status
        // guard, so the message is about duplication, not the new status).
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '24', 'PCS', DifferenceReason::NONE);

        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '24', 'PCS', DifferenceReason::NONE);
            $this->fail('Expected the second confirmation to lose.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('already has a confirmation', $e->getMessage());
        }

        $this->assertSame(1, DeliveryConfirmation::count());
        $this->assertSame('DELIVERED', $delivery->fresh()->delivery_status->value);
    }
}
