<?php

namespace Tests\Feature;

use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Enums\PaymentRecordStatus;
use App\Exceptions\ConfirmationConflict;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerCredit;
use App\Models\CustomerMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\EmployeeMaster;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrderItem;
use App\Services\DeliveryService;
use App\Services\FinanceService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 8 — Finance: invoices at the POD billing boundary, payments, credits,
 * allocations, exposure blocking. Rulings: docs/PHASE8_DESIGN.md REV 2 + §23.
 */
class FinanceTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $admin;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $secondary;

    private CustomerMaster $otherSecondary;

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
        $this->admin = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => null,
        ]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();
        $this->secondary = CustomerMaster::factory()->forEmployee($this->employee)->create();
        $this->otherSecondary = CustomerMaster::factory()->forEmployee($this->employee)->create();

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

    // ---- Fixtures ------------------------------------------------------------

    private function receiveStock(string $qty = '2000', string $unit = 'PCS', ?CustomerMaster $holder = null, ?ProductMaster $product = null): void
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

    private function confirmedOrder(string $qty = '100', string $unit = 'PCS', ?string $price = null)
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
                'unit_price' => $price,
                'price_override_reason' => $price !== null ? 'Negotiated snapshot price' : null,
            ]]),
        )['order'];

        $this->orders->confirm($order);

        return $order->fresh(['items']);
    }

    private function allocateDelivery($order, string $qty, string $unit = 'PCS', int $itemNo = 1)
    {
        return $this->deliveries->createAndAllocate(
            $order,
            $this->employee,
            collect([['sales_order_item_no' => $itemNo, 'qty' => $qty, 'unit' => $unit]]),
        )['delivery'];
    }

    private function ship($delivery)
    {
        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);
    }

    private function confirmPod($delivery, string $qty, string $unit = 'PCS', DifferenceReason $reason = DifferenceReason::NONE)
    {
        return $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, $qty, $unit, $reason);
    }

    private function rejectOrderItem($order, string $reason = 'CUSTOMER_REJECTED'): void
    {
        $item = SalesOrderItem::where('sales_order_no', $order->sales_order_no)->where('item_no', 1)->firstOrFail();
        $this->orders->rejectItem($item, $reason, $this->employee);
    }

    private function confirmedOrderFor($customer, string $qty = '5', string $unit = 'PCS', ?string $price = null)
    {
        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $customer->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price' => $price,
                'price_override_reason' => $price !== null ? 'Negotiated snapshot price' : null,
            ]]),
        )['order'];

        $this->orders->confirm($order);

        return $order->fresh(['items']);
    }

    // ---- Invoice generation at the billing boundary -------------------------------

    public function test_invoice_generated_at_first_billing_terminal_pod(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('30', 'PCS', '24500.00'); // ruling §23.1 example price
        $delivery = $this->allocateDelivery($order, '30', 'PCS');
        $this->ship($delivery);

        $result = $this->confirmPod($delivery, '30', 'PCS');

        $this->assertSame('COMPLETELY_DELIVERED', $order->fresh()->order_status->value);
        $this->assertSame(1, Invoice::count(), 'Exactly one final invoice per SO.');

        $invoice = $result['invoice'];
        $this->assertNotNull($invoice);
        $this->assertSame($order->sales_order_no, $invoice->sales_order_no);
        $this->assertSame($this->secondary->customer_id, $invoice->customer_id, 'Debtor = sold-to Secondary.');
        $this->assertSame($this->company->company_id, $invoice->company_id);
        $this->assertSame('IMMEDIATE', $invoice->payment_term->value);
        $this->assertSame($invoice->invoice_date->format('Y-m-d'), $invoice->due_date->format('Y-m-d'), 'IMMEDIATE → due = invoice_date.');
        $this->assertSame('UNPAID', $invoice->payment_status->value);
        $this->assertSame('735000.00', (string) $invoice->invoice_amount, '30 × 24,500 snapshot price.');
        $this->assertSame('735000.00', (string) $invoice->gross_amount);

        $line = InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        $this->assertSame('30.000', (string) $line->quantity);
        $this->assertSame('PCS', $line->invoice_unit);
        $this->assertSame('24500.00', (string) $line->unit_price, 'Snapshot price, not master price.');
        $this->assertSame('735000.00', (string) $line->subtotal_amount);
        $this->assertFalse((bool) $line->is_free_item);
    }

    public function test_pod_retry_does_not_duplicate_invoice(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('24');
        $delivery = $this->allocateDelivery($order, '24', 'PCS');
        $this->ship($delivery);

        $this->confirmPod($delivery, '24');

        // Idempotent path: generateIfTerminal returns the existing invoice.
        $again = $this->invoices->generateIfTerminal($order);
        $this->assertNotNull($again);
        $this->assertSame(1, Invoice::where('sales_order_no', $order->sales_order_no)->count());

        // A POD submission that attempts to re-confirm still loses (Phase 7)
        // and must not create a second invoice either.
        try {
            $this->pod->confirmItem($this->employee, $delivery->delivery_no, 1, '24', 'PCS', DifferenceReason::NONE);
            $this->fail('Expected duplicate POD to be rejected.');
        } catch (HttpException) {
        }

        $this->assertSame(1, Invoice::count());
    }

    public function test_partial_pod_invoices_confirmed_qty_only_no_credit_rows(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'PCS', '24500.00');
        $delivery = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($delivery);

        $this->confirmPod($delivery, '8', 'PCS', DifferenceReason::SHORT_DELIVERY);

        $this->assertSame('PARTIALLY_DELIVERED', $order->fresh()->order_status->value);
        $this->assertSame(1, Invoice::count(), 'PARTIAL is a terminal outcome → invoice immediately.');

        $invoice = Invoice::firstOrFail();
        $this->assertSame('8.000', (string) InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail()->quantity);
        $this->assertSame('196000.00', (string) $invoice->invoice_amount, '8 × 24,500 — actual confirmed quantity.');
        $this->assertSame(0, CustomerCredit::count(), 'No automatic credit for the never-invoiced difference.');
    }

    public function test_rejected_remainder_invoices_shipped_qty_in_single_invoice(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('30', 'PCS', '24500.00');
        $d1 = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($d1);
        $this->confirmPod($d1, '10');

        $this->assertSame(0, Invoice::count(), 'Remainder un-dispositioned → not yet terminal.');

        $this->rejectOrderItem($order);

        $this->assertSame(1, Invoice::count(), 'Rejection completes the disposition → single invoice.');
        $invoice = Invoice::firstOrFail();
        $this->assertSame('10.000', (string) InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail()->quantity);
        $this->assertSame('245000.00', (string) $invoice->invoice_amount);
    }

    public function test_fully_rejected_order_generates_no_monetary_invoice(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('30', 'PCS', '24500.00');

        $this->rejectOrderItem($order);

        $this->assertSame('COMPLETELY_REJECTED', $order->fresh()->order_status->value);
        $this->assertSame(0, Invoice::count(), '§19.4: no invoice for fully-rejected demand.');
        $this->assertSame('0.00', $this->invoices->exposure($this->secondary->customer_id)['outstanding']);
    }

    public function test_goods_issue_never_generates_an_invoice(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('24');
        $delivery = $this->allocateDelivery($order, '24', 'PCS');
        $this->ship($delivery);

        // GOODS_ISSUE happened above (START) — invoice requires POD, not GI.
        $this->assertSame(0, Invoice::count(), 'POD is the billing boundary, never GOODS_ISSUE.');
    }

    public function test_free_line_confirmed_invoices_at_zero_and_unconfirmed_free_line_is_omitted(): void
    {
        $reward = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        ProductUnitConversion::create([
            'product_id' => $reward->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);
        $this->receiveStock('240');
        $this->receiveStock('240', 'PCS', $this->primary, $reward);

        // Deal fixture (Phase 4 pattern): buy 10 CTN → 1 CTN of the reward free.
        DealCondition::create([
            'deal_no' => 'DT-FIN-1', 'company_id' => $this->company->company_id,
            'deal_description' => 'buy 10 CTN get 1 CTN', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'active' => true,
        ]);
        DealQualifier::create(['deal_no' => 'DT-FIN-1', 'product_id' => $this->product->product_id, 'minimum_qty' => '10', 'qualifier_unit' => 'CTN']);
        DealReward::create(['deal_no' => 'DT-FIN-1', 'product_id' => $reward->product_id, 'reward_qty' => '1', 'reward_unit' => 'CTN']);

        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => '10',
                'unit' => 'CTN',
                'unit_price' => '24500.00',
                'price_override_reason' => 'Deal: buy 10 get 1',
            ]]),
        )['order'];
        $this->orders->confirm($order);
        $order = $order->fresh(['items']);

        $free = $order->items->first(fn ($i) => (bool) $i->is_free_item);
        $this->assertNotNull($free, 'Fixture must produce a free deal line.');

        // Free line NOT shipped: delivery covers only the paid line.
        $delivery = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN'],
        ]))['delivery'];
        $this->ship($delivery);
        $this->confirmPod($delivery, '10', 'CTN');

        $this->assertSame(0, Invoice::count(), 'Unshipped free line keeps the order non-terminal.');

        // Ship + confirm the free line too.
        $freeDelivery = $this->deliveries->createAndAllocate($order, $this->employee, collect([
            ['sales_order_item_no' => $free->item_no, 'qty' => '1', 'unit' => 'CTN'],
        ]))['delivery'];
        $this->ship($freeDelivery);
        $this->confirmPod($freeDelivery, '1', 'CTN');

        $this->assertSame(1, Invoice::count());
        $lines = InvoiceItem::where('invoice_no', Invoice::firstOrFail()->invoice_no)->get();
        $this->assertSame(2, $lines->count());

        $paidLine = $lines->firstWhere('is_free_item', false);
        $freeLine = $lines->firstWhere('is_free_item', true);
        $this->assertSame('10.000', (string) $paidLine->quantity);
        $this->assertSame('CTN', $paidLine->invoice_unit);
        $this->assertSame('245000.00', (string) $paidLine->subtotal_amount);
        $this->assertSame('1.000', (string) $freeLine->quantity, 'Confirmed quantity is shown — at a zero price.');
        $this->assertTrue((bool) $freeLine->is_free_item);
        $this->assertSame('0.00', (string) $freeLine->subtotal_amount, 'Free line contributes nothing to the invoice amount.');
        $this->assertSame('0.00', (string) $freeLine->unit_price);
    }

    // ---- Price basis rulings (§22.1 / §23.1) -----------------------------------

    public function test_exact_whole_back_conversion_invoices_order_units(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'CTN', '24500.00');
        $delivery = $this->allocateDelivery($order, '10', 'CTN');
        $this->ship($delivery);

        // 240 PCS confirmed in PCS — exactly 10 CTN back.
        $this->confirmPod($delivery, '240', 'PCS');

        $line = InvoiceItem::firstOrFail();
        $this->assertSame('CTN', $line->invoice_unit, 'Exact whole back-conversion keeps the order-unit line.');
        $this->assertSame('10.000', (string) $line->quantity);
        $this->assertSame('24500.00', (string) $line->unit_price);
        $this->assertSame('245000.00', (string) $line->subtotal_amount);
    }

    public function test_inexact_fallback_converts_quantity_and_price_basis_together(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('2', 'CTN', '24500.00');
        $delivery = $this->allocateDelivery($order, '2', 'CTN');
        $this->ship($delivery);

        // 1 CTN + 6 PCS confirmed (30 of 48 PCS) → 1.25 CTN, NOT whole.
        $this->confirmPod($delivery, '30', 'PCS', DifferenceReason::SHORT_DELIVERY);

        $line = InvoiceItem::firstOrFail();
        $this->assertSame('PCS', $line->invoice_unit, 'Fallback line is in the basic unit.');
        $this->assertSame('30.000', (string) $line->quantity);
        $this->assertSame('1020.83', (string) $line->unit_price, 'Closest 2dp display value (24,500 ÷ 24).');
        $this->assertSame('30625.00', (string) $line->subtotal_amount, 'AUTHORITATIVE: (30 × 24,500) ÷ 24 — never 30 × 1,020.83.');
        $this->assertSame('30625.00', (string) Invoice::firstOrFail()->invoice_amount);
    }

    public function test_divisible_price_converts_exactly(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('2', 'CTN', '24000.00');
        $delivery = $this->allocateDelivery($order, '2', 'CTN');
        $this->ship($delivery);

        $this->confirmPod($delivery, '30', 'PCS', DifferenceReason::SHORT_DELIVERY);

        $line = InvoiceItem::firstOrFail();
        $this->assertSame('1000.00', (string) $line->unit_price, '24,000 ÷ 24 divides exactly.');
        $this->assertSame('30.000', (string) $line->quantity);
        $this->assertSame('30000.00', (string) $line->subtotal_amount);
    }

    public function test_invoice_honors_snapshot_price_after_master_price_change(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('24', 'PCS', '24500.00');
        $delivery = $this->allocateDelivery($order, '24', 'PCS');
        $this->ship($delivery);

        // Master price "changes" after the snapshot was taken (new condition).
        PriceCondition::create([
            'condition_price_no' => 'PC-LATER-'.$this->company->company_id,
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-LATER-'.$this->company->company_id,
            'product_id' => $this->product->product_id,
            'price' => '25000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $this->confirmPod($delivery, '24');

        $this->assertSame('24500.00', (string) InvoiceItem::firstOrFail()->unit_price, 'Snapshot, not the new master price.');
        $this->assertSame('588000.00', (string) Invoice::firstOrFail()->invoice_amount);
    }

    // ---- Debtor exposure & blocking --------------------------------------------

    public function test_exposure_keeps_three_values_separate_and_unused_credit_reduces_net(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'PCS', '10000.00');
        $delivery = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($delivery);
        $this->confirmPod($delivery, '10');

        $customerId = $this->secondary->customer_id;

        $exposure = $this->invoices->exposure($customerId);
        $this->assertSame('100000.00', $exposure['outstanding']);
        $this->assertSame('0.00', $exposure['available_credit']);
        $this->assertSame('100000.00', $exposure['net_exposure']);

        // Open credit REDUCES net exposure (never adds to outstanding).
        $this->finance->createManualCredit($this->company->company_id, $customerId, '20000.00');

        $exposure = $this->invoices->exposure($customerId);
        $this->assertSame('100000.00', $exposure['outstanding'], 'Outstanding unchanged by open credit.');
        $this->assertSame('20000.00', $exposure['available_credit']);
        $this->assertSame('80000.00', $exposure['net_exposure'], '100,000 − 20,000 = 80,000 (design §13 worked check).');
    }

    public function test_credit_blocks_debtor_confirmation_assertion(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'PCS', '10000.00');
        $delivery = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($delivery);
        $this->confirmPod($delivery, '10');

        $this->assertSame('100000.00', $this->invoices->exposure($this->secondary->customer_id)['net_exposure']);

        try {
            $this->invoices->assertDebtorCanConfirm($order);
            $this->fail('Expected the confirmation gate to block an exposed debtor.');
        } catch (ConfirmationConflict $e) {
            $this->assertStringContainsString('net exposure', $e->conflicts[0]);
        }
    }

    public function test_full_credit_application_unblocks_confirmation(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'PCS', '10000.00');
        $delivery = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($delivery);
        $this->confirmPod($delivery, '10');

        $invoice = Invoice::firstOrFail();

        // Fully applied credit settles the invoice → the debtor unblocks.
        $credit = $this->finance->createManualCredit($this->company->company_id, $this->secondary->customer_id, '100000.00');
        $this->finance->allocateCredit($credit->fresh());

        $this->assertSame('PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('0.00', $this->invoices->exposure($this->secondary->customer_id)['net_exposure']);

        $this->invoices->assertDebtorCanConfirm($order); // must NOT throw
        $this->assertTrue(true, 'Fully credited debtor passes the confirmation gate.');
    }

    public function test_positive_net_exposure_blocks_second_order_for_same_debtor(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'PCS', '10000.00');
        $delivery = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($delivery);
        $this->confirmPod($delivery, '10');

        // Another SO for the SAME sold-to debtor is blocked at confirmation.
        // confirm() surfaces exposure as a returned conflict (order stays DRAFT).
        $order2 = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => '5',
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $result = $this->orders->confirm($order2);

        $this->assertNotEmpty($result['conflicts'], 'Exposure must surface as a confirmation conflict.');
        $this->assertStringContainsString('net exposure', $result['conflicts'][0]);
        $this->assertSame('DRAFT', $order2->fresh()->order_status->value, 'Blocked order stays an untouched DRAFT.');

        // A DIFFERENT debtor is not affected.
        $otherOrder = $this->confirmedOrderFor($this->otherSecondary, '5');

        $this->assertSame('CONFIRMED', $otherOrder->order_status->value, 'A different debtor confirms freely.');
    }

    public function test_debtor_identity_isolation_between_customers(): void
    {
        $this->receiveStock();
        $order = $this->confirmedOrder('10', 'PCS', '10000.00');
        $delivery = $this->allocateDelivery($order, '10', 'PCS');
        $this->ship($delivery);
        $this->confirmPod($delivery, '10');

        // Mama Chi's records never touch MIMZA: the other debtor has zero.
        $exposure = $this->invoices->exposure($this->otherSecondary->customer_id);
        $this->assertSame('0.00', $exposure['outstanding']);
        $this->assertSame('0.00', $exposure['net_exposure']);
        $this->assertSame($this->secondary->customer_id, Invoice::firstOrFail()->customer_id);
    }

    // ---- Payments & allocations -------------------------------------------------

    public function test_payment_lifecycle_and_fifo_allocation(): void
    {
        // Both orders are confirmed BEFORE any POD (zero exposure at that
        // point) — this is exactly how a debtor comes to hold two invoices.
        $this->receiveStock('5000');
        $order1 = $this->confirmedOrder('10', 'PCS', '10000.00');
        $order2 = $this->confirmedOrder('5', 'PCS', '10000.00');

        $this->createInvoicedOrder('10', 'PCS', '10000.00', order: $order1);
        $oldInvoice = Invoice::firstOrFail();

        $this->createInvoicedOrder('5', 'PCS', '10000.00', order: $order2, assertSingle: false);
        $newInvoice = Invoice::orderByDesc('invoice_no')->firstOrFail();
        $this->assertNotSame($oldInvoice->invoice_no, $newInvoice->invoice_no);

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '120000.00', 'TRN-1');
        $this->assertSame(PaymentRecordStatus::PENDING, $payment->payment_status);

        // Only CONFIRMED payments allocate.
        try {
            $this->finance->allocatePayment($payment);
            $this->fail('Expected PENDING payment allocation to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('CONFIRMED', $e->getMessage());
        }

        $this->finance->confirmPayment($payment);
        $this->assertSame(PaymentRecordStatus::CONFIRMED, $payment->fresh()->payment_status);

        $result = $this->finance->allocatePayment($payment->fresh());
        $this->assertEqualsWithDelta(120000.0, (float) $result['allocations']->sum(fn ($a) => (float) $a['amount']), 0.001);

        // FIFO: the OLDEST invoice is drained FIRST, the remainder spills
        // into the newest one.
        $this->assertSame('PAID', $oldInvoice->fresh()->payment_status->value);
        $this->assertSame('100000.00', (string) $oldInvoice->fresh()->settled_amount);
        $this->assertSame('PARTIALLY_PAID', $newInvoice->fresh()->payment_status->value);
        $this->assertSame('20000.00', (string) $newInvoice->fresh()->settled_amount);

        // Remainder math on the payment.
        $this->assertSame('0.00', $this->finance->unallocatedAmount($payment->fresh()));
    }

    public function test_partial_allocation_and_later_completion(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'TRANSFER', '40000.00');
        $this->finance->confirmPayment($payment);

        $result = $this->finance->allocatePayment($payment->fresh());
        $this->assertEqualsWithDelta(40000.0, (float) $result['allocations']->sum(fn ($a) => (float) $a['amount']), 0.001);
        $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('40000.00', (string) $invoice->fresh()->settled_amount);

        // A second payment completes the invoice.
        $payment2 = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'POS', '60000.00');
        $this->finance->confirmPayment($payment2);
        $this->finance->allocatePayment($payment2->fresh());

        $this->assertSame('PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('100000.00', (string) $invoice->fresh()->settled_amount);
        $this->assertSame('0.00', $this->invoices->exposure($this->secondary->customer_id)['net_exposure']);
    }

    public function test_over_allocation_is_rejected(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '30000.00');
        $this->finance->confirmPayment($payment);

        try {
            $this->finance->allocatePayment($payment->fresh(), collect([$invoice->invoice_no => '50000.00']));
            $this->fail('Expected over-allocation to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('unallocated remainder', $e->getMessage());
        }

        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame('UNPAID', $invoice->fresh()->payment_status->value);
    }

    public function test_cross_debtor_allocation_is_rejected(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        // Payment recorded for a DIFFERENT debtor.
        $payment = $this->finance->createPayment($this->company->company_id, $this->otherSecondary->customer_id, 'CASH', '50000.00');
        $this->finance->confirmPayment($payment);

        try {
            $this->finance->allocatePayment($payment->fresh(), collect([$invoice->invoice_no => '10000.00']));
            $this->fail('Expected cross-debtor allocation to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('not an outstanding invoice of this debtor', $e->getMessage());
        }

        $this->assertSame('UNPAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('0.00', (string) $invoice->fresh()->settled_amount);
    }

    public function test_fifo_skips_settled_invoices_and_respects_explicit_amounts(): void
    {
        // Both orders confirmed before any invoice exists (see lifecycle test).
        $this->receiveStock('5000');
        $order1 = $this->confirmedOrder('10', 'PCS', '10000.00');
        $order2 = $this->confirmedOrder('5', 'PCS', '10000.00');

        $this->createInvoicedOrder('10', 'PCS', '10000.00', order: $order1);
        $first = Invoice::firstOrFail();

        $p1 = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '100000.00');
        $this->finance->confirmPayment($p1);
        $this->finance->allocatePayment($p1->fresh());
        $this->assertSame('PAID', $first->fresh()->payment_status->value);

        $this->createInvoicedOrder('5', 'PCS', '10000.00', order: $order2, assertSingle: false);
        $second = Invoice::orderByDesc('invoice_no')->firstOrFail();

        $p2 = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '25000.00');
        $this->finance->confirmPayment($p2);

        // Auto-FIFO must NOT touch the settled first invoice.
        $result = $this->finance->allocatePayment($p2->fresh());
        $this->assertSame([$second->invoice_no], $result['allocations']->pluck('invoice_no')->all());
        $this->assertSame('25000.00', (string) $second->fresh()->settled_amount);
    }

    public function test_overpayment_remainder_converts_to_credit(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '120000.00');
        $this->finance->confirmPayment($payment);

        // Allocate the invoice-covered portion FIRST; the remainder converts.
        $this->finance->allocatePayment($payment->fresh());

        $credit = $this->finance->convertRemainderToOverpaymentCredit($payment->fresh());

        $this->assertSame('OVERPAYMENT', $credit->credit_source->value);
        $this->assertSame('20000.00', (string) $credit->original_amount);
        $this->assertSame('20000.00', (string) $credit->remaining_amount);
        $this->assertSame($this->secondary->customer_id, $credit->customer_id);
        $this->assertSame('Payment '.$payment->payment_id, $credit->source_reference);

        // Invoice fully settled by the payment portion.
        $this->assertSame('PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('100000.00', (string) $invoice->fresh()->settled_amount);

        // Open credit now reduces the debtor's net exposure.
        $this->assertSame('20000.00', $this->invoices->exposure($this->secondary->customer_id)['available_credit']);
        $this->assertSame('0.00', $this->invoices->exposure($this->secondary->customer_id)['net_exposure']);
    }

    public function test_converted_remainder_cannot_be_converted_or_allocated_twice(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '30000.00');
        $this->finance->confirmPayment($payment);

        // Convert FIRST (before any allocation): the whole remainder becomes credit.
        $credit = $this->finance->convertRemainderToOverpaymentCredit($payment->fresh());
        $this->assertSame('30000.00', (string) $credit->original_amount);

        // The converted remainder is spent — not allocatable, not re-convertible.
        $this->assertSame('0.00', $this->finance->unallocatedAmount($payment->fresh()));

        try {
            $this->finance->convertRemainderToOverpaymentCredit($payment->fresh());
            $this->fail('Double conversion must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            $this->finance->allocatePayment($payment->fresh());
            $this->fail('Allocating a fully-converted payment must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Exactly ONE credit ever exists for this payment.
        $this->assertSame(1, CustomerCredit::where('source_reference', 'Payment '.$payment->payment_id)->count());
    }

    // ---- Ruling §23.2: cancellation & immutable allocations ----------------------

    public function test_pending_payment_can_be_cancelled(): void
    {
        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '100.00');

        $cancelled = $this->finance->cancelPayment($payment);

        $this->assertSame(PaymentRecordStatus::CANCELLED, $cancelled->payment_status);
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_confirmed_unallocated_payment_can_be_cancelled(): void
    {
        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '100.00');
        $this->finance->confirmPayment($payment);

        $cancelled = $this->finance->cancelPayment($payment->fresh());

        $this->assertSame(PaymentRecordStatus::CANCELLED, $cancelled->payment_status);
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_confirmed_allocated_payment_cannot_be_cancelled_and_history_is_immutable(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '40000.00');
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment->fresh());

        $allocationsBefore = PaymentAllocation::where('payment_id', $payment->payment_id)
            ->orderBy('invoice_no')->get(['invoice_no', 'allocated_amount'])->toArray();
        $this->assertNotEmpty($allocationsBefore);

        try {
            $this->finance->cancelPayment($payment->fresh());
            $this->fail('Expected cancellation of an allocated CONFIRMED payment to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('already has allocations', $e->getMessage());
        }

        // Allocations untouched, invoice settled values untouched.
        $this->assertSame($allocationsBefore, PaymentAllocation::where('payment_id', $payment->payment_id)
            ->orderBy('invoice_no')->get(['invoice_no', 'allocated_amount'])->toArray(), 'Allocation rows are immutable history.');
        $this->assertSame('40000.00', (string) $invoice->fresh()->settled_amount);
        $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame(PaymentRecordStatus::CONFIRMED, $payment->fresh()->payment_status);
    }

    // ---- Credits -----------------------------------------------------------------

    public function test_manual_credit_created_and_fifo_allocated(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $credit = $this->finance->createManualCredit($this->company->company_id, $this->secondary->customer_id, '30000.00', 'Goodwill');

        $this->assertSame('MANUAL_ADJUSTMENT', $credit->credit_source->value);
        $this->assertSame('OPEN', $credit->credit_status->value);
        $this->assertStringStartsWith('CR-'.$this->company->company_id.'-', $credit->credit_no);

        $result = $this->finance->allocateCredit($credit->fresh());

        $this->assertEqualsWithDelta(30000.0, (float) $result['allocations']->sum(fn ($a) => (float) $a['amount']), 0.001);
        $this->assertSame('USED', $credit->fresh()->credit_status->value);
        $this->assertSame('0.00', (string) $credit->fresh()->remaining_amount);
        $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('0.00', (string) $invoice->fresh()->settled_amount, 'Payments did not touch this invoice.');
        $this->assertSame('30000.00', (string) $invoice->fresh()->credit_amount);
        $this->assertSame('70000.00', $this->invoices->exposure($this->secondary->customer_id)['net_exposure']);
    }

    public function test_credit_partial_use_and_over_allocation_rejection(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $credit = $this->finance->createManualCredit($this->company->company_id, $this->secondary->customer_id, '20000.00');

        try {
            $this->finance->allocateCredit($credit->fresh(), collect([$invoice->invoice_no => '25000.00']));
            $this->fail('Expected credit over-allocation to be rejected.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('remaining amount', $e->getMessage());
        }

        $result = $this->finance->allocateCredit($credit->fresh(), collect([$invoice->invoice_no => '20000.00']));
        $this->assertEqualsWithDelta(20000.0, (float) $result['allocations']->sum(fn ($a) => (float) $a['amount']), 0.001);
        $this->assertSame('USED', $credit->fresh()->credit_status->value);
        $this->assertSame('20000.00', (string) $invoice->fresh()->credit_amount);
    }

    public function test_credit_cannot_leave_company_scope_or_exceed_outstanding(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        // Credit larger than outstanding clamps to the outstanding amount.
        $credit = $this->finance->createManualCredit($this->company->company_id, $this->secondary->customer_id, '500000.00');
        $result = $this->finance->allocateCredit($credit->fresh());

        $this->assertEqualsWithDelta(100000.0, (float) $result['allocations']->sum(fn ($a) => (float) $a['amount']), 0.001);
        $this->assertSame('PARTIALLY_USED', $credit->fresh()->credit_status->value);
        $this->assertSame('400000.00', (string) $credit->fresh()->remaining_amount);
        $this->assertSame('PAID', $invoice->fresh()->payment_status->value);
    }

    // ---- Idempotency (HTTP sync scopes) -------------------------------------------

    public function test_payment_creation_replays_on_same_idempotency_key(): void
    {
        $this->actingAs($this->admin);

        $payload = [
            'customer_id' => $this->secondary->customer_id,
            'payment_method' => 'CASH',
            'amount' => '500.00',
            'payment_reference' => 'TRN-42',
            'idempotency_key' => 'pay-key-1',
        ];

        $first = $this->post(route('finance.payments.store'), $payload);
        $first->assertRedirect();

        $retry = $this->post(route('finance.payments.store'), $payload);
        $retry->assertRedirect();

        $this->assertSame(1, Payment::where('customer_id', $this->secondary->customer_id)->count(), 'No duplicate payment.');
        $this->assertSame(1, DB::table('idempotency_keys')->where('endpoint', 'payment_create')->count());
        $retry->assertSessionHas('status', fn ($status) => str_contains($status, 'idempotent replay'));
    }

    public function test_allocation_replays_on_same_idempotency_key(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '50000.00');
        $this->finance->confirmPayment($payment);

        $this->actingAs($this->admin);

        $payload = ['idempotency_key' => 'alloc-key-1'];

        $first = $this->post(route('finance.payments.allocate', $payment), $payload);
        $first->assertRedirect();

        $retry = $this->post(route('finance.payments.allocate', $payment), $payload);
        $retry->assertRedirect();

        $this->assertSame(1, PaymentAllocation::where('payment_id', $payment->payment_id)->count(), 'No duplicate allocation row.');
        $this->assertSame('50000.00', (string) $invoice->fresh()->settled_amount, 'No double settlement.');
    }

    // ---- Authorization --------------------------------------------------------------

    public function test_foreign_admin_cannot_see_records_and_sales_employee_is_read_only(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');
        $invoice = Invoice::firstOrFail();

        // A foreign company's admin sees nothing in the list…
        $foreignAdmin = AppUser::factory()->companyAdmin()->create();
        $this->actingAs($foreignAdmin)->get(route('finance.invoices.index'))
            ->assertOk()
            ->assertSee('No invoices yet', false);

        // …and gets 403 on the detail.
        $this->actingAs($foreignAdmin)->get(route('finance.invoices.show', $invoice))->assertForbidden();

        // Sales employees are read-only: money movement endpoints are forbidden.
        $payment = $this->finance->createPayment($this->company->company_id, $this->secondary->customer_id, 'CASH', '500.00');
        $this->actingAs($this->seller)->post(route('finance.payments.confirm', $payment))->assertForbidden();
        $this->actingAs($this->seller)->post(route('finance.payments.cancel', $payment))->assertForbidden();
        $this->actingAs($this->seller)->get(route('finance.payments.create'))->assertForbidden();
        $this->actingAs($this->seller)->get(route('finance.credits.create'))->assertForbidden();

        // …but they can still view their assigned debtor's invoice (403 for the statement too).
        $this->actingAs($this->seller)->get(route('finance.invoices.show', $invoice))->assertOk();
        $this->actingAs($this->seller)->get(route('finance.statement', $this->secondary))->assertOk();
    }

    public function test_statement_shows_three_exposure_values(): void
    {
        $this->receiveStock();
        $this->createInvoicedOrder('10', 'PCS', '10000.00');

        $this->actingAs($this->admin)->get(route('finance.statement', $this->secondary))
            ->assertOk()
            ->assertSee('Invoice outstanding', false)
            ->assertSee('Available credit', false)
            ->assertSee('Net exposure', false)
            ->assertSee('100,000.00', false);
    }

    // ---- Helper -----------------------------------------------------------------

    /** Ship + POD-confirm an order so an invoice exists for it. */
    private function createInvoicedOrder(string $qty, string $unit, string $price, $order = null, bool $assertSingle = true): void
    {
        $order = $order ?? $this->confirmedOrder($qty, $unit, $price);
        $delivery = $this->allocateDelivery($order, $qty, $unit);
        $this->ship($delivery);
        $this->confirmPod($delivery, $qty, $unit);

        if ($assertSingle) {
            $this->assertSame(1, Invoice::count(), 'Fixture must produce exactly one invoice.');
        }
    }
}
