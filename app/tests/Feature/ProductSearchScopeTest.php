<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Models\StockCount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The stock-count product selector is SEARCHABLE and stays inside the
 * employee's authorized scope (company ∩ employee_product).
 *
 * Item N: the search endpoint — and the page's own fallback catalog — only ever
 * return authorized products, and the server re-validates the scope on submit
 * so a crafted product id can never enter a count.
 */
class ProductSearchScopeTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private CustomerMaster $primary;

    private ProductMaster $authorized;

    private ProductMaster $unauthorized;

    private ProductMaster $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $this->employee->employee_id,
        ]);
        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)
            ->create(['business_name' => 'Aaa Primary Depot']);

        $this->authorized = ProductMaster::factory()->forCompany($this->company)
            ->create(['product_description' => 'Vegetable Oil 1L', 'product_sku' => 'EM-OIL-1L', 'basic_unit' => 'PCS']);
        $this->unauthorized = ProductMaster::factory()->forCompany($this->company)
            ->create(['product_description' => 'Vegetable Oil 5L', 'product_sku' => 'EM-OIL-5L', 'basic_unit' => 'PCS']);

        $other = CompanyMaster::factory()->create();
        $this->foreign = ProductMaster::factory()->forCompany($other)
            ->create(['product_description' => 'Vegetable Oil 25L', 'product_sku' => 'XX-OIL-25L', 'basic_unit' => 'PCS']);

        // The employee may only sell/count ONE of the two company products.
        EmployeeProduct::create([
            'employee_id' => $this->employee->employee_id,
            'product_id' => $this->authorized->product_id,
        ]);
    }

    public function test_n_product_search_returns_only_authorized_products(): void
    {
        $response = $this->actingAs($this->seller)->getJson(route('search.products', ['q' => 'Vegetable Oil']));

        $response->assertOk();

        $ids = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($this->authorized->product_id, $ids);
        $this->assertNotContains($this->unauthorized->product_id, $ids, 'A product outside the employee scope is never returned.');
        $this->assertNotContains($this->foreign->product_id, $ids, 'Another company\'s product is never returned.');

        // The employee can search by CODE too, and each result carries the
        // authoritative base unit the selector shows read-only.
        $bySku = $this->actingAs($this->seller)->getJson(route('search.products', ['q' => 'EM-OIL-1L']));
        $bySku->assertOk();
        $this->assertSame([$this->authorized->product_id], collect($bySku->json('results'))->pluck('id')->all());
        $this->assertSame('PCS', $bySku->json('results.0.basic_unit'));
        $this->assertStringContainsString('EM-OIL-1L', (string) $bySku->json('results.0.meta'));

        // Even an exact unauthorized SKU never surfaces.
        $hidden = $this->actingAs($this->seller)->getJson(route('search.products', ['q' => 'EM-OIL-5L']));
        $hidden->assertOk();
        $this->assertSame([], $hidden->json('results'));

        $this->actingAs($this->seller)->getJson(route('search.products', ['q' => 'XX-OIL-25L']))
            ->assertOk()
            ->assertExactJson(['results' => []]);
    }

    public function test_n_the_count_page_offers_only_authorized_products_through_the_searchable_selector(): void
    {
        $page = $this->actingAs($this->seller)->get(route('inventory.counts.create'));

        $page->assertOk();

        // Mobile-friendly searchable selector: combobox + the chosen product id
        // is what gets submitted (no free-text product field).
        $page->assertSee('role="combobox"', false);
        $page->assertSee('Search product name, description or code', false);
        $page->assertSee('data-count-line', false);
        $page->assertSee('[product_id]', false);
        $page->assertSee('read-only', false); // the base unit badge is labelled read-only

        // The embedded fallback catalog is scoped: the authorized product is
        // present, the unauthorized company product and the foreign one are not.
        $page->assertSee($this->authorized->product_id);
        $page->assertDontSee($this->unauthorized->product_id);
        $page->assertDontSee($this->foreign->product_id);
    }

    public function test_n_the_server_refuses_a_count_line_for_an_unauthorized_product(): void
    {
        $this->actingAs($this->seller)->post(route('inventory.counts.store'), [
            'customer_id' => $this->primary->customer_id,
            'count_type' => 'PRIMARY_OPERATIONAL',
            'lines' => [[
                'product_id' => $this->unauthorized->product_id,
                'counted_qty' => '12',
                'count_unit' => 'PCS',
            ]],
        ])->assertForbidden();

        $this->assertSame(0, StockCount::count(), 'A crafted out-of-scope product never starts a count.');

        // The authorized product goes through — the scope guard is the only gate.
        $this->actingAs($this->seller)->post(route('inventory.counts.store'), [
            'customer_id' => $this->primary->customer_id,
            'count_type' => 'PRIMARY_OPERATIONAL',
            'lines' => [[
                'product_id' => $this->authorized->product_id,
                'counted_qty' => '12',
                'count_unit' => 'PCS',
            ]],
        ])->assertRedirect();

        $this->assertSame(1, StockCount::count());
    }
}
