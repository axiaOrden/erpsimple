<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesEmployeeWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private CustomerMaster $secondary;

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
        $this->secondary = CustomerMaster::factory()->forEmployee($this->employee)->create();
    }

    public function test_dashboard_exposes_actionable_sales_workflow(): void
    {
        $this->actingAs($this->seller)
            ->get(route('dashboard'))
            ->assertSee(route('orders.create'), false)
            ->assertSee(route('inventory.counts.create'), false)
            ->assertSee(route('visits.customer', $this->secondary), false);
    }

    public function test_assigned_secondary_visit_exposes_count_order_history_and_outstanding_actions(): void
    {
        $this->actingAs($this->seller)
            ->get(route('visits.customer', $this->secondary))
            ->assertSee('Count inventory')
            ->assertSee('New order')
            ->assertSee('Recent purchases')
            ->assertSee('Open orders')
            ->assertSee('Statement & outstanding', false)
            ->assertSee(route('orders.create', ['customer' => $this->secondary->customer_id]), false);
    }

    public function test_unassigned_customer_visit_remains_forbidden(): void
    {
        $unassigned = CustomerMaster::factory()->create();

        $this->actingAs($this->seller)
            ->get(route('visits.customer', $unassigned))
            ->assertForbidden();
    }

    public function test_count_form_includes_assigned_secondary_and_enforces_product_scope(): void
    {
        $allowed = ProductMaster::factory()->forCompany($this->company)->create();
        $blocked = ProductMaster::factory()->forCompany($this->company)->create();
        $foreign = ProductMaster::factory()->create();

        EmployeeProduct::create([
            'employee_id' => $this->employee->employee_id,
            'product_id' => $allowed->product_id,
        ]);

        $this->actingAs($this->seller)
            ->get(route('inventory.counts.create', [
                'customer' => $this->secondary->customer_id,
                'type' => 'SECONDARY_OBSERVATION',
            ]))
            ->assertSee($this->secondary->business_name)
            ->assertSee($allowed->product_description)
            ->assertDontSee($blocked->product_description)
            ->assertDontSee($foreign->product_description)
            ->assertSee('value="SECONDARY_OBSERVATION" selected', false);
    }

    public function test_contextual_order_and_shipment_forms_preselect_the_customer(): void
    {
        $this->actingAs($this->seller)
            ->get(route('orders.create', ['customer' => $this->secondary->customer_id]))
            ->assertSee("soldTo: '".$this->secondary->customer_id."'", false);

        $this->actingAs($this->seller)
            ->get(route('shipments.create', ['source' => $this->primary->customer_id]))
            ->assertSee('value="'.$this->primary->customer_id.'" selected', false);
    }
}
