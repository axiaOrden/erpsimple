<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderRejectionReason;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ProvidesRejectionReasons;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use DatabaseTransactions;
    use ProvidesRejectionReasons;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $shipTo;

    private CustomerMaster $secondary;

    private ProductMaster $product;

    private SalesOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        // Supplying eligibility: the employee supplies only through Primaries
        // assigned to them (customer_employee) — enforced server-side.
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

        PriceCondition::create([
            'condition_price_no' => 'PC-STD',
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-STD',
            'product_id' => $this->product->product_id,
            'price' => '500.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $this->service = app(SalesOrderService::class);
    }

    private function lines(string $qty = '10', string $unit = 'CTN', ?string $price = null, ?string $reason = null): Collection
    {
        return collect([[
            'product_id' => $this->product->product_id,
            'qty' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
            'price_override_reason' => $reason,
        ]]);
    }

    private function inventorySnapshot(): array
    {
        $row = Inventory::where('customer_id', $this->primary->customer_id)
            ->where('product_id', $this->product->product_id)->first();

        $movements = InventoryMovement::count();

        return [
            'row' => $row?->unrestricted_qty.'|'.$row?->restricted_qty,
            'movements' => $movements,
        ];
    }

    // ---- Draft & customer semantics -----------------------------------------

    public function test_creates_draft_with_correct_customer_semantics(): void
    {
        $result = $this->service->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->shipTo->customer_id, // SHIP_TO of the primary — valid
            $this->secondary->customer_id,
            $this->lines(),
        );

        $order = $result['order'];

        $this->assertSame($this->primary->customer_id, $order->supplying_customer_id);
        $this->assertSame($this->shipTo->customer_id, $order->source_customer_id);
        $this->assertSame($this->secondary->customer_id, $order->sold_to_customer_id);
        $this->assertSame('DRAFT', $order->order_status->value);
        $this->assertNotNull($order->sales_order_no);
    }

    public function test_source_of_other_primary_is_rejected(): void
    {
        $otherPrimary = CustomerMaster::factory()->primary()->create();
        $foreignShipTo = CustomerMaster::factory()->create([
            'customer_type' => CustomerType::SHIP_TO,
            'parent_customer_id' => $otherPrimary->customer_id,
        ]);

        $this->expectException(HttpException::class);

        $this->service->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $foreignShipTo->customer_id, // belongs to another primary
            $this->secondary->customer_id,
            $this->lines(),
        );
    }

    public function test_unassigned_sold_to_customer_is_rejected(): void
    {
        $stranger = CustomerMaster::factory()->create();

        $this->expectException(HttpException::class);

        $this->service->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $stranger->customer_id,
            $this->lines(),
        );
    }

    public function test_employee_not_assigned_to_primary_can_still_order_from_it(): void
    {
        // The employee is assigned to the Secondary only — that's enough.
        $result = $this->service->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $this->secondary->customer_id,
            $this->lines(),
        );

        $this->assertSame('DRAFT', $result['order']->order_status->value);
    }

    // ---- Pricing --------------------------------------------------------------

    public function test_recommended_price_is_snapshotted(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines('10', 'CTN'),
        )['order'];

        $item = $order->items->first();

        $this->assertSame('500.00', (string) $item->recommended_price);
        $this->assertSame('500.00', (string) $item->unit_price);
        $this->assertFalse($item->price_overridden);
        $this->assertSame('PC-STD', $item->condition_price_no);
    }

    public function test_override_requires_reason(): void
    {
        $this->expectException(HttpException::class);

        $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id,
            $this->lines('10', 'CTN', '450.00', ''), // override without reason
        );
    }

    public function test_override_preserves_reason_and_recommended_price(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id,
            $this->lines('10', 'CTN', '450.00', 'DISTRIBUTOR_OWN_PRICE'),
        )['order'];

        $item = $order->items->first();

        $this->assertTrue($item->price_overridden);
        $this->assertSame('450.00', (string) $item->unit_price);
        $this->assertSame('500.00', (string) $item->recommended_price);
        $this->assertSame('DISTRIBUTOR_OWN_PRICE', $item->price_override_reason);

        $this->expectException(HttpException::class);

        $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id,
            $this->lines('10', 'CTN', '450.00', 'Typed free-text reason'),
        );
    }

    public function test_later_price_master_change_does_not_touch_order_snapshot(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        $this->service->confirm($order);

        // Master price changes AFTER confirmation.
        PriceConditionItem::where('condition_price_no', 'PC-STD')
            ->where('product_id', $this->product->product_id)
            ->update(['price' => '999.99']);

        $item = $order->items()->first();

        $this->assertSame('500.00', (string) $item->unit_price, 'Historical order pricing must not change.');
        $this->assertSame('500.00', (string) $item->recommended_price);
    }

    // ---- Deals ------------------------------------------------------------------

    public function test_qualifying_deal_adds_free_line_on_confirm(): void
    {
        DealCondition::create([
            'deal_no' => 'D-X',
            'company_id' => $this->company->company_id,
            'deal_description' => 'promo',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => 'D-X',
            'product_id' => $this->product->product_id,
            'minimum_qty' => '10',
            'qualifier_unit' => 'CTN',
        ]);
        DealReward::create([
            'deal_no' => 'D-X',
            'product_id' => $this->product->product_id,
            'reward_qty' => '1',
            'reward_unit' => 'CTN',
            'for_each_qty' => null,
            'for_each_unit' => null,
        ]);

        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines('10', 'CTN'),
        )['order'];

        $result = $this->service->confirm($order);
        $order = $result['order'];

        $free = $order->items->firstWhere('is_free_item', true);

        $this->assertNotNull($free, 'Qualifying deal must propose a free line at confirmation.');
        $this->assertSame('DEAL', $free->line_source->value);
        $this->assertTrue($free->is_free_item);
        $this->assertSame('0.00', (string) $free->unit_price);
        $this->assertSame('D-X', $free->deal_no);
        $this->assertSame(1, $free->parent_item_no, 'parent_item_no must reference the qualifying manual line.');
        $this->assertSame('1.000', (string) $free->order_qty);
        $this->assertSame('CTN', $free->order_unit);
    }

    public function test_deals_recomputed_at_confirmation_not_from_draft(): void
    {
        DealCondition::create([
            'deal_no' => 'D-LATE',
            'company_id' => $this->company->company_id,
            'deal_description' => 'created after draft',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => 'D-LATE',
            'product_id' => $this->product->product_id,
            'minimum_qty' => '10',
            'qualifier_unit' => 'CTN',
        ]);
        DealReward::create([
            'deal_no' => 'D-LATE',
            'product_id' => $this->product->product_id,
            'reward_qty' => '2',
            'reward_unit' => 'PCS',
            'for_each_qty' => null,
            'for_each_unit' => null,
        ]);

        // Draft created BEFORE the deal existed (deal created in this test above).
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines('10', 'CTN'),
        )['order'];

        $result = $this->service->confirm($order);

        $this->assertTrue($result['order']->items->contains(fn ($i) => $i->deal_no === 'D-LATE'),
            'Deals are re-evaluated at confirmation with current master data.');
    }

    public function test_ambiguous_pricing_blocks_confirmation_with_report(): void
    {
        // Second overlapping generic condition → ambiguity.
        PriceCondition::create([
            'condition_price_no' => 'PC-2',
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-2',
            'product_id' => $this->product->product_id,
            'price' => '555.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        $result = $this->service->confirm($order);

        $this->assertNotSame('CONFIRMED', $result['order']->order_status->value);
        $this->assertNotEmpty($result['conflicts']);
        $this->assertStringContainsString('ambiguous', $result['conflicts'][0]);
        $this->assertStringContainsString('PC-STD', $result['conflicts'][0]);
        $this->assertStringContainsString('PC-2', $result['conflicts'][0]);
    }

    public function test_ambiguous_deals_block_confirmation_with_report(): void
    {
        foreach (['D-1', 'D-2'] as $no) {
            DealCondition::create([
                'deal_no' => $no,
                'company_id' => $this->company->company_id,
                'deal_description' => $no,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'active' => true,
            ]);
            DealQualifier::create([
                'deal_no' => $no,
                'product_id' => $this->product->product_id,
                'minimum_qty' => '10',
                'qualifier_unit' => 'CTN',
            ]);
            DealReward::create([
                'deal_no' => $no,
                'product_id' => $this->product->product_id,
                'reward_qty' => '1',
                'reward_unit' => 'PCS',
                'for_each_qty' => null,
                'for_each_unit' => null,
            ]);
        }

        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines('10', 'CTN'),
        )['order'];

        $result = $this->service->confirm($order);

        $this->assertNotSame('CONFIRMED', $result['order']->order_status->value);
        $this->assertNotEmpty($result['conflicts']);
        $this->assertStringContainsString('D-1', $result['conflicts'][0]);
        $this->assertStringContainsString('D-2', $result['conflicts'][0]);
    }

    // ---- Demand-only invariant -----------------------------------------------------

    public function test_confirmation_does_not_touch_inventory(): void
    {
        $before = $this->inventorySnapshot();

        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines('1000', 'CTN'), // demand far beyond any stock
        )['order'];

        $this->service->confirm($order);

        $after = $this->inventorySnapshot();

        $this->assertSame($before['row'], $after['row'], 'SO must not change unrestricted/restricted stock.');
        $this->assertSame($before['movements'], $after['movements'], 'SO must not create inventory movements.');
        $this->assertSame('CONFIRMED', $order->fresh()->order_status->value, 'Demand beyond stock is still valid demand.');
    }

    // ---- Confirmation immutability & rejection ----------------------------------------

    public function test_confirmed_order_cannot_be_edited(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        $this->service->confirm($order);

        $this->expectException(HttpException::class);

        $this->service->updateDraft($order, $this->lines('5', 'CTN'));
    }

    public function test_rejection_preserves_order_qty(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines('30', 'CTN'),
        )['order'];

        $this->service->confirm($order);

        $item = $order->items()->first();
        $rejected = $this->service->rejectItem($item, $this->reason(), $this->employee);

        $this->assertSame('30.000', (string) $rejected->order_qty, 'Original demand must be preserved.');
        $this->assertSame('REJECTED', $rejected->rejection_status->value);
        $this->assertSame($this->employee->employee_id, $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);

        $order->refresh();
        $this->assertSame('COMPLETELY_REJECTED', $order->order_status->value);
    }

    public function test_partial_rejection_sets_partial_status(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id,
            collect([
                ['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'CTN', 'unit_price' => null, 'price_override_reason' => null],
            ]),
        )['order'];

        $this->service->confirm($order);

        $item = $order->items()->first();
        $this->service->rejectItem($item, $this->reason(SalesOrderRejectionReason::CODE_UNAVAILABLE_STOCK), $this->employee);

        $order->refresh();
        $this->assertSame('COMPLETELY_REJECTED', $order->order_status->value);
    }

    public function test_double_rejection_is_rejected(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        $this->service->confirm($order);
        $item = $order->items()->first();
        $this->service->rejectItem($item, $this->reason(), $this->employee);

        $this->expectException(HttpException::class);
        $this->service->rejectItem($item, $this->reason(), $this->employee);
    }

    // ---- HTTP authorization --------------------------------------------------------

    public function test_employee_cannot_view_another_employees_order(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];
        $this->service->confirm($order);

        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $otherSeller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $otherEmployee->employee_id,
            'company_id' => $this->company->company_id,
        ]);

        $this->actingAs($otherSeller)->get('/orders/'.$order->sales_order_no)->assertForbidden();
    }

    public function test_admin_of_other_company_cannot_view_order(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        $foreignAdmin = AppUser::factory()->companyAdmin()->create();

        $this->actingAs($foreignAdmin)->get('/orders/'.$order->sales_order_no)->assertForbidden();
    }

    public function test_store_scopes_order_to_selling_company_regardless_of_spoofed_fields(): void
    {
        // Customers are GLOBAL (no company_id); the SO company comes from the
        // authenticated employee — never a client-supplied company.
        $this->actingAs($this->seller)->post('/orders', [
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'company_id' => 'HACK', // spoof attempt — must be ignored
            'action' => 'draft',
            'lines' => [[
                'product_id' => $this->product->product_id,
                'qty' => '5',
                'unit' => 'CTN',
            ]],
        ]);

        $order = SalesOrder::where('supplying_customer_id', $this->primary->customer_id)->first();

        $this->assertNotNull($order);
        $this->assertSame($this->company->company_id, $order->company_id,
            'The SO company is derived server-side from the employee, never from the payload.');
    }

    // ---- Supplying-Primary eligibility (customer_employee assignment) -------

    public function test_assigned_primary_is_accepted_as_supplying(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        $this->assertSame($this->primary->customer_id, $order->supplying_customer_id);
        $this->assertSame('DRAFT', $order->order_status->value);
    }

    public function test_second_assigned_primary_is_accepted(): void
    {
        $secondPrimary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();

        $order = $this->service->createDraft(
            $this->employee, $secondPrimary->customer_id, $secondPrimary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        // No fixed Primary → Secondary mapping: the SAME secondary may be
        // served through any of the employee's assigned Primaries.
        $this->assertSame($secondPrimary->customer_id, $order->supplying_customer_id);
        $this->assertSame($this->secondary->customer_id, $order->sold_to_customer_id);
    }

    public function test_unassigned_active_primary_is_rejected(): void
    {
        $unassigned = CustomerMaster::factory()->primary()->create(); // active, but not assigned

        $this->actingAs($this->seller)->post('/orders', [
            'supplying_customer_id' => $unassigned->customer_id,
            'source_customer_id' => $unassigned->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'action' => 'draft',
            'lines' => [['product_id' => $this->product->product_id, 'qty' => '5', 'unit' => 'CTN']],
        ])->assertForbidden();

        $this->assertSame(0, SalesOrder::where('supplying_customer_id', $unassigned->customer_id)->count(),
            'An unassigned Primary must never yield an order — server-side, not just UI filtering.');
    }

    public function test_spoofing_unassigned_primary_id_in_post_is_rejected(): void
    {
        $unassigned = CustomerMaster::factory()->primary()->create();

        // The assigned Primary is sent in one field while an unassigned one is
        // spoofed in the other — every server-side path must validate the
        // same supplying id that is persisted.
        $this->actingAs($this->seller)->post('/orders', [
            'supplying_customer_id' => $unassigned->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'company_id' => $this->company->company_id,
            'action' => 'draft',
            'lines' => [['product_id' => $this->product->product_id, 'qty' => '5', 'unit' => 'CTN']],
        ])->assertForbidden();

        $this->assertSame(0, SalesOrder::count(), 'No order may be persisted for a spoofed supplying Primary.');
    }

    public function test_sync_draft_referencing_unassigned_primary_becomes_conflict(): void
    {
        // Assignment removed while the device was offline: the queued draft
        // references a Primary the employee may no longer supply through.
        CustomerEmployee::where('employee_id', $this->employee->employee_id)
            ->where('customer_id', $this->primary->customer_id)->delete();

        $response = $this->actingAs($this->seller)->postJson('/sync/order-drafts', [
            'idempotency_key' => 'sync-stale-primary',
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'lines' => [['product_id' => $this->product->product_id, 'qty' => '10', 'unit' => 'CTN']],
            'cached_prices' => [$this->product->product_id => '500.00'],
            'request_confirm' => true,
        ]);

        // 403 → the offline client maps this to outbox CONFLICT for inspection.
        $response->assertStatus(403);
        $this->assertStringContainsString('must be assigned to you', (string) $response->json('message'));
        $this->assertSame(0, SalesOrder::where('sold_to_customer_id', $this->secondary->customer_id)->count());
    }

    public function test_removing_primary_assignment_before_confirmation_blocks_confirmation(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        CustomerEmployee::where('employee_id', $this->employee->employee_id)
            ->where('customer_id', $this->primary->customer_id)->delete();

        // Editing the draft is blocked as well.
        $this->expectException(HttpException::class);

        $this->service->updateDraft($order, $this->lines('7', 'CTN'));
    }

    public function test_confirmation_conflicts_when_primary_assignment_was_removed(): void
    {
        $order = $this->service->createDraft(
            $this->employee, $this->primary->customer_id, $this->primary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        )['order'];

        CustomerEmployee::where('employee_id', $this->employee->employee_id)
            ->where('customer_id', $this->primary->customer_id)->delete();

        $result = $this->service->confirm($order);

        $this->assertNotEmpty($result['conflicts'], 'Confirmation must surface the revoked assignment as a conflict.');
        $this->assertStringContainsString('no longer assigned', $result['conflicts'][0]);
        $this->assertSame('DRAFT', $order->fresh()->order_status->value, 'The order stays an untouched DRAFT.');
        $this->assertSame(1, $order->items()->count(), 'No deal/derived lines may be added.');
    }

    public function test_customer_globality_does_not_determine_company_context(): void
    {
        // A second company with its own employee, assigned Primary/Secondary
        // and product. Customers stay GLOBAL — yet each SO derives its company
        // from the AUTHENTICATED employee, and no cross-company supply leaks.
        $otherCompany = CompanyMaster::factory()->create();
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $otherCompany->company_id]);
        $otherPrimary = CustomerMaster::factory()->primary()->forEmployee($otherEmployee)->create();
        $otherSecondary = CustomerMaster::factory()->forEmployee($otherEmployee)->create();
        $otherProduct = ProductMaster::factory()->forCompany($otherCompany)->create(['basic_unit' => 'PCS']);

        $order = $this->service->createDraft(
            $otherEmployee, $otherPrimary->customer_id, $otherPrimary->customer_id,
            $otherSecondary->customer_id,
            collect([['product_id' => $otherProduct->product_id, 'qty' => '2', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];

        $this->assertSame($otherCompany->company_id, $order->company_id,
            'Company context follows the employee, not the (global) customer.');

        // The global Primary of the other company is NOT supply-eligible here.
        $this->expectException(HttpException::class);

        $this->service->createDraft(
            $this->employee, $otherPrimary->customer_id, $otherPrimary->customer_id,
            $this->secondary->customer_id, $this->lines(),
        );
    }

    // ---- Offline sync -----------------------------------------------------------------

    public function test_sync_draft_creates_server_draft_idempotently(): void
    {
        $payload = [
            'idempotency_key' => 'draft-key-1',
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'lines' => [[
                'product_id' => $this->product->product_id,
                'qty' => '10',
                'unit' => 'CTN',
            ]],
            'cached_prices' => [$this->product->product_id => '480.00'], // device cached an older price
        ];

        $first = $this->actingAs($this->seller)->postJson('/sync/order-drafts', $payload);
        $first->assertStatus(201);

        $body = $first->json();
        $this->assertTrue($body['server_draft']);
        $this->assertFalse($body['confirmed']);
        $this->assertSame('DRAFT', $body['order_status']);

        // A stale cached price must be surfaced, never silently applied.
        $this->assertNotEmpty($body['pricing_changes']);
        $this->assertSame('480.00', $body['pricing_changes'][0]['cached']);
        $this->assertSame('500.00', $body['pricing_changes'][0]['server']);

        // Retry must not duplicate.
        $retry = $this->actingAs($this->seller)->postJson('/sync/order-drafts', $payload);
        $retry->assertStatus(200);
        $this->assertEquals($first->json('sales_order_no'), $retry->json('sales_order_no'));

        $this->assertSame(1, SalesOrder::where('sold_to_customer_id', $this->secondary->customer_id)->count());
    }

    public function test_sync_draft_with_request_confirm_stays_draft_when_prices_changed(): void
    {
        $payload = [
            'idempotency_key' => 'draft-confirm-1',
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'lines' => [[
                'product_id' => $this->product->product_id,
                'qty' => '10',
                'unit' => 'CTN',
            ]],
            'cached_prices' => [$this->product->product_id => '400.00'],
            'request_confirm' => true,
        ];

        $response = $this->actingAs($this->seller)->postJson('/sync/order-drafts', $payload);
        $response->assertStatus(201);

        $body = $response->json();

        $this->assertFalse($body['confirmed'], 'A stale cached price must not silently confirm.');
        $this->assertNotEmpty($body['pricing_changes']);
        $this->assertSame('DRAFT', SalesOrder::find($body['sales_order_no'])->order_status->value);
    }

    public function test_sync_draft_confirms_when_prices_match(): void
    {
        $payload = [
            'idempotency_key' => 'draft-confirm-2',
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $this->secondary->customer_id,
            'lines' => [[
                'product_id' => $this->product->product_id,
                'qty' => '10',
                'unit' => 'CTN',
            ]],
            'cached_prices' => [$this->product->product_id => '500.00'],
            'request_confirm' => true,
        ];

        $response = $this->actingAs($this->seller)->postJson('/sync/order-drafts', $payload);
        $response->assertStatus(201);

        $body = $response->json();

        $this->assertTrue($body['confirmed']);
        $this->assertSame('CONFIRMED', SalesOrder::find($body['sales_order_no'])->order_status->value);
    }

    public function test_sync_rejects_unassigned_customer(): void
    {
        $stranger = CustomerMaster::factory()->create();

        $this->actingAs($this->seller)->postJson('/sync/order-drafts', [
            'idempotency_key' => 'draft-evil',
            'supplying_customer_id' => $this->primary->customer_id,
            'source_customer_id' => $this->primary->customer_id,
            'sold_to_customer_id' => $stranger->customer_id,
            'lines' => [['product_id' => $this->product->product_id, 'qty' => '1', 'unit' => 'CTN']],
        ])->assertStatus(403);
    }
}
