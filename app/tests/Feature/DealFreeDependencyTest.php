<?php

namespace Tests\Feature;

use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderRejectionReason;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ProvidesRejectionReasons;
use Tests\TestCase;

/**
 * DEAL/free items are DEPENDENT fulfilment, never independent demand.
 *
 * Coverage requested by the correction pass (letters as in the brief):
 *   A  parent rejected before allocation  ⇒ child free cannot allocate;
 *   B  deal 12→1, parent allocated 6      ⇒ free 1 refused;
 *   C  deal 12→1, parent allocated 12     ⇒ free 1 allowed (and never more);
 *   D  deal 24→2 split 12+1 / 12+1        ⇒ valid;
 *   E  free quantity can never move ahead of the parent (6+1 refused,
 *      free-only second delivery refused);
 *   F  remaining parent demand rejected   ⇒ excess free demand closes with
 *      the SYSTEM_DEFAULT reason;
 *   G  dispatched free quantity survives the parent rejection for
 *      POD/custody accountability;
 *   H  invoice = POD accepted paid qty + zero-valued accepted free qty,
 *      never the ordered quantity;
 *   I  free-only malformed historical delivery never bills the parent;
 *   L  the rejection dropdown never offers SYSTEM_DEFAULT;
 *   M  automatic dependent closure is recorded with SYSTEM_DEFAULT.
 */
class DealFreeDependencyTest extends TestCase
{
    use DatabaseTransactions;
    use ProvidesRejectionReasons;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $this->employee->employee_id,
        ]);

        // The SELLER side (stock holder + supplying Primary) and the DEBTOR.
        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)
            ->create(['business_name' => 'Aaa Primary Depot']);
        $this->secondary = CustomerMaster::factory()->forEmployee($this->employee)
            ->create(['business_name' => 'Aaa Secondary Shop']);

        $this->product = ProductMaster::factory()->forCompany($this->company)
            ->create(['product_description' => 'Vegetable Oil 1L', 'basic_unit' => 'PCS']);

        PriceCondition::create([
            'condition_price_no' => 'PC-DEP-'.$this->company->company_id,
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-DEP-'.$this->company->company_id,
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

        $this->receiveStock('2400');
    }

    // ---- helpers -----------------------------------------------------------

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

    /**
     * One trade deal for the test product: "qualifierQty qualifierUnit →
     * rewardQty rewardUnit". `for_each` repeats the reward per multiple.
     */
    private function deal(
        string $dealNo,
        string $qualifierQty,
        string $qualifierUnit,
        string $rewardQty,
        string $rewardUnit,
        ?string $forEachQty = null,
        ?string $forEachUnit = null,
    ): void {
        DealCondition::create([
            'deal_no' => $dealNo,
            'company_id' => $this->company->company_id,
            'deal_description' => 'test deal '.$dealNo,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => $dealNo,
            'product_id' => $this->product->product_id,
            'minimum_qty' => $qualifierQty,
            'qualifier_unit' => $qualifierUnit,
        ]);
        DealReward::create([
            'deal_no' => $dealNo,
            'product_id' => $this->product->product_id,
            'reward_qty' => $rewardQty,
            'reward_unit' => $rewardUnit,
            'for_each_qty' => $forEachQty,
            'for_each_unit' => $forEachUnit,
        ]);
    }

    /** Confirmed order for `qty` of the product; deals add their free lines. */
    private function confirmedOrder(string $qty, string $unit = 'PCS'): SalesOrder
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

        $result = $this->orders->confirm($order);

        $this->assertSame([], $result['conflicts'], 'Order confirmation must not conflict in this fixture.');

        return $order->fresh(['items']);
    }

    private function item(SalesOrder $order, int $itemNo): SalesOrderItem
    {
        return SalesOrderItem::where('sales_order_no', $order->sales_order_no)
            ->where('item_no', $itemNo)
            ->firstOrFail();
    }

    /** @param array<int, array{0: int, 1: string, 2?: string}> $lines [soItemNo, qty, unit] */
    private function allocate(SalesOrder $order, array $lines): Delivery
    {
        return $this->deliveries->createAndAllocate(
            $order->fresh(['items']),
            $this->employee,
            collect($lines)->map(fn (array $line) => [
                'sales_order_item_no' => $line[0],
                'qty' => $line[1],
                'unit' => $line[2] ?? 'PCS',
                'transit_qty' => '0',
            ]),
        )['delivery'];
    }

    /** The 422 a crafted/service allocation is refused with. */
    private function assertAllocationRefused(SalesOrder $order, array $lines, string $needle): void
    {
        try {
            $this->allocate($order, $lines);
            $this->fail('Allocation should have been refused ('.$needle.').');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    private function reject(SalesOrderItem $item, string $code = SalesOrderRejectionReason::CODE_CUSTOMER_REQUEST): SalesOrderItem
    {
        return $this->orders->rejectItem($this->item($item->salesOrder, (int) $item->item_no), $this->reason($code), $this->employee);
    }

    private function ship(Delivery $delivery): void
    {
        $shipment = $this->shipments->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($this->employee, $shipment);
    }

    /** POD one delivery item addressed by its SO item number. */
    private function podItem(
        Delivery $delivery,
        int $soItemNo,
        string $confirmedQty,
        string $unit = 'PCS',
        DifferenceReason $reason = DifferenceReason::NONE,
        ?DifferenceDisposition $disposition = null,
    ): void {
        $row = DeliveryItem::where('delivery_no', $delivery->delivery_no)
            ->where('sales_order_item_no', $soItemNo)
            ->firstOrFail();

        $this->pod->confirmItem(
            $this->employee, $delivery->delivery_no, (int) $row->item_no, $confirmedQty, $unit, $reason, null, $disposition,
        );
    }

    // ---- A ------------------------------------------------------------------

    public function test_a_rejecting_the_parent_before_allocation_leaves_the_free_line_unallocatable(): void
    {
        $this->deal('D-A', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $parent = $this->item($order, 1);
        $free = $this->item($order, 2);

        $this->assertTrue($free->is_free_item);
        $this->assertSame(1, (int) $free->parent_item_no);

        $this->reject($parent);

        // The dependent free demand closed WITH the parent's terminal rejection.
        $this->assertSame('REJECTED', $free->fresh()->rejection_status->value);
        $this->assertSame('SYSTEM_DEFAULT', $free->fresh()->rejectionReason->reason_code);

        $this->assertAllocationRefused($order, [[2, '1', 'PCS']], 'was rejected');

        $this->assertSame(0, Delivery::count(), 'No delivery may be created for the closed free line.');

        // Crafted/legacy state: the free line is put back to "not rejected"
        // WITHOUT any earned entitlement (its parent is terminally rejected and
        // holds no allocation). The allocation layer must still refuse it.
        DB::table('sales_order_item')
            ->where('sales_order_no', $order->sales_order_no)
            ->where('item_no', 2)
            ->update(['rejection_status' => 'NONE', 'rejection_reason_id' => null, 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null]);

        $this->assertAllocationRefused($order, [[2, '1', 'PCS']], 'deal entitlement');
        $this->assertSame(0, Delivery::count(), 'A crafted free-line request never produces a delivery.');
    }

    // ---- B / C --------------------------------------------------------------

    public function test_b_the_free_line_is_refused_while_the_parent_has_not_earned_the_deal_multiple(): void
    {
        $this->deal('D-B', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        // 6 parent PCS: below the 12 PCS qualifier ⇒ 0 free.
        $this->allocate($order, [[1, '6', 'PCS']]);

        $this->assertAllocationRefused($order, [[2, '1', 'PCS']], 'deal entitlement');

        $context = $this->deliveries->allocationContext($order->fresh(['items']))
            ->firstWhere(fn (array $row) => (int) $row['item_no'] === 2);

        $this->assertSame('0.000', $context['dependency']['effective_remaining_basic']);
        $this->assertStringContainsString('free currently eligible 0', $context['dependency_note']);
        $this->assertStringContainsString('parent fulfilled/allocated 6 / 12 PCS', $context['dependency_note']);
        $this->assertSame('0.000', $context['remaining_basic'], 'The free line advertises no independent demand.');
    }

    public function test_c_the_free_line_is_allowed_once_and_only_once_the_parent_earned_it(): void
    {
        $this->deal('D-C', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $this->allocate($order, [[1, '12', 'PCS']]);

        $delivery = $this->allocate($order, [[2, '1', 'PCS']]);

        $row = DeliveryItem::where('delivery_no', $delivery->delivery_no)->where('sales_order_item_no', 2)->firstOrFail();
        $this->assertTrue((bool) $row->is_free_item);
        $this->assertSame('1.000', (string) $row->allocated_qty);
        $this->assertSame('PCS', $row->delivery_unit);

        // A crafted over-request (a legacy/edited free line asking for 2 PCS
        // when the deal for 12 earned 1) is refused, and nothing is allocated.
        DB::table('sales_order_item')
            ->where('sales_order_no', $order->sales_order_no)
            ->where('item_no', 2)
            ->update(['order_qty' => '2.000']);

        $this->assertAllocationRefused($order, [[2, '1', 'PCS']], 'deal entitlement');
        $this->assertSame(1, DeliveryItem::where('sales_order_no', $order->sales_order_no)
            ->where('sales_order_item_no', 2)->count());
    }

    // ---- D ------------------------------------------------------------------

    public function test_d_a_two_for_one_deal_is_earned_across_two_deliveries_without_duplication(): void
    {
        // 12 PCS → 1 PCS free, repeating per 12 PCS: a 24 PCS order earns 2.
        $this->deal('D-D', '12', 'PCS', '1', 'PCS', '12', 'PCS');
        $order = $this->confirmedOrder('24', 'PCS');

        $freeLines = $order->items->where('is_free_item', true)->sortBy('item_no')->values();
        $this->assertCount(2, $freeLines, 'A repeated deal emits one free line per earned reward.');
        $this->assertSame(2, (int) $freeLines[0]->item_no);
        $this->assertSame(3, (int) $freeLines[1]->item_no);

        $first = $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);
        $second = $this->allocate($order, [[1, '12', 'PCS'], [3, '1', 'PCS']]);

        foreach ([$first, $second] as $delivery) {
            $this->assertSame('ALLOCATED', $delivery->delivery_status->value);
            $this->assertSame(1, DeliveryItem::where('delivery_no', $delivery->delivery_no)
                ->where('product_id', $this->product->product_id)->where('is_free_item', true)->count());
        }

        // 24 parent PCS allocated in total, 2 free PCS: exactly the entitlement.
        $this->assertSame('1.000', $this->deliveries->allocatedBasicQty($this->item($order, 2)));
        $this->assertSame('1.000', $this->deliveries->allocatedBasicQty($this->item($order, 3)));
        $this->assertAllocationRefused($order, [[3, '1', 'PCS']], 'deal entitlement');
    }

    // ---- E ------------------------------------------------------------------

    public function test_e_free_quantity_can_never_move_ahead_of_its_parent(): void
    {
        $this->deal('D-E', '12', 'PCS', '1', 'PCS', '12', 'PCS');
        $order = $this->confirmedOrder('24', 'PCS');

        // 6 parent + 1 free in ONE delivery: the parent half does not earn it.
        $this->assertAllocationRefused($order, [[1, '6', 'PCS'], [2, '1', 'PCS']], 'deal entitlement');
        $this->assertSame(0, Delivery::count());

        $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);

        // A second free line with NO further parent quantity: refused.
        $this->assertAllocationRefused($order, [[3, '1', 'PCS']], 'deal entitlement');

        // The parent half then earns the second reward — and only then.
        $delivery = $this->allocate($order, [[1, '12', 'PCS'], [3, '1', 'PCS']]);

        $this->assertSame('ALLOCATED', $delivery->delivery_status->value);
        $this->assertSame(2, DeliveryItem::where('sales_order_no', $order->sales_order_no)
            ->where('is_free_item', true)->count());
    }

    // ---- F / M --------------------------------------------------------------

    public function test_f_rejecting_the_remaining_parent_demand_closes_only_the_unearned_free_demand(): void
    {
        $this->deal('D-F', '12', 'PCS', '1', 'PCS', '12', 'PCS');
        $order = $this->confirmedOrder('24', 'PCS');

        $delivered = $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);

        // Remaining 12 parent PCS are terminally rejected.
        $this->reject($this->item($order, 1));

        $freeDelivered = $this->item($order, 2);
        $freeExcess = $this->item($order, 3);

        // The DISPATCHED free line keeps its (earned) allocation untouched.
        $this->assertSame('NONE', $freeDelivered->rejection_status->value);
        $this->assertSame(1, DeliveryItem::where('delivery_no', $delivered->delivery_no)
            ->where('sales_order_item_no', 2)->count());

        // The EXCESS free demand is closed automatically with SYSTEM_DEFAULT.
        $this->assertSame('REJECTED', $freeExcess->rejection_status->value);
        $this->assertSame('SYSTEM_DEFAULT', $freeExcess->rejectionReason->reason_code);
        $this->assertNotNull($freeExcess->rejected_at);
        $this->assertSame($this->employee->employee_id, $freeExcess->rejected_by);

        $this->assertAllocationRefused($order, [[3, '1', 'PCS']], 'was rejected');

        // The parent's own reason stays the employee's choice.
        $this->assertSame('CUSTOMER_REQUEST', $this->item($order, 1)->rejectionReason->reason_code);
        $this->assertSame('PARTIALLY_REJECTED', $order->fresh()->order_status->value);
    }

    public function test_m_automatic_closure_uses_the_automation_only_reason(): void
    {
        $this->deal('D-M', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $this->reject($this->item($order, 1), SalesOrderRejectionReason::CODE_UNAVAILABLE_STOCK);

        $free = $this->item($order, 2);

        $this->assertSame('System Default', $free->rejection_reason, 'The readable snapshot names the system reason.');
        $this->assertSame(
            SalesOrderRejectionReason::systemDefault()->reason_id,
            (int) $free->rejection_reason_id,
        );
        $this->assertTrue($free->isSystemRejected());
        $this->assertFalse(SalesOrderRejectionReason::systemDefault()->user_selectable);

        // The employee's own rejection keeps its CODE, not the label.
        $this->assertSame(
            SalesOrderRejectionReason::CODE_UNAVAILABLE_STOCK,
            $this->item($order, 1)->rejectionReason->reason_code,
        );
    }

    // ---- G ------------------------------------------------------------------

    public function test_g_dispatched_free_quantity_survives_the_parent_rejection_for_pod_accountability(): void
    {
        $this->deal('D-G', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $delivery = $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);
        $this->ship($delivery);

        // The parent's remaining demand is terminally rejected AFTER dispatch:
        // it must not pretend the physically issued free stock disappeared.
        $this->reject($this->item($order, 1));

        $free = $this->item($order, 2);
        $this->assertSame('NONE', $free->rejection_status->value, 'Dispatched free demand is not closed by the parent rejection.');
        $this->assertSame(1, DeliveryItem::where('delivery_no', $delivery->delivery_no)
            ->where('sales_order_item_no', 2)->count(), 'Goods Issue history is never erased.');

        $freeRow = DeliveryItem::where('delivery_no', $delivery->delivery_no)
            ->where('sales_order_item_no', 2)
            ->firstOrFail();

        $this->podItem($delivery, 2, '1', 'PCS');

        $this->assertSame(1, DB::table('delivery_confirmation')
            ->where('delivery_no', $delivery->delivery_no)
            ->where('delivery_item_no', (int) $freeRow->item_no)
            ->count(), 'The dispatched free line still goes through POD/custody.');
    }

    // ---- H / I --------------------------------------------------------------

    public function test_h_the_invoice_carries_only_the_pod_accepted_paid_quantity_and_a_zero_valued_free_line(): void
    {
        $this->deal('D-H', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $delivery = $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);
        $this->ship($delivery);

        // The customer accepts 6 paid PCS and the entitled 1 free PCS.
        $this->podItem($delivery, 1, '6', 'PCS', DifferenceReason::SHORT_DELIVERY, DifferenceDisposition::UNKNOWN);
        $this->podItem($delivery, 2, '1', 'PCS');

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();

        $this->assertSame('6000.00', (string) $invoice->invoice_amount, 'Never the 12,000.00 ordered value.');
        $this->assertSame('6000.00', (string) $invoice->gross_amount);
        $this->assertCount(2, $invoice->items);

        $paid = $invoice->items->firstWhere('sales_order_item_no', 1);
        $free = $invoice->items->firstWhere('sales_order_item_no', 2);

        $this->assertSame('6.000', (string) $paid->quantity);
        $this->assertSame('1000.00', (string) $paid->unit_price);
        $this->assertSame('6000.00', (string) $paid->subtotal_amount);
        $this->assertFalse((bool) $paid->is_free_item);

        $this->assertSame('1.000', (string) $free->quantity, 'Accepted free quantity stays visible for transparency.');
        $this->assertSame('0.00', (string) $free->unit_price);
        $this->assertSame('0.00', (string) $free->subtotal_amount);
        $this->assertTrue((bool) $free->is_free_item);

        // Custody: the POD difference became traceable transit stock, and the
        // free line never touched money.
        $this->assertSame('0.00', (string) $invoice->discount_amount);
        $this->assertSame('0.00', (string) $invoice->tax_amount);
    }

    public function test_i_a_free_only_malformed_delivery_can_never_bill_the_parent_value(): void
    {
        $this->deal('D-I', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $delivery = $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);

        // Historical malformed state: the paid parent's delivery row is gone,
        // only the free line was ever dispatched (the state the pre-fix bug
        // could produce).
        DeliveryItem::where('delivery_no', $delivery->delivery_no)
            ->where('sales_order_item_no', 1)
            ->delete();

        $this->ship($delivery->fresh(['items']));
        $this->podItem($delivery, 2, '1', 'PCS');

        // The parent's remaining demand is rejected afterwards (the customary
        // human disposition for such a stuck order).
        $this->reject($this->item($order, 1));

        $invoice = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail();

        $this->assertSame('0.00', (string) $invoice->invoice_amount, 'A free-only delivery bills 0.00, never the parent value.');
        $this->assertSame('0.00', (string) $invoice->gross_amount);
        $this->assertCount(1, $invoice->items);

        $line = $invoice->items->first();
        $this->assertSame(2, (int) $line->sales_order_item_no);
        $this->assertSame('1.000', (string) $line->quantity);
        $this->assertSame('0.00', (string) $line->unit_price);
        $this->assertSame('0.00', (string) $line->subtotal_amount);

        // The parent line itself contributed nothing: no shipped row exists.
        $this->assertSame('0.000', app(InvoiceService::class)->invoiceableBasicQty($this->item($order, 1)));
    }

    // ---- L ------------------------------------------------------------------

    public function test_l_the_rejection_dropdown_never_offers_the_system_reason(): void
    {
        $this->deal('D-L', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $response = $this->actingAs($this->seller)->get(route('orders.show', $order));
        $response->assertOk();

        $response->assertSee('Customer Request');
        $response->assertSee('Unavailable Stock');
        $response->assertDontSee('System Default');

        // Server-side: posting the automation-only reason is a validation error.
        $this->actingAs($this->seller)->post(
            route('orders.items.reject', ['order' => $order->sales_order_no, 'itemNo' => 1]),
            ['rejection_reason_id' => SalesOrderRejectionReason::systemDefault()->reason_id],
        )->assertSessionHasErrors('rejection_reason_id');

        $this->assertSame('NONE', $this->item($order, 1)->rejection_status->value);

        // Free text is no longer accepted at all.
        $this->actingAs($this->seller)->post(
            route('orders.items.reject', ['order' => $order->sales_order_no, 'itemNo' => 1]),
            ['rejection_reason' => 'Just feel like it'],
        )->assertSessionHasErrors('rejection_reason_id');

        $this->assertSame('NONE', $this->item($order, 1)->rejection_status->value);

        // A user-selectable reason goes through and records the snapshot.
        $this->actingAs($this->seller)->post(
            route('orders.items.reject', ['order' => $order->sales_order_no, 'itemNo' => 1]),
            ['rejection_reason_id' => $this->reason(SalesOrderRejectionReason::CODE_CUSTOMER_REQUEST)->reason_id],
        )->assertRedirect();

        $this->assertSame('REJECTED', $this->item($order, 1)->rejection_status->value);
        $this->assertSame('Customer Request', $this->item($order, 1)->rejection_reason);
    }

    // ---- unit genericity ----------------------------------------------------

    public function test_deal_entitlement_is_derived_from_maintained_units_not_hard_coded_pcs(): void
    {
        // Qualifier 1 CTN (24 PCS), reward 2 PCS repeated per CTN: the
        // entitlement basis is converted through the EXISTING unit conversion
        // of the qualifier product — no PCS constant anywhere.
        $this->deal('D-U', '1', 'CTN', '2', 'PCS', '1', 'CTN');
        $order = $this->confirmedOrder('48', 'PCS');

        $this->assertSame('2.000', (string) $this->item($order, 2)->order_qty, 'The reward ships in its maintained unit.');
        $this->assertCount(2, $order->items->where('is_free_item', true));

        // 12 PCS allocated < 1 CTN: nothing earned yet.
        $this->allocate($order, [[1, '12', 'PCS']]);
        $this->assertAllocationRefused($order, [[2, '2', 'PCS']], 'deal entitlement');

        // 24 PCS = exactly one CTN ⇒ the first 2 PCS reward is earned.
        $this->allocate($order, [[1, '12', 'PCS']]);
        $first = $this->allocate($order, [[2, '2', 'PCS']]);

        $this->assertSame('ALLOCATED', $first->delivery_status->value);
        $this->assertSame('2.000', (string) DeliveryItem::where('delivery_no', $first->delivery_no)
            ->where('sales_order_item_no', 2)->value('allocated_qty'));

        // The second reward still needs its own CTN of parent quantity.
        $this->assertAllocationRefused($order, [[3, '2', 'PCS']], 'deal entitlement');

        $second = $this->allocate($order, [[1, '24', 'PCS'], [3, '2', 'PCS']]);

        $this->assertSame('ALLOCATED', $second->delivery_status->value);
    }

    public function test_free_allocation_cannot_draw_stock_beyond_the_entitlement_or_leave_the_source_reserved(): void
    {
        $this->deal('D-S', '12', 'PCS', '1', 'PCS');
        $order = $this->confirmedOrder('12', 'PCS');

        $before = $this->inv();

        $this->assertAllocationRefused($order, [[2, '1', 'PCS']], 'deal entitlement');

        $after = $this->inv();

        $this->assertSame((string) $before->unrestricted_qty, (string) $after->unrestricted_qty);
        $this->assertSame((string) $before->restricted_qty, (string) $after->restricted_qty);

        $this->allocate($order, [[1, '12', 'PCS'], [2, '1', 'PCS']]);

        $final = $this->inv();
        $this->assertSame('2387.000', (string) $final->unrestricted_qty);
        $this->assertSame('13.000', (string) $final->restricted_qty);
        $this->assertSame('2400.000', $final->onHandQty(), 'ON HAND is conserved across allocation and POD.');
    }
}
