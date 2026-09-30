<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CompanyScopingTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $companyA;

    private CompanyMaster $companyB;

    private EmployeeMaster $employeeA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = CompanyMaster::factory()->create();
        $this->companyB = CompanyMaster::factory()->create();

        $this->employeeA = EmployeeMaster::factory()->create(['company_id' => $this->companyA->company_id]);
    }

    public function test_sales_employee_dashboard_shows_only_assigned_customers(): void
    {
        $user = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);

        $mine = CustomerMaster::factory()->forEmployee($this->employeeA)->create();
        CustomerMaster::factory()->forEmployee(null)->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee($mine->business_name);
    }

    public function test_company_admin_dashboard_is_scoped_to_own_company(): void
    {
        $user = AppUser::factory()->companyAdmin()->create([
            'employee_id' => null,
            'company_id' => $this->companyA->company_id,
        ]);

        ProductMaster::factory()->forCompany($this->companyA)->create();
        ProductMaster::factory()->forCompany($this->companyB)->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSeeText('2');
    }

    public function test_superadmin_sees_all_companies_and_can_switch_context(): void
    {
        $user = AppUser::factory()->superadmin()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee($this->companyA->company_name)
            ->assertSee($this->companyB->company_name);

        $this->actingAs($user)->post('/company/switch', [
            'company' => $this->companyB->company_id,
        ])->assertRedirect();

        $this->withSession(['company_context' => $this->companyB->company_id])
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_company_admin_cannot_switch_company_context(): void
    {
        $user = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->companyA->company_id,
        ]);

        $this->actingAs($user)->post('/company/switch', [
            'company' => $this->companyB->company_id,
        ])->assertForbidden();
    }

    public function test_sales_employee_cannot_switch_company_context(): void
    {
        $user = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->companyA->company_id,
        ]);

        $this->actingAs($user)->post('/company/switch', [
            'company' => $this->companyB->company_id,
        ])->assertForbidden();
    }
}
