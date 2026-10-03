<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Models\SalesRegion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MasterDataTest extends TestCase
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

    private function adminFor(CompanyMaster $company): AppUser
    {
        return AppUser::factory()->companyAdmin()->create([
            'employee_id' => null,
            'company_id' => $company->company_id,
        ]);
    }

    private function superadmin(): AppUser
    {
        return AppUser::factory()->superadmin()->create();
    }

    // ---- Products ----------------------------------------------------------

    public function test_company_admin_sees_only_own_company_products(): void
    {
        $admin = $this->adminFor($this->companyA);

        $mine = ProductMaster::factory()->forCompany($this->companyA)->create();
        $other = ProductMaster::factory()->forCompany($this->companyB)->create();

        $response = $this->actingAs($admin)->get('/products');

        $response->assertOk()->assertSee($mine->product_description);
        $response->assertDontSee($other->product_description);
    }

    public function test_company_admin_creates_product_inside_own_company(): void
    {
        $admin = $this->adminFor($this->companyA);

        $response = $this->actingAs($admin)->post('/products', [
            'product_id' => 'PRD-TEST-1',
            'product_description' => 'Test Product',
            'product_sku' => 'SKU-TEST-1',
            'basic_unit' => 'PCS',
            'active' => '1',
        ]);

        $response->assertRedirect('/products');
        $this->assertDatabaseHas('product_master', [
            'product_id' => 'PRD-TEST-1',
            'company_id' => $this->companyA->company_id,
        ]);
    }

    public function test_company_admin_cannot_hide_products_of_other_company(): void
    {
        $admin = $this->adminFor($this->companyA);
        $other = ProductMaster::factory()->forCompany($this->companyB)->create();

        $this->actingAs($admin)->patch('/products/'.$other->product_id.'/deactivate')
            ->assertForbidden();

        $this->assertDatabaseHas('product_master', [
            'product_id' => $other->product_id,
            'active' => true,
        ]);
    }

    public function test_duplicate_sku_within_company_is_rejected(): void
    {
        $admin = $this->adminFor($this->companyA);

        ProductMaster::factory()->forCompany($this->companyA)->create(['product_sku' => 'DUP-SKU']);

        $this->actingAs($admin)->post('/products', [
            'product_id' => 'PRD-DUP',
            'product_description' => 'Dup',
            'product_sku' => 'DUP-SKU',
            'basic_unit' => 'PCS',
        ])->assertSessionHasErrors('product_sku');
    }

    public function test_same_sku_allowed_in_different_companies(): void
    {
        $adminA = $this->adminFor($this->companyA);
        $adminB = $this->adminFor($this->companyB);

        $this->actingAs($adminA)->post('/products', [
            'product_id' => 'PRD-A1',
            'product_description' => 'A product',
            'product_sku' => 'SHARED-SKU',
            'basic_unit' => 'PCS',
        ])->assertRedirect('/products');

        $this->actingAs($adminB)->post('/products', [
            'product_id' => 'PRD-B1',
            'product_description' => 'B product',
            'product_sku' => 'SHARED-SKU',
            'basic_unit' => 'PCS',
        ])->assertRedirect('/products');

        $this->assertDatabaseHas('product_master', ['product_id' => 'PRD-B1', 'product_sku' => 'SHARED-SKU']);
    }

    // ---- Customers ---------------------------------------------------------

    public function test_company_admin_can_view_but_not_create_customers(): void
    {
        $admin = $this->adminFor($this->companyA);

        $this->actingAs($admin)->get('/customers')->assertOk();
        $this->actingAs($admin)->post('/customers', [
            'customer_id' => 'X',
            'business_name' => 'X',
            'customer_type' => 'PRIMARY',
        ])->assertForbidden();
    }

    public function test_superadmin_creates_ship_to_with_primary_parent(): void
    {
        $super = $this->superadmin();

        $primary = CustomerMaster::factory()->primary()->create();

        $this->actingAs($super)->post('/customers', [
            'business_name' => 'Primary Warehouse 2',
            'customer_type' => 'SHIP_TO',
            'parent_customer_id' => $primary->customer_id,
            'active' => '1',
        ])->assertRedirect('/customers');

        $this->assertDatabaseHas('customer_master', [
            'business_name' => 'Primary Warehouse 2',
            'parent_customer_id' => $primary->customer_id,
        ]);
    }

    public function test_ship_to_requires_primary_parent(): void
    {
        $super = $this->superadmin();

        $secondary = CustomerMaster::factory()->create(['customer_type' => 'SECONDARY']);

        $this->actingAs($super)->post('/customers', [
            'business_name' => 'Bad ship-to',
            'customer_type' => 'SHIP_TO',
            'parent_customer_id' => $secondary->customer_id,
        ])->assertSessionHasErrors();
    }

    public function test_non_ship_to_cannot_have_parent(): void
    {
        $super = $this->superadmin();

        $primary = CustomerMaster::factory()->primary()->create();

        $this->actingAs($super)->post('/customers', [
            'business_name' => 'Bad secondary',
            'customer_type' => 'SECONDARY',
            'parent_customer_id' => $primary->customer_id,
        ])->assertSessionHasErrors();
    }

    // ---- Employees & logins -------------------------------------------------

    public function test_company_admin_cannot_create_employee_in_other_company(): void
    {
        $admin = $this->adminFor($this->companyA);

        // company_id is forced server-side for admins; try to spoof it via
        // query string AND payload.
        $response = $this->actingAs($admin)->post('/employees?company='.$this->companyB->company_id, [
            'employee_id' => 'EMP-EVIL',
            'employee_name' => 'Evil Employee',
            'company_id' => $this->companyB->company_id,
        ]);

        $response->assertRedirect();

        // The employee is created — but in the ADMIN's company, never the spoofed one.
        $this->assertDatabaseHas('employee_master', [
            'employee_id' => 'EMP-EVIL',
            'company_id' => $this->companyA->company_id,
        ]);
        $this->assertDatabaseMissing('employee_master', [
            'employee_id' => 'EMP-EVIL',
            'company_id' => $this->companyB->company_id,
        ]);
    }

    public function test_employee_with_login_provisioning(): void
    {
        $admin = $this->adminFor($this->companyA);

        $this->actingAs($admin)->post('/employees', [
            'employee_id' => 'EMP-LOGIN-1',
            'employee_name' => 'Login Employee',
            'create_login' => '1',
            'login_email' => 'login.employee@companya.test',
            'login_password' => 'Secret123!',
            'login_role' => 'SALES_EMPLOYEE',
        ])->assertRedirect();

        $this->assertDatabaseHas('app_user', [
            'email' => 'login.employee@companya.test',
            'employee_id' => 'EMP-LOGIN-1',
            'company_id' => $this->companyA->company_id,
            'role' => 'SALES_EMPLOYEE',
        ]);

        $user = AppUser::where('email', 'login.employee@companya.test')->first();
        $this->assertTrue(Hash::check('Secret123!', $user->password_hash));
    }

    // ---- Assignments ---------------------------------------------------------

    public function test_assignments_sync_customers_and_product_scope(): void
    {
        $admin = $this->adminFor($this->companyA);

        SalesRegion::firstOrCreate(['region_code' => 'ASSIGN-REG'], [
            'description' => 'Assignment Region',
            'zone' => 'TEST',
            'sort_order' => 1,
        ]);
        $employee = EmployeeMaster::factory()->create([
            'company_id' => $this->companyA->company_id,
            'region_code' => 'ASSIGN-REG',
        ]);
        $c1 = CustomerMaster::factory()->create(['sales_region' => 'ASSIGN-REG']);
        $c2 = CustomerMaster::factory()->create(['sales_region' => 'ASSIGN-REG']);
        $p1 = ProductMaster::factory()->forCompany($this->companyA)->create();
        $pForeign = ProductMaster::factory()->forCompany($this->companyB)->create();

        $this->actingAs($admin)->put('/employees/'.$employee->employee_id.'/assignments', [
            'customer_ids' => [$c1->customer_id, $c2->customer_id],
            'product_scope_mode' => 'SELECTED',
            'product_ids' => [$p1->product_id, $pForeign->product_id], // foreign filtered out
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_employee', [
            'employee_id' => $employee->employee_id,
            'customer_id' => $c1->customer_id,
        ]);
        $this->assertDatabaseHas('customer_employee', [
            'employee_id' => $employee->employee_id,
            'customer_id' => $c2->customer_id,
        ]);

        // Only the company's own product entered the scope.
        $this->assertDatabaseHas('employee_product', [
            'employee_id' => $employee->employee_id,
            'product_id' => $p1->product_id,
        ]);
        $this->assertDatabaseMissing('employee_product', [
            'employee_id' => $employee->employee_id,
            'product_id' => $pForeign->product_id,
        ]);
    }

    public function test_product_scope_mode_all_clears_rows(): void
    {
        $admin = $this->adminFor($this->companyA);

        $employee = EmployeeMaster::factory()->create(['company_id' => $this->companyA->company_id]);
        EmployeeProduct::create([
            'employee_id' => $employee->employee_id,
            'product_id' => ProductMaster::factory()->forCompany($this->companyA)->create()->product_id,
        ]);

        $this->actingAs($admin)->put('/employees/'.$employee->employee_id.'/assignments', [
            'product_scope_mode' => 'ALL',
        ])->assertRedirect();

        $this->assertDatabaseMissing('employee_product', ['employee_id' => $employee->employee_id]);
    }

    public function test_customer_assignments_are_limited_to_the_employee_sales_region(): void
    {
        $admin = $this->adminFor($this->companyA);
        SalesRegion::firstOrCreate(['region_code' => 'REG-A'], ['description' => 'Region A', 'zone' => 'TEST', 'sort_order' => 1]);
        SalesRegion::firstOrCreate(['region_code' => 'REG-B'], ['description' => 'Region B', 'zone' => 'TEST', 'sort_order' => 2]);

        $employee = EmployeeMaster::factory()->create([
            'company_id' => $this->companyA->company_id,
            'region_code' => 'REG-A',
        ]);
        $eligible = CustomerMaster::factory()->create(['business_name' => 'Eligible Shop', 'sales_region' => 'REG-A']);
        $outsideRegion = CustomerMaster::factory()->create(['business_name' => 'Outside Shop', 'sales_region' => 'REG-B']);

        $this->actingAs($admin)
            ->get(route('assignments.edit', $employee))
            ->assertOk()
            ->assertSee('Eligible Shop')
            ->assertDontSee('Outside Shop');

        $this->actingAs($admin)->put(route('assignments.update', $employee), [
            'customer_ids' => [$outsideRegion->customer_id],
            'product_scope_mode' => 'ALL',
        ])->assertSessionHasErrors('customer_ids.0');

        $this->assertDatabaseMissing('customer_employee', [
            'employee_id' => $employee->employee_id,
            'customer_id' => $outsideRegion->customer_id,
        ]);
        $this->assertDatabaseMissing('customer_employee', [
            'employee_id' => $employee->employee_id,
            'customer_id' => $eligible->customer_id,
        ]);
    }

    public function test_company_admin_can_view_customer_details_and_company_preferred_visits(): void
    {
        $admin = $this->adminFor($this->companyA);
        SalesRegion::firstOrCreate(['region_code' => 'SHOW-REG'], [
            'description' => 'Lagos Mainland',
            'zone' => 'LAGOS',
            'sort_order' => 1,
        ]);
        $customer = CustomerMaster::factory()->create([
            'business_name' => 'Operational Customer',
            'sales_region' => 'SHOW-REG',
            'phone_number' => '7087973183',
        ]);
        $companyPlan = CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'customer_id' => $customer->customer_id,
            'preferred_week' => 2,
            'preferred_day' => 1,
            'active' => true,
        ]);
        CustomerFjp::create([
            'company_id' => $this->companyB->company_id,
            'customer_id' => $customer->customer_id,
            'preferred_week' => 3,
            'preferred_day' => 5,
            'active' => true,
        ]);

        $this->actingAs($admin)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Operational Customer')
            ->assertSee('Lagos Mainland')
            ->assertDontSee('SHOW-REG')
            ->assertSee('W2-Mon')
            ->assertDontSee('W3-Fri')
            ->assertSee(route('fjp.edit', $companyPlan), false);
    }

    public function test_sales_employee_cannot_open_admin_customer_details(): void
    {
        $seller = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);
        $customer = CustomerMaster::factory()->forEmployee($this->employeeA)->create();

        $this->actingAs($seller)->get(route('customers.show', $customer))->assertForbidden();
    }

    public function test_customer_index_contains_upload_and_customer_selection_controls(): void
    {
        $admin = $this->adminFor($this->companyA);
        $customer = CustomerMaster::factory()->create(['business_name' => 'Selectable Customer']);

        $this->actingAs($admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Upload company preferred visits')
            ->assertSee('Select all')
            ->assertSee('Clear FJP')
            ->assertSee('name="customer_ids[]"', false)
            ->assertSee((string) $customer->customer_id);
    }

    public function test_sales_employee_cannot_access_master_data_write(): void
    {
        $user = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);

        $this->actingAs($user)->get('/products')->assertForbidden();
        $this->actingAs($user)->post('/products', [])->assertForbidden();
        $this->actingAs($user)->get('/employees')->assertForbidden();
    }

    // ---- Search endpoints -----------------------------------------------------

    public function test_customer_search_respects_employee_assignment(): void
    {
        $user = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);

        $assigned = CustomerMaster::factory()->forEmployee($this->employeeA)->create();
        $unassigned = CustomerMaster::factory()->create();

        $response = $this->actingAs($user)->getJson('/search/customers');

        $response->assertOk();
        $ids = collect($response->json('results'))->pluck('id');

        $this->assertTrue($ids->contains($assigned->customer_id));
        $this->assertFalse($ids->contains($unassigned->customer_id));
    }

    public function test_supplying_primaries_returns_only_primary_customers(): void
    {
        $user = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);

        $primary = CustomerMaster::factory()->primary()->create();
        CustomerMaster::factory()->create(['customer_type' => 'SECONDARY']);

        $response = $this->actingAs($user)->getJson('/search/supplying-primaries');

        $ids = collect($response->json('results'))->pluck('id');
        $this->assertTrue($ids->contains($primary->customer_id));
        $this->assertCount(1, $ids->filter(fn ($id) => str_starts_with((string) $id, $primary->customer_id)));
    }
}
