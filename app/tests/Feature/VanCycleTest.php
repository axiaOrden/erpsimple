<?php

namespace Tests\Feature;

use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Enums\TransitStatus;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockCount;
use App\Models\TransitStock;
use App\Services\DeliveryService;
use App\Services\FinanceService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ProvidesRejectionReasons;
use Tests\TestCase;

/**
 * VAN one-cycle rule (audited 2026-10-01).
 *
 * A VAN is commercially a Secondary customer — the same SO → Delivery →
 * Shipment START → POD → Invoice → Payment lifecycle, with NO VAN inventory
 * count / closing-stock / route-settlement subsystem — but it may hold only
 * ONE unresolved cycle at a time. "Unfinished" is derived from existing
 * states only, and the financial condition is the EXISTING Phase 8 net
 * exposure (`max(0, outstanding − available credit)`), so a settled invoice or
 * sufficient credit never blocks and terminal history never blocks forever.
 */
class VanCycleTest extends TestCase
{
    use DatabaseTransactions;
    use ProvidesRejectionReasons;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $secondary;

    private CustomerMaster $van;

    private ProductMaster $product;

    private SalesOrderService $orders;

    private DeliveryService $deliveries;

    private ShipmentService $shipments;

    private PodService $pod;

    private InventoryService $inventory;

    private InvoiceService $invoices;

    private FinanceService $finance;

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
        $this->van = CustomerMaster::factory()->van()->forEmployee($this->employee)->create();

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
        $this->invoices = app(InvoiceService::class);
        $this->finance = app(FinanceService::class);
    }

    // ---- Helpers ---------------------------------------------------------------

    private function receiveStock(string $qty = '1000'): void
    {
        $this->inventory->adjustPhysical(
            $this->employee,
            $this->primary->customer_id,
            $this->product->product_id,
            $qty,
            'PCS',
            MovementType::GOODS_RECEIPT,
        );
    }

    /** A DRAFT VAN cycle (the guard must never forbid drafting alone). */
    private function draft(CustomerMaster $customer, string $qty = '10', ?string $price = '2500.00'): SalesOrder
    {
        return $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $customer->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => 'PCS',
                'unit_price' => $price,
                'price_override_reason' => $price !== null ? 'VAN cycle snapshot price' : null,
            ]]),
        )['order'];
    }

    private function confirmOrder(SalesOrder $order): SalesOrder
    {
        $result = $this->orders->confirm($order);

        $this->assertSame([], $result['conflicts'], 'Confirmation should be allowed: '.implode(' | ', $result['conflicts']));

        return $result['order'];
    }

    private function confirmed(CustomerMaster $customer, string $qty = '10'): SalesOrder
    {
        return $this->confirmOrder($this->draft($customer, $qty));
    }

    private function allocate(SalesOrder $order, string $qty, int $itemNo = 1): Delivery
    {
        return $this->deliveries->createAndAllocate($order, $this->employee, collect([[
            'sales_order_item_no' => $itemNo,
            'qty' => $qty,
            'unit' => 'PCS',
        ]]))['delivery'];
    }

    /** One shipment from the Primary carrying every given ALLOCATED delivery. */
    private function ship(Delivery ...$deliveries): void
    {
        $shipment = $this->shipments->createShipment(
            $this->employee, $this->company->company_id, $this->primary->customer_id,
        );

        foreach ($deliveries as $delivery) {
            $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        }

        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);
    }

    private function pod(
        Delivery $delivery,
        string $qty,
        DifferenceReason $reason = DifferenceReason::NONE,
        ?DifferenceDisposition $disposition = null,
    ): void {
        $this->pod->confirmItem(
            $this->employee, $delivery->delivery_no, 1, $qty, 'PCS', $reason, null, $disposition,
        );
    }

    /** Settle an invoice through the EXISTING finance path (field payment). */
    private function settle(Invoice $invoice): void
    {
        $payment = $this->finance->createPayment(
            $this->company->company_id, $invoice->customer_id, 'CASH_AT_PRIMARY', (string) $invoice->invoice_amount, null,
        );
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment->fresh(), collect([$invoice->invoice_no => (string) $invoice->invoice_amount]));
    }

    private function vanStatus(): ?array
    {
        return $this->orders->vanCycleStatus($this->van->customer_id, $this->company->company_id);
    }

    /** Run an operation that must be refused by the VAN one-cycle rule. */
    private function expectVanBlock(callable $attempt): string
    {
        try {
            $attempt();
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode(), 'The VAN rule refuses with a 422.');

            return $e->getMessage();
        }

        $this->fail('Expected the VAN one-cycle rule to refuse this operation.');
    }

    // ---- A–K ----------------------------------------------------------------

    /** A — a VAN with no previous transaction may start a cycle. */
    public function test_a_van_with_no_previous_transaction_may_start_a_cycle(): void
    {
        $this->receiveStock();

        $this->assertNull($this->vanStatus(), 'Nothing to block on a fresh VAN.');

        $order = $this->confirmed($this->van);

        $this->assertSame('CONFIRMED', $order->order_status->value);
        $this->assertFalse($order->items()->where('is_free_item', true)->exists());

        // SO = demand only: a new VAN cycle never touches stock.
        $inventory = Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)->firstOrFail();
        $this->assertSame('1000.000', (string) $inventory->unrestricted_qty);
        $this->assertSame('0.000', (string) $inventory->restricted_qty);
    }

    /** B — an unfinished previous SO blocks a new order (capture AND confirmation). */
    public function test_b_unfinished_van_order_blocks_a_new_order(): void
    {
        $this->receiveStock();

        $first = $this->draft($this->van);
        $second = $this->draft($this->van); // drafts alone never block another draft
        $this->confirmOrder($first);        // the first cycle becomes demand

        // The authoritative boundary: the second cycle cannot be confirmed...
        $result = $this->orders->confirm($second->fresh());
        $this->assertNotSame([], $result['conflicts']);
        $this->assertStringContainsString('unfinished transaction', $result['conflicts'][0]);
        $this->assertStringContainsString($first->sales_order_no, $result['conflicts'][0]);
        $this->assertSame('DRAFT', $second->fresh()->order_status->value);

        // ...and capture already refuses a THIRD order while the cycle is open.
        $message = $this->expectVanBlock(fn () => $this->draft($this->van));
        $this->assertStringContainsString('This VAN still has an unfinished transaction', $message);
        $this->assertStringContainsString('open demand not yet allocated', $message);

        $status = $this->vanStatus();
        $this->assertNotNull($status);
        $this->assertSame($first->sales_order_no, $status['blocking'][0]['sales_order_no']);
        $this->assertSame(['open demand not yet allocated'], $status['blocking'][0]['reasons']);
    }

    /** C — an allocated delivery that was never dispatched blocks the next cycle. */
    public function test_c_allocated_delivery_not_yet_shipped_blocks_a_new_cycle(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);
        $delivery = $this->allocate($order, '10');

        $status = $this->vanStatus();
        $this->assertSame(['allocated delivery not yet dispatched'], $status['blocking'][0]['reasons']);

        $this->assertStringContainsString('not yet dispatched', $this->expectVanBlock(fn () => $this->draft($this->van)));

        // The refusal changed nothing: the allocation is intact.
        $this->assertSame('ALLOCATED', $delivery->fresh()->delivery_status->value);
    }

    /** D — Shipment START with POD unfinished is still an OPEN cycle. */
    public function test_d_shipment_started_with_pod_unfinished_blocks_a_new_cycle(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);
        $delivery = $this->allocate($order, '10');
        $this->ship($delivery);

        $status = $this->vanStatus();
        $this->assertSame(['dispatched quantity awaiting POD'], $status['blocking'][0]['reasons']);
        $this->assertStringContainsString('awaiting POD', $this->expectVanBlock(fn () => $this->draft($this->van)));

        // The goods physically left the source — demand is immutable, the cycle
        // is operationally open until the receiving customer confirms.
        $this->assertSame('SHIPPED', $delivery->fresh()->delivery_status->value);
        $inventory = Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)->firstOrFail();
        $this->assertSame('0.000', (string) $inventory->restricted_qty);
    }

    /** E — a PARTIAL POD (unresolved physical outcome) keeps the cycle open. */
    public function test_e_partial_pod_leaves_the_cycle_open(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);
        $deliveryA = $this->allocate($order, '4');
        $deliveryB = $this->allocate($order, '6');
        $this->ship($deliveryA, $deliveryB);

        $this->pod($deliveryA, '4'); // one row outcome'd, the other still open

        $status = $this->vanStatus();

        // Both facts are reported: one dispatched row has no outcome AND the
        // confirmed 4 cannot be invoiced yet (the cycle is not terminal).
        $this->assertContains('dispatched quantity awaiting POD', $status['blocking'][0]['reasons']);
        $this->assertContains('POD-accepted quantity not yet invoiced', $status['blocking'][0]['reasons']);
        $this->assertNotContains('open demand not yet allocated', $status['blocking'][0]['reasons']);

        $this->assertNull(Invoice::where('sales_order_no', $order->sales_order_no)->first());
        $this->assertSame('PARTIALLY_DELIVERED', $order->fresh()->order_status->value);
        $this->assertStringContainsString('awaiting POD', $this->expectVanBlock(fn () => $this->draft($this->van)));
    }

    /** F — a completed cycle whose invoice is UNSETTLED blocks on net exposure. */
    public function test_f_completed_pod_with_positive_net_exposure_blocks_a_new_cycle(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van, '8');
        $delivery = $this->allocate($order, '8');
        $this->ship($delivery);
        $this->pod($delivery, '8');

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
        $this->assertSame('20000.00', (string) $invoice->invoice_amount);

        $status = $this->vanStatus();
        $this->assertSame([], $status['blocking'], 'Nothing operational is left — only the money.');
        $this->assertSame('20000.00', $status['exposure']['net_exposure']);

        $message = $this->expectVanBlock(fn () => $this->draft($this->van));
        $this->assertStringContainsString('This VAN still has an unfinished transaction', $message);
        $this->assertStringContainsString('Net exposure: 20000.00', $message);

        // The financial condition is the EXISTING Phase 8 rule, unchanged.
        $this->assertSame('20000.00', $this->invoices->exposure($this->van->customer_id, $this->company->company_id)['net_exposure']);
    }

    /** G — a completed AND fully settled cycle lets the next one start. */
    public function test_g_completed_pod_and_fully_settled_invoice_allows_a_new_cycle(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van, '8');
        $delivery = $this->allocate($order, '8');
        $this->ship($delivery);
        $this->pod($delivery, '8');

        $this->settle(Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail());

        $this->assertSame('0.00', $this->invoices->exposure($this->van->customer_id, $this->company->company_id)['net_exposure']);
        $this->assertNull($this->vanStatus());

        $next = $this->confirmed($this->van);
        $this->assertSame('CONFIRMED', $next->order_status->value);
    }

    /** H — sufficient customer credit drops net exposure to zero → not falsely blocked. */
    public function test_h_customer_credit_offsets_exposure_instead_of_blocking(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van, '8');
        $delivery = $this->allocate($order, '8');
        $this->ship($delivery);
        $this->pod($delivery, '8');

        $this->assertNotNull($this->vanStatus(), 'An unsettled invoice blocks before the credit is applied.');

        $this->finance->createManualCredit(
            $this->company->company_id, $this->van->customer_id, '20000.00', 'VAN cycle audit credit',
        );

        $exposure = $this->invoices->exposure($this->van->customer_id, $this->company->company_id);
        $this->assertSame('20000.00', $exposure['outstanding']);
        $this->assertSame('20000.00', $exposure['available_credit']);
        $this->assertSame('0.00', $exposure['net_exposure']);

        $this->assertNull($this->vanStatus(), 'Zero net exposure must not read as unfinished work.');
        $this->assertSame('CONFIRMED', $this->confirmed($this->van)->order_status->value);
    }

    /** I — a terminally rejected VAN order with no work left allows a new cycle. */
    public function test_i_terminally_rejected_van_order_with_nothing_left_allows_a_new_cycle(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);
        $this->orders->rejectItem(
            SalesOrderItem::where('sales_order_no', $order->sales_order_no)->where('item_no', 1)->firstOrFail(),
            $this->reason(),
            $this->employee,
        );

        $this->assertSame('COMPLETELY_REJECTED', $order->fresh()->order_status->value);
        $this->assertNull(Invoice::where('sales_order_no', $order->sales_order_no)->first(), 'Nothing was ever shipped — no invoice.');
        $this->assertNull($this->vanStatus(), 'Terminal history must not block forever.');

        $this->assertSame('CONFIRMED', $this->confirmed($this->van)->order_status->value);
    }

    /** J — dispatched quantity + rejection still needs POD accountability. */
    public function test_j_dispatched_rejected_quantity_stays_blocked_until_pod_is_recorded(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);
        $delivery = $this->allocate($order, '10');
        $this->ship($delivery);

        // The demand is rejected AFTER dispatch: the physical accountability stays.
        $this->orders->rejectItem(
            SalesOrderItem::where('sales_order_no', $order->sales_order_no)->where('item_no', 1)->firstOrFail(),
            $this->reason(),
            $this->employee,
        );

        $this->assertSame('COMPLETELY_REJECTED', $order->fresh()->order_status->value);
        $this->assertSame(['dispatched quantity awaiting POD'], $this->vanStatus()['blocking'][0]['reasons']);
        $this->assertStringContainsString('awaiting POD', $this->expectVanBlock(fn () => $this->draft($this->van)));

        // Recording the physical outcome resolves the operational blocker...
        $this->pod($delivery, '10');
        $status = $this->vanStatus();
        $this->assertSame([], $status['blocking'], 'The dispatched quantity reached a terminal POD outcome.');

        // ...and the accepted quantity is still invoiced from POD (demand state
        // never decides the billable quantity), so settlement is the last step.
        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
        $this->assertSame('25000.00', (string) $invoice->invoice_amount);
        $this->assertSame('25000.00', $status['exposure']['net_exposure']);

        $this->settle($invoice);
        $this->assertNull($this->vanStatus());
    }

    /** K — a POD difference uses Phase 9 transit/custody: no VAN stock, no count. */
    public function test_k_pod_difference_uses_transit_custody_and_invents_no_van_stock(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);
        $delivery = $this->allocate($order, '10');
        $this->ship($delivery);

        $this->pod($delivery, '6', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        // The unaccepted 4 are traceable transit custody for the delivering employee.
        $transit = TransitStock::where('origin_delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame('4.000', (string) $transit->quantity);
        $this->assertSame(TransitStatus::REUSABLE, $transit->transit_status);
        $this->assertSame($this->employee->employee_id, $transit->holding_employee_id);
        $this->assertSame($this->primary->customer_id, $transit->source_customer_id);

        // NO VAN stock balance is fabricated at the VAN...
        $this->assertSame(0, Inventory::where('customer_id', $this->van->customer_id)->count());
        // ...and NO VAN count / closing workflow exists or is required to close it.
        $this->assertSame(0, StockCount::where('customer_id', $this->van->customer_id)->count());

        // The source was issued once (GOODS_ISSUE) and POD restored nothing to it.
        $inventory = Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)->firstOrFail();
        $this->assertSame('990.000', (string) $inventory->unrestricted_qty);
        $this->assertSame('0.000', (string) $inventory->restricted_qty);

        // Only the accepted 6 are billable.
        $this->assertSame('15000.00', (string) Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail()->invoice_amount);
    }

    /**
     * The capture screen EXPLAINS the refusal and links the blocking document
     * (informational only — the service guard stays authoritative).
     */
    public function test_order_capture_screen_explains_the_van_refusal(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->van);

        $this->actingAs($this->seller)
            ->get(route('orders.create', ['customer' => $this->van->customer_id]))
            ->assertOk()
            ->assertSee('This VAN cannot start another order yet')
            ->assertSee('unfinished transaction')
            ->assertSee($order->sales_order_no);

        // A non-VAN customer's capture screen is unaffected.
        $this->actingAs($this->seller)
            ->get(route('orders.create', ['customer' => $this->secondary->customer_id]))
            ->assertOk()
            ->assertDontSee('This VAN cannot start another order yet');

        // Submitting is refused with the friendly redirect; the service guard (and
        // its concurrency-safe lock) remains the enforcement behind it.
        $this->actingAs($this->seller)
            ->post(route('orders.store'), [
                'supplying_customer_id' => $this->primary->customer_id,
                'source_customer_id' => $this->primary->customer_id,
                'sold_to_customer_id' => $this->van->customer_id,
                'action' => 'draft',
                'lines' => [[
                    'product_id' => $this->product->product_id,
                    'qty' => '5',
                    'unit' => 'PCS',
                ]],
            ])
            ->assertRedirect(route('orders.create', ['customer' => $this->van->customer_id]))
            ->assertSessionHas('van_block');

        $this->assertSame(1, SalesOrder::where('sold_to_customer_id', $this->van->customer_id)->count());
    }

    /** Control — ordinary Secondary customers are NOT restricted to one cycle. */
    public function test_ordinary_secondary_customer_is_not_restricted(): void
    {
        $this->receiveStock();

        $order = $this->confirmed($this->secondary);
        $this->allocate($order, '10'); // unfinished: allocated, not dispatched

        $this->assertNull(
            $this->orders->vanCycleStatus($this->secondary->customer_id, $this->company->company_id),
            'The one-cycle rule is VAN-specific.',
        );

        // A Secondary may hold several unresolved cycles at once.
        $this->assertSame('CONFIRMED', $this->confirmed($this->secondary)->order_status->value);
        $this->assertSame('CONFIRMED', $this->confirmed($this->secondary)->order_status->value);
    }
}
