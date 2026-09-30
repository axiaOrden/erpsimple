<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Order-capture purchase-history GUIDANCE (field-sales workflow, not Phase 10).
 *
 * The recent-purchase panel is read-only: it must never auto-create lines,
 * mandate products/quantities, leak another customer's or another company's
 * purchases, or expose products outside the employee's scope. Historical
 * quantities never constrain today's order.
 */
class OrderPurchaseHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $customerA;

    private CustomerMaster $customerB;

    private ProductMaster $product1;

    private ProductMaster $product2;

    private SalesOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $this->employee->employee_id,
        ]);

        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();
        $this->customerA = CustomerMaster::factory()->forEmployee($this->employee)->create();
        $this->customerB = CustomerMaster::factory()->forEmployee($this->employee)->create();

        $this->product1 = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        $this->product2 = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);

        $conditionNo = 'PC-HIST-'.$this->company->company_id;
        PriceCondition::create([
            'condition_price_no' => $conditionNo,
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);

        foreach ([$this->product1, $this->product2] as $product) {
            PriceConditionItem::create([
                'condition_price_no' => $conditionNo,
                'product_id' => $product->product_id,
                'price' => '1000.00',
                'currency' => 'NGN',
                'tax_type' => 'NONE',
            ]);
            ProductUnitConversion::create([
                'product_id' => $product->product_id,
                'alternative_unit' => 'CTN',
                'numerator' => '24',
                'denominator' => '1',
            ]);
        }

        $this->orders = app(SalesOrderService::class);
    }

    // ---- Helpers -----------------------------------------------------------

    private function confirmFor(EmployeeMaster $employee, CustomerMaster $primary, CustomerMaster $soldTo, array $lines): SalesOrder
    {
        $order = $this->orders->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect($lines),
        )['order'];

        $result = $this->orders->confirm($order);

        $this->assertSame([], $result['conflicts'], 'Fixture order must confirm cleanly.');

        return $order->fresh(['items']);
    }

    private function line(ProductMaster $product, string $qty, string $unit = 'PCS'): array
    {
        return [
            'product_id' => $product->product_id,
            'qty' => $qty,
            'unit' => $unit,
            'unit_price' => null,
            'price_override_reason' => null,
        ];
    }

    private function history(CustomerMaster $customer)
    {
        return $this->actingAs($this->seller)
            ->getJson(route('orders.customer-history').'?sold_to_customer_id='.$customer->customer_id);
    }

    // ---- Tests -------------------------------------------------------------

    public function test_history_returns_recent_purchases_for_the_selected_customer(): void
    {
        $this->confirmFor($this->employee, $this->primary, $this->customerA, [$this->line($this->product1, '5', 'CTN')]);

        $response = $this->history($this->customerA);

        $response->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.product_id', $this->product1->product_id)
            ->assertJsonPath('history.0.quantity', '5.000')
            ->assertJsonPath('history.0.unit', 'CTN')
            ->assertJsonPath('history.0.is_free_item', false);

        $this->assertNotEmpty($response->json('history.0.date'));
        $this->assertStringStartsWith('SO-', $response->json('history.0.sales_order_no'));
    }

    public function test_history_for_another_customer_is_not_shown(): void
    {
        $orderA = $this->confirmFor($this->employee, $this->primary, $this->customerA, [$this->line($this->product1, '5')]);

        // Customer B has no history → nothing at all, never A's purchases.
        $this->history($this->customerB)
            ->assertOk()
            ->assertJsonCount(0, 'history')
            ->assertJsonMissing(['sales_order_no' => $orderA->sales_order_no]);

        // A's own history does contain A's order.
        $this->history($this->customerA)
            ->assertOk()
            ->assertJsonPath('history.0.sales_order_no', $orderA->sales_order_no);
    }

    public function test_history_excludes_products_outside_employee_scope(): void
    {
        // Both orders exist before the scope is narrowed.
        $this->confirmFor($this->employee, $this->primary, $this->customerA, [
            $this->line($this->product1, '3'),
            $this->line($this->product2, '7'),
        ]);

        // Scope the employee to product1 only.
        EmployeeProduct::create(['employee_id' => $this->employee->employee_id, 'product_id' => $this->product1->product_id]);

        $response = $this->history($this->customerA);

        $response->assertOk()->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.product_id', $this->product1->product_id);

        $this->assertNotContains(
            $this->product2->product_id,
            array_column($response->json('history'), 'product_id'),
            'Out-of-scope products must never appear in history guidance.',
        );
    }

    public function test_history_excludes_other_company_orders(): void
    {
        // Another company, its own employee/product/primary, same global customer.
        $otherCompany = CompanyMaster::factory()->create();
        $otherEmployee = EmployeeMaster::factory()->create(['company_id' => $otherCompany->company_id]);
        $otherPrimary = CustomerMaster::factory()->primary()->forEmployee($otherEmployee)->create();
        $otherProduct = ProductMaster::factory()->forCompany($otherCompany)->create(['basic_unit' => 'PCS']);

        CustomerEmployee::create(['customer_id' => $this->customerA->customer_id, 'employee_id' => $otherEmployee->employee_id, 'role' => 'SE']);

        $otherCondition = 'PC-OTH-'.$otherCompany->company_id;
        PriceCondition::create([
            'condition_price_no' => $otherCondition, 'company_id' => $otherCompany->company_id,
            'sales_region' => null, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => $otherCondition, 'product_id' => $otherProduct->product_id,
            'price' => '500.00', 'currency' => 'NGN', 'tax_type' => 'NONE',
        ]);

        $foreignOrder = $this->confirmFor($otherEmployee, $otherPrimary, $this->customerA, [$this->line($otherProduct, '9')]);

        // Our employee's history is company-scoped — the foreign order never leaks.
        $response = $this->history($this->customerA);

        $response->assertOk()
            ->assertJsonMissing(['sales_order_no' => $foreignOrder->sales_order_no])
            ->assertJsonCount(0, 'history');
    }

    public function test_historical_quantity_does_not_constrain_todays_order(): void
    {
        // A small previous purchase...
        $this->confirmFor($this->employee, $this->primary, $this->customerA, [$this->line($this->product1, '5')]);

        // ...does not limit today's larger order.
        $order = $this->confirmFor($this->employee, $this->primary, $this->customerA, [$this->line($this->product1, '500')]);

        $this->assertSame('CONFIRMED', $order->order_status->value);
        $this->assertSame('500.000', (string) $order->items->first()->order_qty);
    }

    public function test_customer_with_no_history_can_still_order_normally(): void
    {
        $this->history($this->customerB)->assertOk()->assertJsonCount(0, 'history');

        $order = $this->confirmFor($this->employee, $this->primary, $this->customerB, [$this->line($this->product1, '10')]);

        $this->assertSame('CONFIRMED', $order->order_status->value);
        $this->assertSame(1, SalesOrder::where('sold_to_customer_id', $this->customerB->customer_id)->count());
    }

    public function test_history_requires_assignment_to_the_customer(): void
    {
        $unassigned = CustomerMaster::factory()->create(); // no customer_employee row

        $this->history($unassigned)->assertForbidden();
    }
}
