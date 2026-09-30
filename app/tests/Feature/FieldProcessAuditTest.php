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
use App\Models\DeliveryConfirmation;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\TransitStock;
use App\Services\DeliveryService;
use App\Services\FinanceService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\ProductUnitService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use App\Services\TransitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 9 audit — DEFAULT field-sales business process end to end.
 *
 * Covers the critical field-custody rule, field-stock redeployment, on-the-spot
 * orders, cumulative demand, the strict credit/exposure rule and conservation.
 * These exercise PUBLIC service paths (controller-level behavior), not raw DB
 * mutation.
 */
class FieldProcessAuditTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private AppUser $admin;

    private CustomerMaster $primary;

    private ProductMaster $product;

    private DeliveryService $deliveries;

    private ShipmentService $shipments;

    private PodService $pod;

    private TransitService $transit;

    private SalesOrderService $orders;

    private FinanceService $finance;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();
        $this->product = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);

        $conditionNo = 'PC-AUDIT-'.$this->company->company_id;
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

        $this->deliveries = app(DeliveryService::class);
        $this->shipments = app(ShipmentService::class);
        $this->pod = app(PodService::class);
        $this->transit = app(TransitService::class);
        $this->orders = app(SalesOrderService::class);
        $this->finance = app(FinanceService::class);
        $this->invoices = app(InvoiceService::class);
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

    private function allocate(SalesOrder $order, string $qty, string $unit = 'CTN', string $transitQty = '0'): Delivery
    {
        return $this->deliveries->createAndAllocate(
            $order->fresh(['items']),
            $this->employee,
            collect([[
                'sales_order_item_no' => 1,
                'qty' => $qty,
                'unit' => $unit,
                'transit_qty' => $transitQty,
            ]]),
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

    /** Seed reusable custody stock via a real POD full-rejection (no shortcuts). */
    private function seedCustody(string $qty = '5', string $unit = 'CTN'): TransitStock
    {
        $order = $this->confirmedOrder($this->newCustomer(), $qty, $unit);
        $delivery = $this->allocate($order, $qty, $unit);
        $this->ship($delivery);
        $this->pod($delivery, '0', $unit, DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        return TransitStock::where('origin_delivery_no', $delivery->delivery_no)->firstOrFail();
    }

    private function giFor(Delivery $delivery): int
    {
        return InventoryMovement::where('reference_type', 'DELIVERY')
            ->where('reference_no', $delivery->delivery_no)
            ->where('movement_type', MovementType::GOODS_ISSUE)
            ->count();
    }

    // ---- 13/14/15/16: critical field-custody rule --------------------------

    public function test_custody_stock_supplements_allocation_up_to_original_demand(): void
    {
        // Primary holds 100 CTN. The employee already has 5 CTN reusable custody
        // from an earlier rejection (a real POD chain).
        $this->receiveStock('2400');
        $this->seedCustody('5', 'CTN');
        $this->assertSame('120.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // The customer's original demand is 30 CTN.
        $order = $this->confirmedOrder($this->newCustomer(), '30', 'CTN');

        // 10 CTN from source stock.
        $deliveryA = $this->allocate($order, '10', 'CTN');
        $this->ship($deliveryA);

        // A further 5 CTN fulfilled from legitimate reusable custody — a new,
        // separate delivery; the original GI is never edited.
        $deliveryB = $this->allocate($order, '5', 'CTN', '5');
        $this->ship($deliveryB);

        // Pure-transit delivery issues NOTHING from source.
        $this->assertSame(0, $this->giFor($deliveryB));
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // The customer accepts the full 15 CTN (10 + 5).
        $this->pod($deliveryA, '10', 'CTN');
        $this->pod($deliveryB, '5', 'CTN');

        // Conservation: source on-hand dropped by exactly the stock actually
        // issued (10 CTN) — never by the 5 CTN that was issued once elsewhere.
        $this->assertSame('2040.000', (string) $this->inv()->unrestricted_qty, '2400 − 120 (earlier GI) − 240 (10 CTN)');

        // Remaining demand (15 CTN) is rejected → the single final invoice is
        // generated for the accepted 15 CTN only.
        $item = $order->fresh(['items'])->items->first();
        $this->orders->rejectItem($item, 'Customer reduced requirement', $this->employee);

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
        $invoiceItem = InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        $this->assertSame('15.000', (string) $invoiceItem->quantity, 'Only the accepted 15 CTN are invoiced.');
        $this->assertSame('15000.00', (string) $invoice->invoice_amount, '15 CTN × 1,000.');
    }

    public function test_rejected_custody_stock_redeployed_to_existing_order(): void
    {
        $this->receiveStock('2400');
        $origin = $this->seedCustody('10', 'CTN');
        $this->assertSame('240.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // Customer B already has a confirmed order.
        $orderB = $this->confirmedOrder($this->newCustomer(), '20', 'CTN');

        // Redeploy all 10 CTN to B's order as a pure-transit delivery.
        $deliveryB = $this->allocate($orderB, '10', 'CTN', '10');
        $this->ship($deliveryB);
        $this->pod($deliveryB, '10', 'CTN');

        // No second Goods Issue for the reused physical stock.
        $this->assertSame(0, $this->giFor($deliveryB));
        $this->assertSame(1, InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count(), 'Exactly one GI exists (the origin).');

        // Provenance chain intact: the reallocated child points back to the root.
        $child = TransitStock::where('transit_status', TransitStatus::REALLOCATED)->firstOrFail();
        $this->assertSame($origin->transit_id, $child->parent_transit_id);
        $this->assertSame($origin->origin_delivery_no, $child->origin_delivery_no);
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // B is invoiced for the 10 CTN it accepted.
        $this->orders->rejectItem($orderB->fresh(['items'])->items->first(), 'remainder', $this->employee);
        $invoice = Invoice::where('sales_order_no', $orderB->sales_order_no)->firstOrFail();
        $this->assertSame('10.000', (string) InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail()->quantity);
    }

    public function test_on_the_spot_order_can_use_field_custody_stock(): void
    {
        $this->receiveStock('2400');
        $this->seedCustody('8', 'CTN');

        // SO is created and CONFIRMED BEFORE any fulfillment/POD.
        $newCustomer = $this->newCustomer();
        $order = $this->confirmedOrder($newCustomer, '8', 'CTN');
        $this->assertSame('CONFIRMED', $order->order_status->value);

        $delivery = $this->allocate($order, '8', 'CTN', '8');
        $this->ship($delivery);
        $this->pod($delivery, '8', 'CTN');

        $this->assertSame(0, $this->giFor($delivery));
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
        $this->assertSame($newCustomer->customer_id, $invoice->customer_id);
        $this->assertSame('8.000', (string) InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail()->quantity);
    }

    public function test_mixed_delivery_pod_invoices_the_combined_quantity_only(): void
    {
        $this->receiveStock('2400');
        $this->seedCustody('2', 'CTN'); // 48 PCS reusable custody

        $order = $this->confirmedOrder($this->newCustomer(), '10', 'CTN');

        // ONE delivery item backed by 8 CTN stock + 2 CTN custody.
        $delivery = $this->allocate($order, '10', 'CTN', '2');
        $this->ship($delivery);

        // START issued only the stock portion (8 CTN = 192 PCS).
        $gi = InventoryMovement::where('reference_no', $delivery->delivery_no)
            ->where('movement_type', MovementType::GOODS_ISSUE)->get();
        $this->assertCount(1, $gi);
        $this->assertSame('-192.000', (string) $gi->first()->quantity);

        // The customer accepts all 10 CTN → invoiced for the combined quantity.
        $this->pod($delivery, '10', 'CTN');

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();
        $this->assertSame('10.000', (string) InvoiceItem::where('invoice_no', $invoice->invoice_no)->firstOrFail()->quantity);
        $this->assertSame('10000.00', (string) $invoice->invoice_amount);
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));
    }

    // ---- Default UI flow: custody-only redeployment must be offered --------

    public function test_delivery_create_page_offers_transit_backing_when_source_stock_is_empty(): void
    {
        // All primary stock is issued into the employee's custody (10 CTN
        // rejected at the customer) — the source now holds ZERO unrestricted.
        $this->receiveStock('240');
        $seedOrder = $this->confirmedOrder($this->newCustomer(), '10', 'CTN');
        $seedDelivery = $this->allocate($seedOrder, '10', 'CTN');
        $this->ship($seedDelivery);
        $this->pod($seedDelivery, '0', 'CTN', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        $this->assertSame('0.000', (string) $this->inv()->unrestricted_qty);
        $this->assertSame('240.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // A customer with existing confirmed demand can still be fulfilled from
        // custody — the DEFAULT delivery screen must offer the transit option
        // even though the source has no unrestricted stock left.
        $order = $this->confirmedOrder($this->newCustomer(), '10', 'CTN');

        $response = $this->actingAs($this->seller)->get(route('deliveries.create', $order));

        $response->assertOk();
        $response->assertSee('from transit', false);
        $response->assertSee('transit_qty', false);
    }

    // ---- 5/6/7: partial, multiple deliveries, cumulative cap ---------------

    public function test_cumulative_fulfillment_cannot_exceed_original_demand(): void
    {
        $this->receiveStock('2400');
        $order = $this->confirmedOrder($this->newCustomer(), '30', 'CTN');

        $first = $this->allocate($order, '15', 'CTN');
        $this->ship($first);
        $this->pod($first, '15', 'CTN');

        // A second attempt to over-fulfil (20 CTN > remaining 15) is rejected.
        try {
            $this->allocate($order, '20', 'CTN');
            $this->fail('Cumulative fulfillment must never exceed the original demand.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // The exact remaining demand is still allocatable.
        $second = $this->allocate($order, '15', 'CTN');
        $this->ship($second);
        $this->pod($second, '15', 'CTN');

        $this->assertEqualsWithDelta(
            720.0,
            (float) DeliveryConfirmation::whereIn('delivery_no', [$first->delivery_no, $second->delivery_no])->get()
                ->sum(fn ($c) => (float) app(ProductUnitService::class)->toBasicById($this->product->product_id, (string) $c->confirmed_qty, (string) $c->confirmed_unit)),
            0.001,
        );
    }

    // ---- 28/29/30: strict credit rule -------------------------------------

    public function test_unpaid_debt_blocks_new_allocation_and_start_but_not_dispatched_pod(): void
    {
        $this->receiveStock('2400');
        $debtor = $this->newCustomer();

        // All orders are confirmed BEFORE any debt exists (debt arises from POD).
        $o1 = $this->confirmedOrder($debtor, '10', 'CTN');
        $o2 = $this->confirmedOrder($debtor, '5', 'CTN');
        $o3 = $this->confirmedOrder($debtor, '5', 'CTN');
        $o4 = $this->confirmedOrder($debtor, '5', 'CTN');

        // o3 is allocated but NOT dispatched before the debt is known.
        $d3 = $this->allocate($o3, '5', 'CTN');

        // o1 and o2 are physically dispatched.
        $d1 = $this->allocate($o1, '10', 'CTN');
        $this->ship($d1);
        $d2 = $this->allocate($o2, '5', 'CTN');
        $this->ship($d2);

        // POD of o1 creates the unpaid invoice → debt.
        $this->pod($d1, '10', 'CTN');
        $invoice = Invoice::where('sales_order_no', $o1->sales_order_no)->firstOrFail();
        $this->assertSame('UNPAID', $invoice->payment_status->value);
        $this->assertSame('10000.00', $this->invoices->exposure($debtor->customer_id)['net_exposure']);

        // 29 — already-dispatched stock can still be accounted for (POD works),
        // even with a difference. The difference becomes traceable transit and
        // the return workflow stays fully available despite the debt.
        $this->pod($d2, '3', 'CTN', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);
        $this->assertSame('PARTIALLY_DELIVERED', $d2->fresh()->delivery_status->value);

        $transitRow = TransitStock::where('origin_delivery_no', $d2->delivery_no)->firstOrFail();
        $this->assertSame('48.000', (string) $transitRow->quantity, '2 of the 5 CTN stayed with the employee.');

        $beforeReturn = (float) $this->inv()->unrestricted_qty;
        $this->transit->initiateReturn($this->employee, $transitRow);
        $this->transit->verifySourceReceipt($this->admin, $transitRow->fresh());
        $this->assertEqualsWithDelta($beforeReturn + 48, (float) $this->inv()->unrestricted_qty, 0.001, 'Verified return restored the source while the debtor was blocked.');

        // 28 — NEW allocation while the debt stands is blocked.
        try {
            $this->allocate($o4, '5', 'CTN');
            $this->fail('A debtor with outstanding debt must not receive a new allocation.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // 28 — STARTing a pre-existing allocation is blocked too.
        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $d3->delivery_no);
        $this->shipments->markReady($shipment);

        try {
            $this->shipments->start($this->employee, $shipment);
            $this->fail('START must be blocked while the debtor has outstanding debt.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // The blocked allocation is safe to release (not stranded): the READY
        // shipment returns to DRAFT first, then the allocation is released.
        $this->shipments->backToDraft($shipment->fresh());
        $this->deliveries->releaseDelivery($d3->fresh());
        $this->assertSame('DRAFT', $d3->fresh()->delivery_status->value);
    }

    // ---- 3: company / employee-product scope enforcement ------------------

    public function test_employee_product_scope_is_enforced_at_order_capture(): void
    {
        // Scope the employee to a DIFFERENT product only.
        $otherProduct = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        EmployeeProduct::create(['employee_id' => $this->employee->employee_id, 'product_id' => $otherProduct->product_id]);

        $this->actingAs($this->seller)->post('/orders', [
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->newCustomer()->customer_id,
            'action' => 'draft',
            'lines' => [['product_id' => $this->product->product_id, 'qty' => '5', 'unit' => 'CTN']],
        ])->assertForbidden();

        $this->assertSame(0, SalesOrder::count(), 'An out-of-scope product must never yield an order.');
    }

    public function test_foreign_company_product_cannot_be_ordered(): void
    {
        $otherCompany = CompanyMaster::factory()->create();
        $foreignProduct = ProductMaster::factory()->forCompany($otherCompany)->create(['basic_unit' => 'PCS']);

        $this->actingAs($this->seller)->post('/orders', [
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->newCustomer()->customer_id,
            'action' => 'draft',
            'lines' => [['product_id' => $foreignProduct->product_id, 'qty' => '5', 'unit' => 'PCS']],
        ])->assertStatus(422);

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_payment_settles_debt_and_customer_transacts_again(): void
    {
        $this->receiveStock('2400');
        $debtor = $this->newCustomer();

        $o1 = $this->confirmedOrder($debtor, '10', 'CTN');
        $d1 = $this->allocate($o1, '10', 'CTN');
        $this->ship($d1);
        $this->pod($d1, '10', 'CTN');

        // Debt exists.
        $this->assertSame('10000.00', $this->invoices->exposure($debtor->customer_id)['net_exposure']);
        $blocked = $this->orders->confirm($this->orders->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id, $debtor->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => '5', 'unit' => 'CTN', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order']);
        $this->assertNotEmpty($blocked['conflicts']);

        // The debt is settled in full.
        $payment = $this->finance->createPayment($this->company->company_id, $debtor->customer_id, 'CASH', '10000.00');
        $this->finance->confirmPayment($payment);
        $this->finance->allocatePayment($payment->fresh());

        $this->assertSame('0.00', $this->invoices->exposure($debtor->customer_id)['net_exposure']);

        // The customer can transact again — confirm + allocate.
        $o2 = $this->confirmedOrder($debtor, '5', 'CTN');
        $d2 = $this->allocate($o2, '5', 'CTN');
        $this->assertSame('ALLOCATED', $d2->delivery_status->value);
    }
}
