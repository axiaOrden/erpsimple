<?php

namespace Tests\Feature;

use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidence;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Services\DeliveryService;
use App\Services\FinanceService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public QR invoice must let a non-app customer/Primary inspect the proof
 * of payment WITHOUT a login — and the unguessable public token must authorize
 * read-only access to THAT invoice's payments and nothing else.
 *
 * Item J: the correct token shows the payment and serves its evidence.
 * Item K: every mismatched token/payment/evidence combination is a 404.
 */
class PublicInvoiceEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private ProductMaster $product;

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
        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)
            ->create(['business_name' => 'Aaa Primary Depot']);
        $this->product = ProductMaster::factory()->forCompany($this->company)
            ->create(['product_description' => 'Vegetable Oil 1L', 'basic_unit' => 'PCS']);

        PriceCondition::create([
            'condition_price_no' => 'PC-EV-'.$this->company->company_id,
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-EV-'.$this->company->company_id,
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

        app(InventoryService::class)->adjustPhysical(
            $this->employee, $this->primary->customer_id, $this->product->product_id, '2400', 'PCS', MovementType::GOODS_RECEIPT,
        );
    }

    /** Deliver 10 CTN and accept them, so the order produces its final invoice. */
    private function deliveredInvoice(CustomerMaster $soldTo): Invoice
    {
        $orders = app(SalesOrderService::class);
        $deliveries = app(DeliveryService::class);
        $shipments = app(ShipmentService::class);
        $pod = app(PodService::class);

        $order = $orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => '10',
                'unit' => 'CTN',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $this->assertSame([], $orders->confirm($order)['conflicts']);

        $delivery = $deliveries->createAndAllocate(
            $order->fresh(['items']),
            $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN', 'transit_qty' => '0']]),
        )['delivery'];

        $shipment = $shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $shipments->attachDelivery($shipment, $delivery->delivery_no);
        $shipments->markReady($shipment);
        $shipments->start($this->employee, $shipment);

        $pod->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'CTN', DifferenceReason::NONE);

        return Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
    }

    /** Record a verified primary-routed settlement WITH photo proof. */
    private function settleWithProof(Invoice $invoice, string $amount, string $reference): Payment
    {
        $this->actingAs($this->seller)->post(route('payments.record.store', $invoice), [
            'payment_method' => 'BANK_TRANSFER_TO_PRIMARY',
            'amount' => $amount,
            'payment_reference' => $reference,
            'proof' => UploadedFile::fake()->image('transfer-receipt.jpg', 900, 1400),
            'gps_latitude' => '6.5243790',
            'gps_longitude' => '3.3792050',
            'gps_accuracy' => '9',
            'captured_at' => now()->toIso8601String(),
            'watermark_text' => 'Simple ERP | seller | invoice',
        ])->assertRedirect();

        return Payment::where('customer_id', $invoice->customer_id)->latest('payment_id')->firstOrFail();
    }

    // ---- J ------------------------------------------------------------------

    public function test_j_the_public_invoice_shows_the_payment_and_serves_its_evidence_without_a_login(): void
    {
        $invoice = $this->deliveredInvoice($this->secondaryCustomer());
        $payment = $this->settleWithProof($invoice, '10000.00', 'TRX-99887766');

        $evidence = PaymentEvidence::where('payment_id', $payment->payment_id)->firstOrFail();
        $token = app(InvoiceService::class)->publicToken($invoice);

        // No authentication whatsoever.
        $page = $this->get(route('invoice.public', $token));
        $page->assertOk();
        $page->assertSee('Payment');
        $page->assertSee('BANK TRANSFER TO PRIMARY');
        $page->assertSee('CONFIRMED');
        $page->assertSee('NGN 10,000.00');
        $page->assertSee('TRX-99887766');
        $page->assertSee('Proof of Payment');
        $page->assertSee('View receipt / evidence');
        $page->assertSee(route('invoice.public.evidence', [
            'token' => $token, 'payment' => $payment->payment_id, 'evidence' => $evidence->evidence_id,
        ]));

        // Internal storage names and the uploader's filename are never exposed.
        $page->assertDontSee($evidence->stored_path);
        $page->assertDontSee('transfer-receipt.jpg');

        $response = $this->get(route('invoice.public.evidence', [
            'token' => $token, 'payment' => $payment->payment_id, 'evidence' => $evidence->evidence_id,
        ]));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl, 'Public evidence is never cached.');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('payment-evidence.jpg', (string) $response->headers->get('Content-Disposition'));
        $this->assertNotEmpty($response->streamedContent(), 'The receipt image bytes are served.');

        // Read-only by construction: no ERP write verb is reachable on these routes.
        $this->post(route('invoice.public', $token))->assertStatus(405);
        $this->post(route('invoice.public.evidence', [
            'token' => $token, 'payment' => $payment->payment_id, 'evidence' => $evidence->evidence_id,
        ]))->assertStatus(405);
    }

    // ---- K ------------------------------------------------------------------

    public function test_k_the_public_token_authorizes_only_its_own_invoice_payment_and_evidence(): void
    {
        // Invoice A (debtor A) with a settled payment + proof.
        $invoiceA = $this->deliveredInvoice($this->secondaryCustomer());
        $paymentA = $this->settleWithProof($invoiceA, '10000.00', 'TRX-AAAA');
        $evidenceA = PaymentEvidence::where('payment_id', $paymentA->payment_id)->firstOrFail();
        $tokenA = app(InvoiceService::class)->publicToken($invoiceA);

        // Invoice B: a different debtor, its own payment + proof.
        $invoiceB = $this->deliveredInvoice($this->secondaryCustomer());
        $paymentB = $this->settleWithProof($invoiceB, '10000.00', 'TRX-BBBB');
        $evidenceB = PaymentEvidence::where('payment_id', $paymentB->payment_id)->firstOrFail();
        $tokenB = app(InvoiceService::class)->publicToken($invoiceB);

        $this->assertNotSame($tokenA, $tokenB);

        $url = fn (string $token, int $payment, int $evidence) => route('invoice.public.evidence', [
            'token' => $token, 'payment' => $payment, 'evidence' => $evidence,
        ]);

        // The correct combinations work.
        $this->get($url($tokenA, $paymentA->payment_id, $evidenceA->evidence_id))->assertOk();
        $this->get($url($tokenB, $paymentB->payment_id, $evidenceB->evidence_id))->assertOk();

        // Another invoice's payment, evidence, or both: all 404.
        $this->get($url($tokenA, $paymentB->payment_id, $evidenceB->evidence_id))->assertNotFound();
        $this->get($url($tokenA, $paymentA->payment_id, $evidenceB->evidence_id))->assertNotFound();
        $this->get($url($tokenA, $paymentB->payment_id, $evidenceA->evidence_id))->assertNotFound();
        $this->get($url($tokenB, $paymentA->payment_id, $evidenceA->evidence_id))->assertNotFound();

        // Malformed and unknown tokens never resolve (no existence hints).
        $this->get(route('invoice.public', 'not-a-token'))->assertNotFound();
        $this->get(route('invoice.public', str_repeat('a', 43)))->assertNotFound();
        $this->get($url(str_repeat('a', 43), $paymentA->payment_id, $evidenceA->evidence_id))->assertNotFound();
        $this->get($url('../../storage/app', $paymentA->payment_id, $evidenceA->evidence_id))->assertNotFound();

        // A payment of the SAME debtor that was never allocated to invoice A is
        // not this invoice's business either.
        $unallocated = app(FinanceService::class)->createPayment(
            $this->company->company_id,
            $invoiceA->customer_id,
            'BANK_TRANSFER_TO_PRIMARY',
            '500.00',
            'TRX-UNALLOCATED',
        );

        $this->assertSame(0, PaymentAllocation::where('payment_id', $unallocated->payment_id)->count());

        $strayEvidence = PaymentEvidence::create([
            'payment_id' => $unallocated->payment_id,
            'stored_path' => 'payment-evidence/stray.jpg',
            'original_name' => 'stray.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 1024,
            'uploaded_by' => $this->employee->employee_id,
        ]);

        Storage::disk('local')->put('payment-evidence/stray.jpg', 'stray');

        $this->get($url($tokenA, $unallocated->payment_id, $strayEvidence->evidence_id))->assertNotFound();

        // The other invoice's page never lists this invoice's payment either.
        $this->get(route('invoice.public', $tokenA))->assertDontSee('TRX-BBBB');
        $this->get(route('invoice.public', $tokenB))->assertDontSee('TRX-AAAA');
    }

    private function secondaryCustomer(): CustomerMaster
    {
        return CustomerMaster::factory()->forEmployee($this->employee)->create();
    }
}
