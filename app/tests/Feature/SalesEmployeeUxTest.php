<?php

namespace Tests\Feature;

use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerEmployee;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\Delivery;
use App\Models\DeliveryConfirmation;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidence;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderRejectionReason;
use App\Models\StockCount;
use App\Services\DeliveryService;
use App\Services\FinanceService;
use App\Services\FjpRotationService;
use App\Services\InventoryService;
use App\Services\InvoiceImageService;
use App\Services\InvoicePartyService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\ProductUnitService;
use App\Services\SalesLifecycleService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ProvidesRejectionReasons;
use Tests\TestCase;

/**
 * Sales-employee UX redesign: the field workspace, customer registration, the
 * today lifecycle dashboard, the ongoing/completed order derivation, the
 * public invoice and field settlement recording.
 *
 * The tests drive PUBLIC entry points (HTTP routes and the services those
 * routes use); they never reach into private state to make a claim pass.
 */
class SalesEmployeeUxTest extends TestCase
{
    use DatabaseTransactions;
    use ProvidesRejectionReasons;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private AppUser $admin;

    private CustomerMaster $primary;

    private CustomerMaster $secondary;

    private ProductMaster $product;

    private SalesOrderService $orders;

    private DeliveryService $deliveries;

    private ShipmentService $shipments;

    private PodService $pod;

    private FinanceService $finance;

    private SalesLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $this->employee->employee_id,
        ]);
        $this->admin = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => null,
        ]);

        // Deterministic business names: the directories are ordered by name, so
        // random fixtures could sort differently between assertions.
        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)
            ->create(['business_name' => 'Aaa Primary Depot']);
        $this->secondary = CustomerMaster::factory()->forEmployee($this->employee)
            ->create(['business_name' => 'Aaa Secondary Shop']);
        $this->product = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);

        $conditionNo = 'PC-UX-'.$this->company->company_id;
        PriceCondition::create([
            'condition_price_no' => $conditionNo,
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => $conditionNo,
            'product_id' => $this->product->product_id,
            'price' => '1000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

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
        $this->finance = app(FinanceService::class);
        $this->lifecycle = app(SalesLifecycleService::class);
    }

    // ---- Helpers -----------------------------------------------------------

    private function newCustomer(?EmployeeMaster $employee = null): CustomerMaster
    {
        return CustomerMaster::factory()->forEmployee($employee ?? $this->employee)->create();
    }

    private function receiveStock(string $qty): void
    {
        app(InventoryService::class)->adjustPhysical(
            $this->employee, $this->primary->customer_id, $this->product->product_id, $qty, 'PCS', MovementType::GOODS_RECEIPT,
        );
    }

    private function inv(): Inventory
    {
        return Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)
            ->firstOrFail();
    }

    private function confirmedOrder(CustomerMaster $soldTo, string $qty, string $unit = 'CTN'): SalesOrder
    {
        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $result = $this->orders->confirm($order);

        if ($result['conflicts'] !== []) {
            $this->fail('Order confirmation unexpectedly conflicted: '.implode(' | ', $result['conflicts']));
        }

        return $order->fresh(['items']);
    }

    private function allocate(SalesOrder $order, string $qty, string $unit = 'CTN'): Delivery
    {
        return $this->deliveries->createAndAllocate(
            $order->fresh(['items']),
            $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => $qty, 'unit' => $unit, 'transit_qty' => '0']]),
        )['delivery'];
    }

    private function ship(Delivery $delivery): void
    {
        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);
    }

    private function pod(Delivery $delivery, string $confirmedQty, string $unit = 'CTN', DifferenceReason $reason = DifferenceReason::NONE, ?DifferenceDisposition $disposition = null): void
    {
        $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, $confirmedQty, $unit, $reason, null, $disposition);
    }

    /**
     * Deliver + accept `qty` CTN: SO → allocation → shipment → POD → invoice.
     *
     * An unpaid invoice blocks further confirmation for the SAME sold-to
     * debtor, so each call uses its own customer unless one is supplied.
     */
    private function acceptAndInvoice(string $qty = '10', string $unit = 'CTN', ?CustomerMaster $customer = null): array
    {
        $this->receiveStock('2400');

        $order = $this->confirmedOrder($customer ?? $this->newCustomer(), $qty, $unit);
        $delivery = $this->allocate($order, $qty, $unit);
        $this->ship($delivery);
        $this->pod($delivery, $qty, $unit);

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();

        return [$order, $invoice];
    }

    // ---- 1-4: field workspace scoping --------------------------------------

    public function test_primary_tab_is_scoped_to_the_employees_assignments(): void
    {
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $theirPrimary = CustomerMaster::factory()->primary()->forEmployee($otherEmployee)->create();

        $this->actingAs($this->seller)->get(route('primary.index'))
            ->assertOk()
            ->assertSee($this->primary->business_name)
            ->assertDontSee($theirPrimary->business_name)
            // The Secondary directory is a different context tab.
            ->assertDontSee($this->secondary->business_name);
    }

    public function test_secondary_directory_is_scoped_searchable_and_loads_more(): void
    {
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $theirs = CustomerMaster::factory()->forEmployee($otherEmployee)->create(['business_name' => 'Foreign Trader']);

        $many = collect(range(1, 18))->map(fn ($i) => CustomerMaster::factory()
            ->forEmployee($this->employee)
            ->create(['business_name' => sprintf('Shop %02d', $i)]));

        $this->actingAs($this->seller)->get(route('secondary.index'))
            ->assertOk()
            ->assertSee($this->secondary->business_name)
            ->assertDontSee($theirs->business_name)
            ->assertSee('per_page=30', false); // load-more grows the list in place

        $this->actingAs($this->seller)->get(route('secondary.index', ['q' => 'Shop 07']))
            ->assertOk()
            ->assertSee('Shop 07')
            ->assertDontSee('Shop 08');

        $this->actingAs($this->seller)->get(route('secondary.index', ['per_page' => 30]))
            ->assertOk()
            ->assertSee($many->last()->business_name);
    }

    public function test_field_workspace_is_unavailable_to_administrators(): void
    {
        foreach (['primary.index', 'secondary.index', 'more.index', 'secondary.create'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertForbidden();
        }
    }

    // ---- 5-8: registration, GPS, phone, FJP --------------------------------

    public function test_sales_employee_registers_customer_with_assignment_and_preferred_visit(): void
    {
        $week = app(FjpRotationService::class)->rotationWeek(Carbon::today());

        $response = $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Mama Chi Stores',
            'contact_person' => 'Chi',
            'phone' => '08012345678',
            'gps_latitude' => '6.5243790',
            'gps_longitude' => '3.3792050',
            'gps_accuracy' => '12',
            'address' => '12 Market Road',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'postal_code' => '100001',
            'preferred_visits' => [
                ['preferred_week' => $week, 'preferred_day' => (int) Carbon::today()->dayOfWeek],
            ],
        ]);

        $customer = CustomerMaster::where('business_name', 'Mama Chi Stores')->firstOrFail();

        $response->assertRedirect(route('visits.customer', $customer->customer_id));

        // Server-derived identity: canonical phone, fixed type/country, GPS kept.
        $this->assertSame('+2348012345678', $customer->phone_canonical);
        $this->assertSame('+2348012345678', $customer->phone_number);
        $this->assertSame('SECONDARY', $customer->customer_type->value);
        $this->assertSame('Nigeria', $customer->country);
        $this->assertTrue((bool) $customer->active);
        $this->assertSame('6.5243790', (string) $customer->gps_latitude);
        $this->assertSame('3.3792050', (string) $customer->gps_longitude);

        // Assignment created for the REGISTERING employee, on the existing model.
        $this->assertTrue(CustomerEmployee::where('customer_id', $customer->customer_id)
            ->where('employee_id', $this->employee->employee_id)->exists());

        // Preferred visit uses the project's one scheduling model (customer_fjp).
        $this->assertTrue(CustomerFjp::where('customer_id', $customer->customer_id)
            ->where('employee_id', $this->employee->employee_id)
            ->where('preferred_week', $week)
            ->exists());

        // Registration is NOT a visit: no attendance may be manufactured.
        $this->assertSame(0, CustomerVisitAttendance::where('customer_id', $customer->customer_id)->count());
    }

    public function test_registration_ignores_crafted_server_controlled_fields(): void
    {
        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Spoofed Store',
            'phone' => '+234 801 999 8888',
            'gps_latitude' => '6.5',
            'gps_longitude' => '3.3',
            // Crafted attempts to control server-owned values:
            'customer_id' => 'CUS-HACKED',
            'customer_type' => 'PRIMARY',
            'country' => 'Ghana',
            'active' => 0,
            'phone_canonical' => '+2338000000000',
            'parent_customer_id' => $this->primary->customer_id,
        ])->assertRedirect();

        $created = CustomerMaster::where('business_name', 'Spoofed Store')->firstOrFail();

        $this->assertNotSame('CUS-HACKED', $created->customer_id);
        $this->assertSame('SECONDARY', $created->customer_type->value);
        $this->assertSame('Nigeria', $created->country);
        $this->assertTrue((bool) $created->active);
        $this->assertNull($created->parent_customer_id);
        $this->assertSame('+2348019998888', $created->phone_canonical, 'Country dial code is not client-chosen.');
    }

    public function test_registration_requires_device_coordinates(): void
    {
        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'No GPS Store',
            'phone' => '08012223333',
        ])->assertSessionHasErrors('gps_latitude');

        $this->assertSame(0, CustomerMaster::where('business_name', 'No GPS Store')->count());

        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Bad GPS Store',
            'phone' => '08012223334',
            'gps_latitude' => '999',
            'gps_longitude' => '3.3',
        ])->assertSessionHasErrors('gps_latitude');
    }

    public function test_phone_number_must_have_the_configured_national_length(): void
    {
        $base = ['gps_latitude' => '6.5', 'gps_longitude' => '3.3'];

        foreach ([
            '080123456' => 'nine national digits',      // local part too short
            '080123456789' => 'twelve national digits', // local part too long
            'not-a-number' => 'no digits at all',
            '080123456a' => 'trailing letter',
        ] as $phone => $why) {
            $this->actingAs($this->seller)->post(route('secondary.store'), array_merge($base, [
                'business_name' => 'Bad Number Store',
                'phone' => $phone,
            ]))->assertSessionHasErrors('phone');

            $this->assertSame(0, CustomerMaster::where('business_name', 'Bad Number Store')->count(), $why);
        }

        // Control: the exact national length is accepted.
        $this->actingAs($this->seller)->post(route('secondary.store'), array_merge($base, [
            'business_name' => 'Good Number Store',
            'phone' => '08012345678',
        ]))->assertRedirect();

        $this->assertSame('+2348012345678', CustomerMaster::where('business_name', 'Good Number Store')->value('phone_canonical'));
    }

    public function test_duplicate_phone_is_rejected_across_equivalent_spellings(): void
    {
        // Legacy row: no canonical column, national spelling.
        $existing = CustomerMaster::factory()->forEmployee($this->employee)->create([
            'business_name' => 'Existing Mama Chi',
            'phone_number' => '0801 234 5678',
            'phone_canonical' => null,
        ]);

        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Duplicate Mama Chi',
            'phone' => '+2348012345678',
            'gps_latitude' => '6.5',
            'gps_longitude' => '3.3',
        ])->assertSessionHasErrors('phone')
            ->assertSessionHas('duplicate_customer');

        $this->assertSame(0, CustomerMaster::where('business_name', 'Duplicate Mama Chi')->count());
        $this->assertSame(1, CustomerMaster::where('customer_id', $existing->customer_id)->count());
    }

    public function test_duplicate_phone_is_backstopped_by_database_uniqueness(): void
    {
        CustomerMaster::create([
            'customer_id' => 'CUS-999999',
            'business_name' => 'Hidden Duplicate',
            'customer_type' => 'SECONDARY',
            'phone_number' => 'n/a',
            'phone_canonical' => '+2348017778888',
            'active' => true,
        ]);

        // Registration of an equivalent spelling is rejected up front.
        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Racing Duplicate',
            'phone' => '2348017778888',
            'gps_latitude' => '6.5',
            'gps_longitude' => '3.3',
        ])->assertSessionHasErrors('phone');

        $this->assertSame(0, CustomerMaster::where('business_name', 'Racing Duplicate')->count());

        // And the DATABASE itself refuses a second row with the same canonical
        // number — the backstop for two registrations racing each other.
        $rejected = false;

        try {
            CustomerMaster::create([
                'customer_id' => 'CUS-999998',
                'business_name' => 'Sneaky Duplicate',
                'customer_type' => 'SECONDARY',
                'phone_canonical' => '+2348017778888',
                'active' => true,
            ]);
        } catch (QueryException $e) {
            $rejected = (int) $e->errorInfo[1] === 1062;
        }

        $this->assertTrue($rejected, 'uq_cm_phone_canonical must reject a duplicate canonical phone.');
    }

    public function test_registered_customer_with_todays_preferred_visit_joins_todays_fjp(): void
    {
        $week = app(FjpRotationService::class)->rotationWeek(Carbon::today());

        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Today Trader',
            'phone' => '08033344444',
            'gps_latitude' => '6.5',
            'gps_longitude' => '3.3',
            'preferred_visits' => [
                ['preferred_week' => $week, 'preferred_day' => (int) Carbon::today()->dayOfWeek],
            ],
        ]);

        $customer = CustomerMaster::where('business_name', 'Today Trader')->firstOrFail();

        $this->actingAs($this->seller)->get(route('visits.today'))
            ->assertOk()
            ->assertSee('Today Trader');

        // Still no attendance: the employee must check in explicitly.
        $this->assertSame(0, CustomerVisitAttendance::where('customer_id', $customer->customer_id)->count());

        $this->actingAs($this->seller)->get(route('visits.customer', $customer->customer_id))
            ->assertOk()
            ->assertSee('Today Trader');

        // A different rotation week is NOT on today's plan.
        $otherWeek = $week === 4 ? 1 : $week + 1;

        $this->actingAs($this->seller)->post(route('secondary.store'), [
            'business_name' => 'Future Trader',
            'phone' => '08033355555',
            'gps_latitude' => '6.5',
            'gps_longitude' => '3.3',
            'preferred_visits' => [
                ['preferred_week' => $otherWeek, 'preferred_day' => (int) Carbon::today()->dayOfWeek],
            ],
        ]);

        $this->actingAs($this->seller)->get(route('visits.today'))
            ->assertOk()
            ->assertDontSee('Future Trader');
    }

    // ---- 2: today's SKU lifecycle ------------------------------------------

    public function test_todays_lifecycle_buckets_follow_the_physical_progression(): void
    {
        $this->receiveStock('2400');
        $orderA = $this->confirmedOrder($this->secondary, '6', 'CTN');

        // ORANGE: today's demand, nothing allocated yet (6 CTN = 144 PCS).
        $state = $this->lifecycle->todayLifecycle($this->employee);
        $row = $state['rows'][$this->product->product_id];

        $this->assertSame('144.000', $row['open_basic']);
        $this->assertSame('6.000', $row['open_display']);
        $this->assertSame('0.000', $row['allocated_basic']);
        $this->assertSame('0.000', $row['delivered_unpaid_basic']);
        $this->assertSame('0.000', $row['delivered_paid_basic']);

        // YELLOW: allocated into a delivery, not yet accepted (all 6 CTN).
        $delivery = $this->allocate($orderA, '6', 'CTN');
        $row = $this->lifecycle->todayLifecycle($this->employee)['rows'][$this->product->product_id];

        $this->assertSame('0.000', $row['open_display']);
        $this->assertSame('6.000', $row['allocated_display']);
        $this->assertSame('0.000', $row['delivered_unpaid_basic']);

        // BLUE: accepted (invoiced) but unpaid.
        $this->ship($delivery);
        $this->pod($delivery, '6', 'CTN');

        $row = $this->lifecycle->todayLifecycle($this->employee)['rows'][$this->product->product_id];

        $this->assertSame('0.000', $row['open_display']);
        $this->assertSame('0.000', $row['allocated_basic']);
        $this->assertSame('6.000', $row['delivered_unpaid_display']);
        $this->assertSame('0.000', $row['delivered_paid_basic']);

        // No double counting: the buckets always partition the ordered quantity.
        $this->assertSame(
            (float) $row['ordered_display'],
            (float) $row['open_display'] + (float) $row['allocated_display'] + (float) $row['delivered_unpaid_display'] + (float) $row['delivered_paid_display'],
        );

        // GREEN: settled in full through the existing finance path.
        $invoice = Invoice::where('sales_order_no', $orderA->sales_order_no)->firstOrFail();
        $payment = $this->finance->createPayment(
            $this->company->company_id, $invoice->customer_id, 'CASH_AT_PRIMARY', '6000.00', null,
        );
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment, collect([$invoice->invoice_no => '6000.00']));

        // A second, still-open order for the same employee (the debt cleared
        // with the settlement, so it confirms normally): its demand stays ORANGE
        // and never leaks into the delivered buckets.
        $orderB = $this->confirmedOrder($this->secondary, '4', 'CTN');

        $row = $this->lifecycle->todayLifecycle($this->employee)['rows'][$this->product->product_id];

        $this->assertSame('6.000', $row['delivered_paid_display']);
        $this->assertSame('0.000', $row['delivered_unpaid_basic']);
        $this->assertSame('0.000', $row['allocated_basic']);
        $this->assertSame('4.000', $row['open_display'], 'Unallocated demand stays open.');
        $this->assertSame('10.000', $row['ordered_display'], 'Both orders belong to today\'s demand.');
        $this->assertSame(2, count($row['orders']));

        // The Home renders the same SKU with its lifecycle bar + labels.
        $this->actingAs($this->seller)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($this->product->product_description)
            ->assertSee('Delivered · paid');
    }

    public function test_todays_pipeline_groups_by_order_unit_and_conserves_the_demand(): void
    {
        /*
         * Deterministic fixture (Home grouping by ORDER QUANTITY UNIT):
         *
         *   CTN section — total demand 100 CTN
         *     20 CTN  open demand (confirmed today, nothing allocated)
         *     20 CTN  allocated to a delivery, not yet POD-confirmed
         *     20 CTN  POD accepted + invoiced, still UNPAID
         *     25 CTN  POD accepted + invoiced, financially SETTLED
         *     15 CTN  terminally rejected and NOT delivered
         *
         *   PCS section — one line captured in PCS: 12 PCS open
         *
         * Expected: CTN demand stays CTN demand and PCS demand stays PCS demand
         * (nothing is converted into one global display unit), the two sections
         * never share a bucket, and inside each unit the mutually exclusive
         * buckets account for the demand exactly once.
         */
        $this->receiveStock('2412'); // 100 CTN + 12 PCS

        $open = $this->confirmedOrder($this->newCustomer(), '20', 'CTN');
        $allocated = $this->confirmedOrder($this->newCustomer(), '20', 'CTN');
        $unpaid = $this->confirmedOrder($this->newCustomer(), '20', 'CTN');
        $settled = $this->confirmedOrder($this->newCustomer(), '25', 'CTN');
        $rejected = $this->confirmedOrder($this->newCustomer(), '15', 'CTN');

        // 20 CTN allocated, never shipped (still awaiting dispatch).
        $this->allocate($allocated, '20', 'CTN');

        // 20 CTN accepted → invoiced, still outstanding.
        $unpaidDelivery = $this->allocate($unpaid, '20', 'CTN');
        $this->ship($unpaidDelivery);
        $this->pod($unpaidDelivery, '20', 'CTN');

        // 25 CTN accepted → invoiced → settled.
        $settledDelivery = $this->allocate($settled, '25', 'CTN');
        $this->ship($settledDelivery);
        $this->pod($settledDelivery, '25', 'CTN');

        $settledInvoice = Invoice::where('sales_order_no', $settled->sales_order_no)->firstOrFail();
        $payment = $this->finance->createPayment(
            $this->company->company_id, $settledInvoice->customer_id, 'CASH_AT_PRIMARY', (string) $settledInvoice->invoice_amount, null,
        );
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment, collect([$settledInvoice->invoice_no => (string) $settledInvoice->invoice_amount]));

        // 15 CTN terminally rejected: never delivered, so it can never be open.
        $this->orders->rejectItem(
            $rejected->fresh(['items'])->items->first(),
            $this->reason(SalesOrderRejectionReason::CODE_UNAVAILABLE_STOCK),
            $this->employee,
        );

        // A PCS line stays PCS demand — it is never folded into the CTN pipeline.
        $this->confirmedOrder($this->newCustomer(), '12', 'PCS');

        $state = $this->lifecycle->todayLifecycle($this->employee);
        $groups = collect($state['unit_groups'])->keyBy('unit');

        $this->assertTrue($groups->has('CTN'), 'CTN demand gets its own section.');
        $this->assertTrue($groups->has('PCS'), 'PCS demand gets its own section.');

        $ctn = $groups['CTN'];

        $this->assertSame('20.000', $ctn['open'], 'Orange: open / not allocated.');
        $this->assertSame('20.000', $ctn['allocated'], 'Yellow: allocated / processing.');
        $this->assertSame('20.000', $ctn['delivered_unpaid'], 'Blue: delivered / unpaid.');
        $this->assertSame('25.000', $ctn['delivered_paid'], 'Green: delivered / paid.');
        $this->assertSame('15.000', $ctn['rejected'], 'Red: rejected / not delivered.');
        $this->assertSame('100.000', $ctn['total'], 'The CTN fixture is exactly 100 CTN of demand.');
        $this->assertSame('100.000', $ctn['bucket_sum'], 'The five buckets partition the demand.');
        $this->assertTrue($ctn['balanced'], 'Conservation: 20 + 20 + 20 + 25 + 15 = 100 CTN.');

        $this->assertSame('12.000', $groups['PCS']['total'], 'The PCS line is PCS demand, never CTN.');
        $this->assertSame('12.000', $groups['PCS']['open']);
        $this->assertTrue($groups['PCS']['balanced']);
        $this->assertSame('0.000', $groups['PCS']['rejected'], 'Units never share a bucket.');

        // ---- the Home must RENDER the same per-unit pipelines -----------------
        $home = $this->actingAs($this->seller)->get(route('dashboard'))->assertOk();
        $html = $home->getContent();

        $ctnRow = $this->pipelineRow($html, 'CTN');

        $this->assertSame('20', $ctnRow['order-open'], 'LEFT: open / not allocated quantity.');
        $this->assertSame('20', $ctnRow['allocated'], 'Yellow: allocated / processing.');
        $this->assertSame('20', $ctnRow['unpaid'], 'Blue: delivered / unpaid.');
        $this->assertSame('25', $ctnRow['paid'], 'Green: delivered / paid.');
        $this->assertSame('15', $ctnRow['rejected'], 'Red: rejected / not delivered.');
        $this->assertSame('85', $ctnRow['lifecycle-total'], 'Active lifecycle (20+20+20+25) excludes terminal rejection.');
        $this->assertSame('100', $ctnRow['demand-total'], 'The track spans the whole demand, rejection included.');

        // ...and the bar widths ARE those quantities (proportional, not decorative).
        $this->assertSame('20', $ctnRow['order-open-share']);
        $this->assertSame('20', $ctnRow['allocated-share']);
        $this->assertSame('20', $ctnRow['unpaid-share']);
        $this->assertSame('25', $ctnRow['paid-share']);
        $this->assertSame('15', $ctnRow['rejected-share']);

        $markup = $this->pipelineMarkup($html, 'CTN');

        $this->assertStringContainsString('data-unit-group="CTN"', $markup, 'The section is keyed by the order quantity unit.');
        $this->assertStringContainsString('data-unit-total="100"', $markup);
        $this->assertStringContainsString('data-unit-balanced="1"', $markup, 'Conservation is asserted, not assumed.');
        $this->assertStringContainsString('style="width: 20%"', $markup, 'Open segment = 20 of the 100 CTN demand.');
        $this->assertStringContainsString('style="width: 65%"', $markup, 'Processing group = 20 + 20 + 25 = 65 of 100.');
        $this->assertSame(2, substr_count($markup, 'style="width: 30.769%"'), 'Allocated and unpaid are 20 of the 65 processing CTN each.');
        $this->assertStringContainsString('style="width: 38.462%"', $markup, 'Paid = 25 of the 65 processing CTN.');
        $this->assertStringContainsString('style="width: 15%"', $markup, 'Rejected segment = 15 of 100.');

        // Colour is never the only indicator: every bucket is spelled out.
        foreach ([
            'Open · not allocated',
            'Allocated',
            'Delivered · unpaid',
            'Delivered · paid',
            'Rejected · not delivered',
        ] as $label) {
            $this->assertStringContainsString($label, $markup, 'The legend spells out "'.$label.'".');
        }

        $this->assertStringContainsString('CTN in play today', $markup);

        $pcsRow = $this->pipelineRow($html, 'PCS');
        $this->assertSame('12', $pcsRow['order-open'], 'PCS demand is rendered in its own section.');
        $this->assertSame('0', $pcsRow['rejected']);
        $this->assertSame('0', $pcsRow['allocated']);

        // The old order-count headline is gone: the pipeline speaks in quantities.
        $home->assertDontSee('order(s) confirmed today');
    }

    public function test_dashboard_red_rejected_quantity_is_never_also_open(): void
    {
        /*
         * Item O: "rejected" is TERMINAL and NOT delivered. A rejected line must
         * leave the orange bucket entirely — the two buckets are mutually
         * exclusive and their sum is still the demand.
         */
        $this->receiveStock('2400');

        $this->confirmedOrder($this->newCustomer(), '10', 'CTN'); // stays open

        $killed = $this->confirmedOrder($this->newCustomer(), '15', 'CTN');
        $this->orders->rejectItem(
            $killed->fresh(['items'])->items->first(),
            $this->reason(),
            $this->employee,
        );

        $group = collect($this->lifecycle->todayLifecycle($this->employee)['unit_groups'])->keyBy('unit')['CTN'];

        $this->assertSame('10.000', $group['open'], 'Only the genuinely open 10 CTN are orange.');
        $this->assertSame('15.000', $group['rejected'], 'The rejected 15 CTN is red.');
        $this->assertSame('25.000', $group['total']);
        $this->assertTrue($group['balanced'], 'Open + rejected still conserve the demand.');

        $row = $this->pipelineRow($this->actingAs($this->seller)->get(route('dashboard'))->assertOk()->getContent(), 'CTN');

        $this->assertSame('10', $row['order-open'], 'Red quantity is NOT also orange.');
        $this->assertSame('15', $row['rejected']);
        $this->assertSame('40', $row['order-open-share'], '10 of the 25 CTN demand is orange.');
        $this->assertSame('60', $row['rejected-share'], '15 of the 25 CTN demand is red.');
    }

    public function test_todays_lifecycle_is_employee_company_and_date_scoped(): void
    {
        $this->receiveStock('2400');

        $mine = $this->confirmedOrder($this->secondary, '10', 'CTN');

        // Another employee of the SAME company: not this employee's dashboard.
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherPrimary = CustomerMaster::factory()->primary()->forEmployee($otherEmployee)->create();
        $otherCustomer = $this->newCustomer($otherEmployee);

        $theirOrder = $this->orders->createDraft(
            $otherEmployee,
            $otherPrimary->customer_id,
            $otherPrimary->customer_id,
            $otherCustomer->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '5', 'unit' => 'CTN']]),
        )['order'];
        $this->orders->confirm($theirOrder);

        // A draft of my own is demand not yet captured.
        $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '7', 'unit' => 'CTN']]),
        );

        $state = $this->lifecycle->todayLifecycle($this->employee);

        $this->assertSame('240.000', $state['rows'][$this->product->product_id]['ordered_basic'], 'Only own confirmed demand counts.');
        $this->assertSame(1, $state['orders']->count());

        // Yesterday's confirmations are not today's selling.
        $mine->forceFill(['confirmed_at' => Carbon::yesterday()->setTime(10, 0)])->save();

        $yesterday = $this->lifecycle->todayLifecycle($this->employee, Carbon::yesterday());
        $this->assertSame('240.000', $yesterday['rows'][$this->product->product_id]['ordered_basic']);
        $this->assertSame([], $this->lifecycle->todayLifecycle($this->employee)['rows']);

        // An empty day explains itself instead of rendering an empty pipeline.
        $this->actingAs($this->seller)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing confirmed yet today.');
    }

    public function test_lifecycle_uses_existing_ctn_conversion_and_degrades_without_one(): void
    {
        $this->receiveStock('2400');
        $this->confirmedOrder($this->secondary, '10', 'CTN');

        // A second product with NO CTN conversion row.
        $looseProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-UX-'.$this->company->company_id,
            'product_id' => $looseProduct->product_id,
            'price' => '10.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $looseOrder = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $looseProduct->product_id, 'qty' => '50', 'unit' => 'PCS']]),
        )['order'];
        $this->orders->confirm($looseOrder);

        // A product whose BASIC unit is already CTN needs no conversion row.
        $ctnBasic = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'CTN']);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-UX-'.$this->company->company_id,
            'product_id' => $ctnBasic->product_id,
            'price' => '500.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $ctnOrder = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $ctnBasic->product_id, 'qty' => '3', 'unit' => 'CTN']]),
        )['order'];
        $this->orders->confirm($ctnOrder);

        $state = $this->lifecycle->todayLifecycle($this->employee);

        $ctnRow = $state['rows'][$this->product->product_id];
        $this->assertSame('CTN', $ctnRow['display_unit']);
        $this->assertSame('10.000', $ctnRow['ordered_display'], 'Basic 240 ÷ 24 per CTN.');
        $this->assertSame('240.000', $ctnRow['ordered_basic']);

        $nativeCtnRow = $state['rows'][$ctnBasic->product_id];
        $this->assertSame('CTN', $nativeCtnRow['display_unit'], 'Basic unit CTN needs no conversion row.');
        $this->assertSame('3.000', $nativeCtnRow['ordered_display']);
        $this->assertNull($nativeCtnRow['note']);
        // 10 CTN (converted from 240 PCS) + 3 CTN native; the PCS-only line is
        // excluded because it cannot legitimately become CTN.
        $this->assertSame('13.000', $state['totals']['ordered'], 'The CTN-basic product contributes to the totals.');
        $this->assertNotContains($ctnBasic->product_id, $state['unconvertible']);

        $looseRow = $state['rows'][$looseProduct->product_id];
        $this->assertNull($looseRow['display_unit'], 'No CTN conversion exists — none is invented.');
        $this->assertNull($looseRow['ordered_display']);
        $this->assertSame('50.000', $looseRow['ordered_basic']);
        $this->assertNotNull($looseRow['note']);
        $this->assertContains($looseProduct->product_id, $state['unconvertible']);

        // The Home no longer converts everything to one display unit: a line
        // captured in PCS is grouped under PCS and never mixed into the CTN
        // section, so no CTN quantity is invented for it.
        $groups = collect($state['unit_groups'])->keyBy('unit');

        $this->assertTrue($groups->has('PCS'), 'PCS demand keeps its own section.');
        $this->assertSame('50.000', $groups['PCS']['total'], 'The 50 PCS line stays PCS demand.');
        $this->assertSame('13.000', $groups['CTN']['total'], 'The CTN section holds CTN-order demand only (10 + 3).');

        $this->actingAs($this->seller)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('PCS in play today');
    }

    public function test_partially_settled_invoice_does_not_fabricate_paid_quantities(): void
    {
        [$order, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $payment = $this->finance->createPayment(
            $this->company->company_id, $invoice->customer_id, 'BANK_TRANSFER_TO_PRIMARY', '2500.00', 'TRX-1',
        );
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment, collect([$invoice->invoice_no => '2500.00']));

        $state = $this->lifecycle->todayLifecycle($this->employee);
        $row = $state['rows'][$this->product->product_id];

        $this->assertSame('0.000', $row['delivered_paid_basic'], 'A partially paid multi-SKU invoice yields no paid quantity.');
        $this->assertSame('10.000', $row['delivered_unpaid_display']);
        $this->assertNotEmpty($state['partial_invoices']);
        $this->assertSame('2500.00', $state['partial_invoices'][0]['settled']);
        $this->assertSame('7500.00', $state['partial_invoices'][0]['outstanding']);

        $this->actingAs($this->seller)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Partially settled invoices');
    }

    public function test_lifecycle_respects_the_employee_product_scope(): void
    {
        $this->receiveStock('2400');
        $this->confirmedOrder($this->secondary, '10', 'CTN');

        // Empty scope = all company products (default): the row is visible.
        $this->assertArrayHasKey($this->product->product_id, $this->lifecycle->todayLifecycle($this->employee)['rows']);

        // Now restrict the employee to a DIFFERENT product.
        $otherProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        EmployeeProduct::create(['employee_id' => $this->employee->employee_id, 'product_id' => $otherProduct->product_id]);

        $state = $this->lifecycle->todayLifecycle($this->employee);

        $this->assertArrayNotHasKey($this->product->product_id, $state['rows']);
        $this->assertContains($this->product->product_id, $state['out_of_scope']);
    }

    // ---- 11-12: order category derivation ----------------------------------

    public function test_order_derivation_follows_the_operational_rule(): void
    {
        $this->receiveStock('4800');

        // A: 30 ordered, 30 delivered + invoiced, payment outstanding → ONGOING.
        $orderA = $this->confirmedOrder($this->secondary, '30', 'CTN');
        $deliveryA = $this->allocate($orderA, '30', 'CTN');
        $this->ship($deliveryA);
        $this->pod($deliveryA, '30', 'CTN');

        $analysisA = $this->lifecycle->orderAnalysis($orderA->fresh());
        $this->assertSame('ONGOING', $analysisA['state']);
        $this->assertContains('Payment outstanding', $analysisA['reasons']);

        // B: 30 ordered, 10 delivered + paid, 20 still fulfilable → ONGOING.
        $orderB = $this->confirmedOrder($this->newCustomer(), '30', 'CTN');
        $deliveryB = $this->allocate($orderB, '10', 'CTN');
        $this->ship($deliveryB);
        $this->pod($deliveryB, '10', 'CTN');
        $this->settle($orderB);

        $analysisB = $this->lifecycle->orderAnalysis($orderB->fresh());
        $this->assertSame('ONGOING', $analysisB['state']);
        $this->assertContains('Open demand', $analysisB['reasons']);
        $this->assertSame('480.000', $analysisB['metrics']['remaining_fulfillable_basic'], '20 CTN remain fulfillable.');

        // C: 30 ordered, 10 delivered + paid, 20 terminally rejected → COMPLETED.
        $orderC = $this->confirmedOrder($this->newCustomer(), '30', 'CTN');
        $deliveryC = $this->allocate($orderC, '10', 'CTN');
        $this->ship($deliveryC);
        $this->pod($deliveryC, '10', 'CTN');
        $this->orders->rejectItem($orderC->fresh(['items'])->items->first(), $this->reason(), $this->employee);
        $this->settle($orderC);

        $analysisC = $this->lifecycle->orderAnalysis($orderC->fresh());
        $this->assertSame('COMPLETED', $analysisC['state'], 'Rejected remainder leaves nothing to process.');
        $this->assertSame('0.000', $analysisC['metrics']['remaining_fulfillable_basic']);

        // D: 30 ordered, 0 delivered, 30 terminally rejected → COMPLETED.
        $orderD = $this->confirmedOrder($this->newCustomer(), '30', 'CTN');
        $this->orders->rejectItem($orderD->fresh(['items'])->items->first(), $this->reason(), $this->employee);

        $analysisD = $this->lifecycle->orderAnalysis($orderD->fresh());
        $this->assertSame('COMPLETED', $analysisD['state']);
        $this->assertNull($orderD->fresh()->invoice()->first(), 'Nothing invoiceable → no monetary invoice.');

        // A draft is work in progress, not a completed order.
        $draft = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '1', 'unit' => 'CTN']]),
        )['order'];

        $this->assertSame('ONGOING', $this->lifecycle->orderAnalysis($draft)['state']);
    }

    public function test_order_screen_tabs_split_derived_categories_and_stay_scoped(): void
    {
        $this->receiveStock('2400');

        $ongoing = $this->confirmedOrder($this->secondary, '10', 'CTN');

        $done = $this->confirmedOrder($this->newCustomer(), '5', 'CTN');
        $this->orders->rejectItem($done->fresh(['items'])->items->first(), $this->reason(), $this->employee);

        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherPrimary = CustomerMaster::factory()->primary()->forEmployee($otherEmployee)->create();
        $otherOrder = $this->orders->createDraft(
            $otherEmployee,
            $otherPrimary->customer_id,
            $otherPrimary->customer_id,
            $this->newCustomer($otherEmployee)->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '2', 'unit' => 'CTN']]),
        )['order'];
        $this->orders->confirm($otherOrder);

        $this->actingAs($this->seller)->get(route('orders.index', ['tab' => 'ONGOING']))
            ->assertOk()
            ->assertSee($ongoing->sales_order_no)
            ->assertDontSee($done->sales_order_no)
            ->assertDontSee($otherOrder->sales_order_no);

        $this->actingAs($this->seller)->get(route('orders.index', ['tab' => 'COMPLETED']))
            ->assertOk()
            ->assertSee($done->sales_order_no)
            ->assertDontSee($ongoing->sales_order_no);

        // Search + date window.
        $this->actingAs($this->seller)->get(route('orders.index', ['q' => $ongoing->sales_order_no]))
            ->assertOk()
            ->assertSee($ongoing->sales_order_no)
            ->assertDontSee($done->sales_order_no);

        $ongoing->forceFill(['order_date' => Carbon::yesterday()])->save();

        $this->actingAs($this->seller)->get(route('orders.index', ['tab' => 'ONGOING']))
            ->assertOk()
            ->assertDontSee($ongoing->sales_order_no, false);

        $this->actingAs($this->seller)->get(route('orders.index', [
            'tab' => 'ONGOING',
            'from' => Carbon::yesterday()->toDateString(),
            'to' => Carbon::today()->toDateString(),
        ]))->assertOk()->assertSee($ongoing->sales_order_no);
    }

    public function test_order_detail_shows_the_document_lifecycle_and_next_actions(): void
    {
        $this->receiveStock('2400');
        $order = $this->confirmedOrder($this->secondary, '10', 'CTN');

        $this->actingAs($this->seller)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Lifecycle')
            ->assertSee('Delivery')
            ->assertSee('Shipment')
            ->assertSee('POD')
            ->assertSee('Invoice')
            ->assertSee('Payment')
            ->assertSee(route('deliveries.create', $order), false);

        // After acceptance the invoice is one tap away, with settlement.
        $delivery = $this->allocate($order, '10', 'CTN');
        $this->ship($delivery);
        $this->pod($delivery, '10', 'CTN');

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();

        $this->actingAs($this->seller)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($invoice->invoice_no)
            ->assertSee(route('payments.record.create', $invoice), false);
    }

    public function test_mobile_rows_do_not_put_fixed_width_fields_side_by_side(): void
    {
        /*
         * Mobile regression guard (360–450 px): the reported overflows all
         * came from a fixed-width input sitting next to another control in a
         * nowrap flex row. Quantity fields must be fluid (w-full + min-w-0)
         * and the unit is a small read-only badge, never a picker.
         */
        $vertex = $this->actingAs($this->seller)->get(route('inventory.counts.create'))->assertOk();
        $vertex->assertSee('grid grid-cols-2 gap-2', false)
            ->assertSee('w-full min-w-0', false)
            ->assertDontSee('w-28', false)
            ->assertDontSee('w-20', false);

        $this->receiveStock('2400');
        $order = $this->confirmedOrder($this->newCustomer(), '6', 'CTN');
        $delivery = $this->allocate($order, '4', 'CTN');
        $this->ship($delivery);

        $this->actingAs($this->seller)->get(route('deliveries.create', $order))->assertOk()
            ->assertSee('w-full min-w-0', false)
            ->assertDontSee('w-32', false);

        $this->actingAs($this->seller)->get(route('pod.show', $delivery))->assertOk()
            ->assertSee('w-full min-w-0', false)
            ->assertDontSee('w-32', false);

        // The Home SKU pipeline stacks on a phone and only spreads into three
        // columns once there is room (never a fixed-width row).
        $this->actingAs($this->seller)->get(route('dashboard'))->assertOk()
            ->assertSee('grid grid-cols-1 gap-y-0.5 text-[11px] sm:flex sm:flex-wrap', false)
            ->assertSee('flex h-3 w-full overflow-hidden rounded-full bg-surface-variant', false)
            ->assertSee('w-full', false);
    }

    // ---- UAT correction: customer profile --------------------------------

    public function test_customer_profile_shows_available_details_and_hides_empty_rows(): void
    {
        /*
         * The profile must carry the real customer details (name, type,
         * contact, phone, email, address, city/state) and a location action
         * when coordinates exist — without becoming a wall of blank rows.
         */
        $bare = CustomerMaster::factory()->forEmployee($this->employee)->create([
            'business_name' => 'Bare Trader',
            'contact_person' => null,
            'phone_number' => null,
            'email_address' => null,
            'address' => null,
            'address2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'sales_region' => null,
            'market' => null,
            'gps_latitude' => null,
            'gps_longitude' => null,
        ]);

        $this->actingAs($this->seller)->get(route('visits.customer', $bare->customer_id))
            ->assertOk()
            ->assertSee('Customer details')
            ->assertSee('Bare Trader')
            ->assertSee('SECONDARY')
            ->assertSee('No registered location for this customer yet.')
            ->assertDontSee('>Contact person</dt>', false)
            ->assertDontSee('>Email</dt>', false)
            ->assertDontSee('>Phone</dt>', false)
            ->assertDontSee('>Address</dt>', false)
            ->assertDontSee('Registered location')
            ->assertDontSee('Open in maps');

        $detailed = CustomerMaster::factory()->forEmployee($this->employee)->create([
            'business_name' => 'Fully Detailed Stores',
            'contact_person' => 'Ada Obi',
            'phone_number' => '+2348020000001',
            'email_address' => 'ops@detailed.test',
            'address' => '12 Market Road',
            'address2' => 'Suite 4',
            'city' => 'Aba',
            'state' => 'Abia',
            'postal_code' => '450101',
            'country' => 'Nigeria',
            'sales_region' => 'SOUTH-EAST',
            'market' => 'ABA-MAIN',
            'gps_latitude' => '5.1065000',
            'gps_longitude' => '7.3667000',
        ]);

        $this->actingAs($this->seller)->get(route('visits.customer', $detailed->customer_id))
            ->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee('+2348020000001')
            ->assertSee('ops@detailed.test')
            ->assertSee('12 Market Road')
            ->assertSee('City / State')
            ->assertSee('Abia')
            ->assertSee('Show on map')
            ->assertSee('Open in maps')
            ->assertSee('Registered location: 5.1065000, 7.3667000');
    }

    // ---- UAT correction: stock count rows ---------------------------------

    public function test_stock_count_rows_use_the_product_base_unit_read_only(): void
    {
        /*
         * A count row is exactly Product / Qty / Unit. The unit is the
         * product's authoritative base unit from product master — a read-only
         * label with a hidden field, never a picker — and there is no
         * horizontal overflow at 360 px because the fields already stack.
         */
        $convertible = ProductMaster::factory()->forCompany($this->company)->create([
            'basic_unit' => 'PCS',
            'product_description' => 'Countable SKU',
        ]);
        ProductUnitConversion::create([
            'product_id' => $convertible->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        $this->actingAs($this->seller)
            ->get(route('inventory.counts.create', ['customer' => $this->primary->customer_id, 'type' => 'PRIMARY_OPERATIONAL']))
            ->assertOk()
            // Product / Qty / Unit on every row…
            ->assertSee("lines['+index+'][product_id]", false)
            ->assertSee("lines['+index+'][counted_qty]", false)
            // …with the unit DERIVED from product master (read-only label) and
            // submitted as a hidden base unit — there is no unit picker and no
            // free-text unit field left to type CTN into.
            ->assertSee("lines['+index+'][count_unit]", false)
            ->assertSee('unitFor(row)', false)
            ->assertDontSee('maxlength="20"', false);

        // The product menu carries no alternative unit (a count is always base unit).
        $page = $this->actingAs($this->seller)
            ->get(route('inventory.counts.create', ['customer' => $this->primary->customer_id, 'type' => 'PRIMARY_OPERATIONAL']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('24 PCS', $page);
        $this->assertStringNotContainsString('CTN', $page, 'Counting never exposes an alternative unit.');

        // And the submitted count is still authoritatively converted server-side.
        $response = $this->actingAs($this->seller)->post(route('inventory.counts.store'), [
            'customer_id' => $this->primary->customer_id,
            'count_type' => 'PRIMARY_OPERATIONAL',
            'lines' => [[
                'product_id' => $convertible->product_id,
                'counted_qty' => '7',
                'count_unit' => 'PCS',
            ]],
        ]);

        $count = StockCount::with('items')->firstOrFail();
        $response->assertRedirect(route('inventory.counts.show', $count));

        $item = $count->items->first();
        $this->assertSame('7.000', (string) $item->counted_qty);
        $this->assertSame('PCS', $item->count_unit, 'The stored count unit is the product basic unit.');
    }

    // ---- UAT correction: order units and unit continuity -------------------

    public function test_order_context_offers_the_base_unit_plus_only_maintained_conversions(): void
    {
        /*
         * Base unit = product master's basic_unit (default selection).
         * Alternative = ONLY when a product_unit_conversion row is maintained
         * for that product. Units are never exposed globally.
         */
        $withConversion = ProductMaster::factory()->forCompany($this->company)->create([
            'basic_unit' => 'PCS',
            'product_description' => 'Convertible SKU',
        ]);
        ProductUnitConversion::create([
            'product_id' => $withConversion->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        $withoutConversion = ProductMaster::factory()->forCompany($this->company)->create([
            'basic_unit' => 'PCS',
            'product_description' => 'Plain SKU',
        ]);

        $response = $this->actingAs($this->seller)->getJson(route('orders.context', [
            'supplying_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
        ]))->assertOk();

        $products = collect($response->json('products'))->keyBy('id');

        $this->assertSame('PCS', $products[$withConversion->product_id]['basic_unit']);
        $this->assertSame(['CTN'], $products[$withConversion->product_id]['alt_units']);

        $this->assertSame('PCS', $products[$withoutConversion->product_id]['basic_unit']);
        $this->assertSame([], $products[$withoutConversion->product_id]['alt_units'], 'No global CTN exposure.');
    }

    public function test_base_unit_order_keeps_its_unit_through_allocation_and_pod(): void
    {
        $this->receiveStock('480');

        // 48 PCS at 24 PCS/CTN.
        $order = $this->confirmedOrder($this->newCustomer(), '48', 'PCS');
        $delivery = $this->allocate($order, '48', 'PCS');

        $item = $delivery->fresh('items')->items->first();
        $this->assertSame('48.000', (string) $item->allocated_qty);
        $this->assertSame('PCS', $item->delivery_unit);

        $this->ship($delivery);
        $this->pod($delivery, '48', 'PCS');

        $confirmation = DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame('48.000', (string) $confirmation->confirmed_qty);
        $this->assertSame('PCS', $confirmation->confirmed_unit, 'POD stays in the order unit.');
        $this->assertSame('48.000', app(ProductUnitService::class)->toBasicById($this->product->product_id, (string) $confirmation->confirmed_qty, (string) $confirmation->confirmed_unit));
    }

    public function test_alternate_unit_order_keeps_ctn_through_allocation_shipment_and_pod(): void
    {
        /*
         * SO line = 6 CTN (24 PCS each). The employee-facing lifecycle must
         * read 4 CTN at allocation, on the shipment load and at POD — never
         * 96 PCS — while the internal basic quantity stays exactly 96 PCS.
         */
        $this->receiveStock('2400');

        $order = $this->confirmedOrder($this->newCustomer(), '6', 'CTN');
        $delivery = $this->allocate($order, '4', 'CTN');

        $item = $delivery->fresh('items')->items->first();
        $this->assertSame('4.000', (string) $item->allocated_qty, 'Allocated: 4 CTN.');
        $this->assertSame('CTN', $item->delivery_unit);
        $this->assertSame(
            '96.000',
            app(ProductUnitService::class)->toBasicById($this->product->product_id, (string) $item->allocated_qty, (string) $item->delivery_unit),
            'Internally 4 CTN is exactly 96 PCS.',
        );

        $this->ship($delivery);

        $this->actingAs($this->seller)->get(route('shipments.show', $delivery->fresh()->shipmentLink->shipment_no))
            ->assertOk()
            ->assertSee('4 CTN');

        $this->pod($delivery, '4', 'CTN');

        $confirmation = DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame('4.000', (string) $confirmation->confirmed_qty, 'POD: 4 CTN.');
        $this->assertSame('CTN', $confirmation->confirmed_unit);
        $this->assertSame('0.000', (string) $confirmation->difference_qty, 'No difference: the unit never changed.');
    }

    public function test_allocation_and_pod_forms_carry_no_unit_picker(): void
    {
        /*
         * The employee cannot re-express a downstream quantity in another
         * unit: the forms post the Sales Order line's order unit as a hidden
         * field and show the unit as a read-only label. (The service keeps its
         * exact rational conversion capability for programmatic callers.)
         */
        $this->receiveStock('2400');

        $order = $this->confirmedOrder($this->newCustomer(), '6', 'CTN');

        $this->actingAs($this->seller)->get(route('deliveries.create', $order))
            ->assertOk()
            ->assertSee('name="lines[0][unit]" value="CTN"', false)
            ->assertDontSee('select name="lines[0][unit]"', false);

        $delivery = $this->allocate($order, '4', 'CTN');
        $this->ship($delivery);

        $this->actingAs($this->seller)->get(route('pod.show', $delivery))
            ->assertOk()
            ->assertSee('name="confirmed_unit" value="CTN"', false)
            ->assertDontSee('select name="confirmed_unit"', false);
    }

    // ---- 16-17: public invoice --------------------------------------------

    public function test_public_invoice_token_is_unguessable_and_not_a_database_id(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');
        [, $secondInvoice] = $this->acceptAndInvoice('4', 'CTN');

        $this->assertNull($invoice->public_token, 'No token until the invoice is shared.');

        $this->actingAs($this->seller)->get(route('finance.invoices.show', $invoice))->assertOk();

        $invoice->refresh();
        $this->actingAs($this->seller)->get(route('finance.invoices.show', $secondInvoice))->assertOk();
        $secondInvoice->refresh();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $invoice->public_token);
        $this->assertNotSame($invoice->invoice_no, $invoice->public_token);
        $this->assertNotSame($invoice->public_token, $secondInvoice->public_token);

        // Sequential identifiers are NOT the security mechanism.
        $this->get('/invoice/public/'.$invoice->invoice_no)->assertNotFound();
        $this->get('/invoice/public/'.substr((string) $invoice->public_token, 0, 42))->assertNotFound();
        $this->get('/invoice/public/'.str_repeat('a', 43))->assertNotFound();

        // Read-only, no login required.
        $this->get(route('invoice.public', $invoice->public_token))
            ->assertOk()
            ->assertSee($invoice->invoice_no)
            ->assertSee('Read-only public link');
    }

    public function test_public_invoice_page_omits_irrelevant_components_and_zero_tax(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');
        app(InvoiceService::class)->publicToken($invoice);
        $invoice->refresh();

        $response = $this->get(route('invoice.public', $invoice->public_token))->assertOk();

        $response->assertDontSee('Tax')
            ->assertDontSee('Discount')
            ->assertSee('Subtotal')
            ->assertSee('Total');

        // No write surface is reachable from the public page.
        $html = $response->getContent();
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString(route('payments.record.store', $invoice), $html);
        $this->assertStringNotContainsString(route('pod.confirm', $invoice->sales_order_no), $html);

        // When tax IS applicable it is displayed (never fabricated, never hidden).
        $invoice->forceFill(['tax_amount' => '180.00'])->save();

        $this->get(route('invoice.public', $invoice->public_token))
            ->assertOk()
            ->assertSee('Tax')
            ->assertSee('180.00');
    }

    public function test_public_invoice_image_is_a_downloadable_jpeg(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');
        app(InvoiceService::class)->publicToken($invoice);
        $invoice->refresh();

        $inline = $this->get(route('invoice.public.image', $invoice->public_token))->assertOk();
        $inline->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame("\xFF\xD8", substr($inline->getContent(), 0, 2), 'A real JPEG stream.');

        $download = $this->get(route('invoice.public.image', ['token' => $invoice->public_token, 'download' => 1]))->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString($invoice->invoice_no.'.jpg', $download->headers->get('Content-Disposition'));

        // The image is generated from the invoice rows — it is not a screenshot.
        $this->assertGreaterThan(4000, strlen($download->getContent()));
    }

    public function test_downloaded_invoice_is_an_a4_portrait_document(): void
    {
        /*
         * The public HTML page stays responsive for phone browsing; the shared
         * JPG must be a printable A4 PORTRAIT sheet (210 × 297 mm at 150 dpi →
         * 1240 × 1754), not a screenshot of the mobile page.
         */
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');
        app(InvoiceService::class)->publicToken($invoice);
        $invoice->refresh();

        $image = app(InvoiceImageService::class);

        [$width, $height] = $image->pageSize();
        $this->assertSame(1240, $width);
        $this->assertSame((int) round($width * 297 / 210), $height);

        $bytes = $this->get(route('invoice.public.image', $invoice->public_token))->assertOk()->getContent();
        $rendered = imagecreatefromstring($bytes);

        $this->assertNotFalse($rendered);
        $this->assertSame($width, imagesx($rendered));
        $this->assertSame($height, imagesy($rendered), 'The sheet is exactly A4 portrait — never a viewport snapshot.');

        // A4 aspect ratio holds within a 1 % tolerance (paper portrait: 1.414).
        $this->assertEqualsWithDelta(297 / 210, imagesy($rendered) / imagesx($rendered), 0.01);
    }

    public function test_a_long_invoice_still_renders_as_one_a4_sheet(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $product = ProductMaster::factory()->forCompany($this->company)->create([
            'basic_unit' => 'PCS',
            'product_description' => 'A very long product description that must wrap across several lines of the invoice table without ever colliding with the amount column',
        ]);

        // 40 lines is far beyond one page — the document must be scaled, never cropped.
        for ($i = 1; $i <= 40; $i++) {
            $invoice->items()->create([
                'item_no' => 100 + $i,
                'product_id' => $product->product_id,
                'sales_order_no' => $invoice->sales_order_no,
                'sales_order_item_no' => 1,
                'is_free_item' => $i % 7 === 0,
                'quantity' => '3',
                'invoice_unit' => 'PCS',
                'unit_price' => '1234.56',
                'subtotal_amount' => '3703.68',
            ]);
        }

        $invoice = $invoice->fresh(['items']);
        $image = app(InvoiceImageService::class);
        $bytes = $image->jpeg($invoice, null);
        $rendered = imagecreatefromstring($bytes);

        $this->assertNotFalse($rendered);
        $this->assertSame(1240, imagesx($rendered));
        $this->assertSame(1754, imagesy($rendered), 'Even a 40-line invoice keeps the A4 sheet.');

        // Nothing was silently dropped: every line is drawn AND the document
        // still closes with its totals and footer.
        $drawn = $image->drawnText();
        $this->assertContains('Powered by', $drawn);
        $this->assertContains('TOTAL', $drawn);
        $this->assertGreaterThan(80, count($drawn), 'The whole document is rendered, not a truncated page.');
    }

    public function test_invoice_document_names_the_supplying_primary_as_seller_and_the_secondary_as_debtor(): void
    {
        /*
         * Business reality: the Primary/Distributor makes the sale to the
         * Secondary. The company supplies the sales employee and the products
         * — it is not the seller. The debtor (and therefore credit exposure)
         * is unchanged: still the sold-to Secondary.
         */
        $this->receiveStock('2400');

        $secondary = $this->newCustomer();
        $order = $this->confirmedOrder($secondary, '10', 'CTN');
        $delivery = $this->allocate($order, '10', 'CTN');
        $this->ship($delivery);
        $this->pod($delivery, '10', 'CTN');

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
        $token = app(InvoiceService::class)->publicToken($invoice);

        $this->assertSame($secondary->customer_id, $invoice->customer_id, 'Debtor semantics unchanged.');
        $this->assertSame($this->primary->customer_id, $order->supplying_customer_id);

        $parties = app(InvoicePartyService::class)->parties($invoice);

        $this->assertSame($this->primary->business_name, $parties['seller']['name']);
        $this->assertNotSame($this->company->company_name, $parties['seller']['name'], 'The company is not the seller.');
        $this->assertSame($secondary->business_name, $parties['debtor']['name']);
        $this->assertSame($this->employee->employee_id, $parties['employee']['id']);
        $this->assertSame($this->company->company_name, $parties['company']['name']);
        $this->assertNotNull($parties['company']['name']);

        // The public page carries that identity (company name only in "Powered by").
        $this->get(route('invoice.public', $token))
            ->assertOk()
            ->assertSee($this->primary->business_name)
            ->assertSee($secondary->business_name)
            ->assertSee($this->employee->employee_id)
            ->assertSee('Powered by');

        // The downloaded document prints the powered-by line and NEVER the raw
        // public URL (the QR code is the only public handle on the paper).
        $image = app(InvoiceImageService::class);
        $url = route('invoice.public', $token);
        $bytes = $image->jpeg($invoice, $url);
        $this->assertNotFalse(imagecreatefromstring($bytes));

        $drawn = $image->drawnText();
        $this->assertContains($this->primary->business_name, $drawn);
        $this->assertContains($secondary->business_name, $drawn);
        $this->assertContains('Powered by', $drawn);
        $this->assertContains($this->employee->employee_id.' - '.$this->employee->employee_name, $drawn);
        $this->assertContains((string) $parties['company']['name'], $drawn);
        $this->assertContains('Scan Me', $drawn);

        foreach ($drawn as $line) {
            $this->assertStringNotContainsString('http', $line, 'The raw public URL is never printed on the invoice.');
        }
    }

    public function test_invoice_header_never_overlaps_and_stays_inside_the_sheet(): void
    {
        /*
         * UAT defect: the A4 JPG drew 'INVOICE' and the invoice number at the
         * SAME hard-coded baseline, so the title overlapped the number.
         *
         * The header is now a measured two-column layout, and this asserts the
         * contract the renderer itself consumes:
         *   - the title box sits strictly above the invoice number box;
         *   - every following meta line sits strictly below the one above it;
         *   - the seller column never reaches the meta column (gutter kept);
         *   - no line leaves the A4 text area.
         * Then it inspects the ACTUAL rendered pixels of the gap between the
         * title and the number, which must be blank.
         */
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $image = app(InvoiceImageService::class);
        $geometry = $image->headerGeometry($invoice->fresh());

        $title = $geometry['title'];
        $metaLines = $geometry['meta']['lines'];
        $left = $geometry['left'];
        $sheet = $geometry['sheet'];

        $this->assertSame('INVOICE', $title['text'], 'The title is the first thing in the meta column.');
        $this->assertSame($invoice->invoice_no, $metaLines[0]['text'], 'The invoice number follows the title, never beside it.');

        // Vertical: a guaranteed blank gap between every pair of boxes. This is
        // what the old hard-coded Y coordinates did not provide (the title and
        // the invoice number shared one baseline).
        $previous = $title;

        foreach ($metaLines as $line) {
            $this->assertGreaterThanOrEqual(
                8,
                $line['top'] - $previous['bottom'],
                'Header lines are separated by a blank gap, never stacked on one baseline: '.$line['text'],
            );

            $previous = $line;
        }

        // Horizontal: the two columns keep their gutter.
        $this->assertGreaterThanOrEqual($sheet['min_left'], $left['width'], 'The seller column keeps its guaranteed width.');
        $this->assertGreaterThanOrEqual($sheet['gutter'], $geometry['meta']['left'] - $left['max_right'], 'The meta column never reaches the seller column.');

        foreach ($left['lines'] as $line) {
            $this->assertLessThanOrEqual($left['width'], $line['width'], 'Seller line stays inside its column: '.$line['text']);
            $this->assertLessThanOrEqual($left['max_right'], $line['right']);
        }

        // Everything inside the A4 text area.
        $this->assertGreaterThanOrEqual($sheet['margin'], $title['left']);
        $this->assertLessThanOrEqual($sheet['width'] - $sheet['margin'], $geometry['meta']['right']);
        $this->assertLessThan($sheet['height'], $geometry['bottom']);

        // Rendered proof: the band between the title and the number is blank.
        $bytes = $image->jpeg($invoice->fresh(['items']), null);
        $rendered = imagecreatefromstring($bytes);

        $this->assertNotFalse($rendered);
        $this->assertSame([$sheet['width'], $sheet['height']], [imagesx($rendered), imagesy($rendered)]);

        $bandTop = $title['bottom'] + 1;
        $bandBottom = $metaLines[0]['top'] - 1;

        $this->assertGreaterThan(4, $bandBottom - $bandTop, 'A real separation band exists between the title and the number.');
        $this->assertSame(
            0,
            $this->inkInRect($rendered, $geometry['meta']['left'], $bandTop, $geometry['meta']['right'], $bandBottom),
            'No ink is drawn between the INVOICE title and the invoice number.',
        );
    }

    public function test_invoice_header_survives_a_long_invoice_number_and_a_long_seller_name(): void
    {
        /*
         * A long invoice number must shrink inside the sheet instead of running
         * into the title, off the page or over the seller column; a long Primary
         * name/address/contact must never reach the meta column.
         */
        $this->receiveStock('2400');

        $this->primary->forceFill([
            'business_name' => 'Euro Mega Atlantic Nigeria Limited Northern Distribution and Warehousing Division',
            'address' => 'Plot 4821, Industrial Layout Extension, Along the Old Airport Bypass, Near the Central Market',
            'phone_number' => '+2348012345678',
            'email_address' => 'northern.distribution.desk@euro-mega-atlantic-distribution.example',
        ])->save();

        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        // A number far wider than the whole meta column: it must scale down
        // inside the sheet instead of colliding or running off the paper.
        $invoice->invoice_no = 'INV-EMANL-2026-'.str_repeat('9', 90);
        $invoice->sales_order_no = 'SO-EMANL-2026-'.str_repeat('8', 60);

        $image = app(InvoiceImageService::class);
        $geometry = $image->headerGeometry($invoice);

        $left = $geometry['left'];
        $sheet = $geometry['sheet'];

        // The type scales down uniformly — it does not overflow.
        $this->assertLessThan(1.0, $geometry['scale'], 'A long invoice number shrinks the meta type.');
        $this->assertGreaterThanOrEqual($sheet['min_left'], $left['width'], 'The seller column is still guaranteed its width.');

        $metaWidth = $geometry['meta']['right'] - $geometry['meta']['left'];
        $this->assertLessThanOrEqual(
            $sheet['width'] - ($sheet['margin'] * 2) - $sheet['min_left'] - $sheet['gutter'] + 1,
            $metaWidth,
            'The meta column never eats the seller column, even for a 50-character invoice number.',
        );

        foreach ($left['lines'] as $line) {
            $this->assertLessThanOrEqual($left['width'], $line['width'], 'Seller line stays inside its column: '.$line['text']);
        }

        $this->assertGreaterThanOrEqual(8, $geometry['meta']['lines'][0]['top'] - $geometry['title']['bottom']);

        $bytes = $image->jpeg($invoice, null);
        $rendered = imagecreatefromstring($bytes);

        $this->assertNotFalse($rendered);
        $this->assertSame(1240, imagesx($rendered));
        $this->assertSame(1754, imagesy($rendered), 'The header change can never break the A4 sheet.');

        $this->assertSame(
            0,
            $this->inkInRect(
                $rendered,
                $geometry['meta']['left'],
                $geometry['title']['bottom'] + 1,
                $geometry['meta']['right'],
                $geometry['meta']['lines'][0]['top'] - 1,
            ),
            'Even a very long invoice number leaves the title/number band blank.',
        );
    }

    /**
     * The data contract the Home dashboard renders for one ORDER UNIT section.
     *
     * @return array<string, string>
     */
    private function pipelineRow(string $html, string $unit): array
    {
        $this->pipelineMarkup($html, $unit); // asserts the section exists

        preg_match('/<div[^>]*data-unit-pipeline="'.preg_quote($unit, '/').'"[^>]*>/s', $html, $attributes);

        $row = [];

        preg_match_all('/([a-z-]+)="([^"]*)"/', $attributes[0] ?? '', $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (str_starts_with($match[1], 'data-')) {
                $row[substr($match[1], 5)] = $match[2];
            }
        }

        $this->assertArrayHasKey('demand-total', $row, 'The dashboard must render the unit pipeline.');

        return $row;
    }

    /** The rendered markup of one ORDER UNIT section (the whole card). */
    private function pipelineMarkup(string $html, string $unit): string
    {
        $anchor = strpos($html, 'data-unit-pipeline="'.$unit.'"');

        $this->assertNotFalse($anchor, 'The Home dashboard must render an ORDER UNIT pipeline for '.$unit.'.');

        $start = strrpos(substr($html, 0, (int) $anchor), '<article');
        $end = strpos($html, '</article>', (int) $anchor);

        $this->assertNotFalse($start, 'The pipeline must live in the unit card.');
        $this->assertNotFalse($end, 'The pipeline must live in the unit card.');

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    /** Counts "ink" pixels (not near-white) inside a rectangle of a rendered image. */
    private function inkInRect(\GdImage $image, int $left, int $top, int $right, int $bottom): int
    {
        $ink = 0;

        for ($x = max(0, $left); $x <= min(imagesx($image) - 1, $right); $x++) {
            for ($y = max(0, $top); $y <= min(imagesy($image) - 1, $bottom); $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($r < 200 || $g < 200 || $b < 200) {
                    $ink++;
                }
            }
        }

        return $ink;
    }

    public function test_invoice_document_has_no_tax_row_when_tax_is_not_applicable(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $image = app(InvoiceImageService::class);
        $image->jpeg($invoice, null);

        $this->assertNotContains('Tax', $image->drawnText(), 'No tax row is invented when the invoice carries no tax.');
        $this->assertContains('Subtotal', $image->drawnText());
        $this->assertContains('TOTAL', $image->drawnText());

        $invoice->forceFill(['tax_amount' => '180.00'])->save();
        $image->jpeg($invoice->fresh(['items']), null);

        $this->assertContains('Tax', $image->drawnText(), 'An applicable tax is displayed, never hidden.');
    }

    public function test_invoice_image_columns_never_overlap(): void
    {
        /*
         * Regression: the amounts are right-aligned, so a growing currency
         * string used to run into the "Subtotal"/"Outstanding" labels and into
         * the unit-price column. The geometry is measured from the real
         * strings, and this asserts the measured columns stay disjoint.
         */
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $image = app(InvoiceImageService::class);

        // A deliberately wide multi-line invoice: long amounts, free line.
        $invoice->forceFill([
            'gross_amount' => '123456789.00',
            'invoice_amount' => '123456789.00',
            'settled_amount' => '12345670.00',
        ])->save();

        $invoice->load('items');
        $layout = $image->layoutFor($invoice);

        $items = $layout['items'];
        $productRight = $items['product_left'] + $items['product_width'];

        $this->assertGreaterThan(0, $items['product_width']);
        $this->assertLessThanOrEqual($items['qty_left'], $productRight, 'Product column ends before the qty column.');
        $this->assertLessThanOrEqual($items['price_left'], $items['qty_right'], 'Qty column ends before the price column.');
        $this->assertLessThanOrEqual($items['amount_left'], $items['price_right'], 'Price column ends before the amount column.');
        $this->assertLessThanOrEqual($items['right'], $items['amount_right'], 'Amounts stay inside the page margin.');

        // The label column ends before the value column starts.
        $this->assertLessThanOrEqual(
            $layout['totals']['value_start'],
            $layout['totals']['label_end'],
            'Totals labels must not run into the right-aligned values.',
        );

        // The widest possible totals (Settled + Outstanding) still fits.
        $this->assertGreaterThan(0, $layout['totals']['label_x'] - 56, 'Totals block stays inside the page margin.');

        // And the rendered image is still a real JPEG at the configured width.
        $bytes = $image->jpeg($invoice, route('invoice.public', app(InvoiceService::class)->publicToken($invoice)));
        $rendered = imagecreatefromstring($bytes);
        $this->assertNotFalse($rendered, 'The invoice image renders as a decodable JPEG.');
        $this->assertSame((int) config('erp.invoice_image.width'), imagesx($rendered));
    }

    // ---- 18-19: field settlement recording ---------------------------------

    public function test_sales_employee_records_verified_settlement_with_evidence_and_clears_the_debt(): void
    {
        [$order, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $this->actingAs($this->seller)->get(route('payments.record.create', $invoice))
            ->assertOk()
            ->assertSee('Bank transfer to Primary')
            ->assertSee('POS at Primary')
            ->assertSee('Cash at Primary office')
            ->assertSee('never with you');

        $response = $this->actingAs($this->seller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'BANK_TRANSFER_TO_PRIMARY',
            'amount' => '10000.00',
            'payment_reference' => 'TRX-99887766',
            'proof' => UploadedFile::fake()->image('transfer-receipt.jpg', 900, 1400),
            'gps_latitude' => '6.5243790',
            'gps_longitude' => '3.3792050',
            'gps_accuracy' => '9',
            'captured_at' => now()->toIso8601String(),
            'watermark_text' => 'Simple ERP | seller | invoice',
        ]);

        $response->assertRedirect(route('finance.invoices.show', $invoice));

        $payment = Payment::where('customer_id', $invoice->customer_id)->firstOrFail();

        // FinanceService stays authoritative: created → CONFIRMED → allocated.
        $this->assertSame('BANK_TRANSFER_TO_PRIMARY', $payment->payment_method->value);
        $this->assertSame('CONFIRMED', $payment->payment_status->value);
        $this->assertSame('10000.00', (string) PaymentAllocation::where('payment_id', $payment->payment_id)->sum('allocated_amount'));

        $invoice->refresh();
        $this->assertSame('PAID', $invoice->payment_status->value);
        $this->assertSame('0.00', $invoice->outstandingAmount());

        // Evidence is attached to the payment with STRUCTURED GPS.
        $evidence = PaymentEvidence::where('payment_id', $payment->payment_id)->firstOrFail();
        $this->assertSame('6.5243790', (string) $evidence->gps_latitude);
        $this->assertSame('9.00', (string) $evidence->gps_accuracy);
        $this->assertStringContainsString('Simple ERP', (string) $evidence->watermark_text);
        $this->assertSame($this->employee->employee_id, $evidence->uploaded_by);
        Storage::disk('local')->assertExists($evidence->stored_path);

        // The strict debt block clears through the existing finance rules.
        $nextOrder = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '2', 'unit' => 'CTN']]),
        )['order'];

        $this->assertSame([], $this->orders->confirm($nextOrder)['conflicts']);
        $this->assertSame('CONFIRMED', $nextOrder->fresh()->order_status->value);
    }

    public function test_payment_method_must_be_a_primary_routed_option(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        // A bare CASH would imply the employee collected the money personally.
        $this->actingAs($this->seller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'CASH',
            'amount' => '1000.00',
            'proof' => UploadedFile::fake()->image('cash.jpg'),
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Payment::count());

        // A crafted ADMIN amount/method combination is equally rejected.
        $this->actingAs($this->seller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'OTHER',
            'amount' => '1000.00',
            'proof' => UploadedFile::fake()->image('other.jpg'),
        ])->assertSessionHasErrors('payment_method');
    }

    public function test_payment_recording_is_authorized_by_assignment_and_company(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherSeller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $otherEmployee->employee_id,
        ]);

        $this->actingAs($otherSeller)->get(route('payments.record.create', $invoice))->assertForbidden();
        $this->actingAs($otherSeller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'POS_AT_PRIMARY',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('pos.jpg'),
        ])->assertForbidden();

        // Administrators have no field employee profile → no field recording.
        $this->actingAs($this->admin)->get(route('payments.record.create', $invoice))->assertForbidden();

        $this->assertSame(0, Payment::count());
        $this->assertSame('UNPAID', $invoice->fresh()->payment_status->value);
    }

    public function test_payment_evidence_must_be_a_real_image(): void
    {
        [, $invoice] = $this->acceptAndInvoice('10', 'CTN');

        $this->actingAs($this->seller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'POS_AT_PRIMARY',
            'amount' => '10000.00',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf'),
        ])->assertStatus(422);

        // Evidence validation runs BEFORE money moves: nothing is recorded.
        $this->assertSame(0, Payment::count());
        $this->assertSame('UNPAID', $invoice->fresh()->payment_status->value);

        // Proof is mandatory for a field settlement.
        $this->actingAs($this->seller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'POS_AT_PRIMARY',
            'amount' => '10000.00',
        ])->assertSessionHasErrors('proof');

        $this->assertSame(0, Payment::count());
    }

    // ---- 20: navigation ----------------------------------------------------

    public function test_alpine_chrome_is_cloaked_so_nothing_paints_before_alpine_boots(): void
    {
        /*
         * Every x-show element is painted by the browser the moment the HTML is
         * parsed — BEFORE app.js boots Alpine and applies its inline
         * display:none. The header's profile popover therefore shipped visible
         * on every navigation: "the profile menu opens by itself while
         * navigating" was this pre-boot paint, not a stray click handler.
         *
         * The fix is the x-cloak attribute plus a [x-cloak] display rule, so the
         * invariant is asserted directly: no x-show element may rely on Alpine
         * alone to hide it.
         */
        foreach ([route('dashboard'), route('visits.today')] as $url) {
            $html = $this->actingAs($this->seller)->get($url)->assertOk()->getContent();

            preg_match_all('/<[^>]*x-show[^>]*>/s', $html, $tags);

            $this->assertNotEmpty($tags[0], 'The chrome still uses Alpine x-show.');

            foreach ($tags[0] as $tag) {
                $this->assertTrue(
                    str_contains($tag, 'x-cloak') || (bool) preg_match('/style="[^"]*display:\s*none/i', $tag),
                    'An x-show element would paint before Alpine boots: '.preg_replace('/\s+/', ' ', trim($tag)),
                );
            }
        }

        $html = $this->actingAs($this->seller)->get(route('dashboard'))->assertOk()->getContent();

        // The profile popover specifically, and the sync banner it shares a
        // cause with.
        $this->assertSame(1, preg_match('/<div[^>]*x-show="open"[^>]*>/', $html, $menu));
        $this->assertStringContainsString('x-cloak', $menu[0], 'The profile popover must be cloaked.');

        $this->assertSame(1, preg_match('/<div[^>]*x-show="\$store\.net\.banner"[^>]*>/', $html, $banner));
        $this->assertStringContainsString('x-cloak', $banner[0], 'The sync banner must be cloaked.');

        // The attribute is only worth having if the stylesheet actually honours
        // it: without this rule x-cloak is decorative.
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\[x-cloak\]\s*\{[^}]*display:\s*none/i', $css);
    }

    public function test_five_persistent_destinations_and_the_more_hub(): void
    {
        $home = $this->actingAs($this->seller)->get(route('dashboard'))->assertOk();

        foreach ([route('dashboard'), route('visits.today'), route('orders.index'), route('inventory.index'), route('more.index')] as $href) {
            $home->assertSee($href, false);
        }

        $home->assertSee('Home')->assertSee('FJP')->assertSee('Orders')->assertSee('Inventory')->assertSee('More');

        $this->actingAs($this->seller)->get(route('more.index'))
            ->assertOk()
            ->assertSee('Shipments')
            ->assertSee('Payments')
            ->assertSee('Customers')
            ->assertSee('Field stock / transit')
            ->assertSee('Profile')
            ->assertSee('Log out');
    }

    public function test_customer_context_page_keeps_actions_contextual(): void
    {
        [$order, $invoice] = $this->acceptAndInvoice('10', 'CTN', $this->secondary);

        $this->actingAs($this->seller)->get(route('visits.customer', $this->secondary->customer_id))
            ->assertOk()
            ->assertSee('Inventory count')
            ->assertSee('Create new order')
            ->assertSee(route('inventory.counts.create', ['customer' => $this->secondary->customer_id]), false)
            ->assertSee(route('orders.create', ['customer' => $this->secondary->customer_id]), false)
            ->assertSee(route('payments.record.create', $invoice), false)
            ->assertSee('Record payment')
            ->assertSee('Recent purchases')
            ->assertSee('Open orders');
    }

    private function settle(SalesOrder $order): void
    {
        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->first();

        if ($invoice === null) {
            return;
        }

        $payment = $this->finance->createPayment(
            $this->company->company_id, $invoice->customer_id, 'CASH_AT_PRIMARY', (string) $invoice->invoice_amount, null,
        );
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment, collect([$invoice->invoice_no => (string) $invoice->invoice_amount]));
    }
}
