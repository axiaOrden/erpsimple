<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductUnitConversionTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $companyA;

    private CompanyMaster $companyB;

    private AppUser $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = CompanyMaster::factory()->create();
        $this->companyB = CompanyMaster::factory()->create();
        $this->adminA = AppUser::factory()->companyAdmin()->create([
            'employee_id' => null,
            'company_id' => $this->companyA->company_id,
        ]);
    }

    private function createProduct(): ProductMaster
    {
        return ProductMaster::factory()->forCompany($this->companyA)->create(['basic_unit' => 'PCS']);
    }

    public function test_conversion_is_saved_with_product(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'active' => '1',
            'conversions' => [
                ['alternative_unit' => 'CTN', 'numerator' => '24', 'denominator' => '1'],
            ],
        ])->assertRedirect('/products');

        $this->assertDatabaseHas('product_unit_conversion', [
            'product_id' => $product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);
    }

    public function test_basic_unit_cannot_be_an_alternative(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'PCS', 'numerator' => '1', 'denominator' => '1'],
            ],
        ])->assertSessionHasErrors('unit_conversions');

        $this->assertDatabaseMissing('product_unit_conversion', ['product_id' => $product->product_id]);
    }

    public function test_unknown_unit_is_rejected(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'PALLET', 'numerator' => '100', 'denominator' => '1'],
            ],
        ])->assertSessionHasErrors('unit_conversions');
    }

    public function test_zero_numerator_is_rejected(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'CTN', 'numerator' => '0', 'denominator' => '1'],
            ],
        ])->assertSessionHasErrors('unit_conversions');
    }

    public function test_zero_denominator_is_rejected(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'CTN', 'numerator' => '24', 'denominator' => '0'],
            ],
        ])->assertSessionHasErrors('unit_conversions');
    }

    public function test_duplicate_alternative_units_are_rejected(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'CTN', 'numerator' => '24', 'denominator' => '1'],
                ['alternative_unit' => 'CTN', 'numerator' => '48', 'denominator' => '1'],
            ],
        ])->assertSessionHasErrors('unit_conversions');
    }

    public function test_updating_replaces_previous_conversions(): void
    {
        $product = $this->createProduct();

        ProductUnitConversion::create([
            'product_id' => $product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        $this->actingAs($this->adminA)->patch('/products/'.$product->product_id, [
            'product_description' => $product->product_description,
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'KG', 'numerator' => '10', 'denominator' => '1'],
            ],
        ])->assertRedirect('/products');

        $this->assertDatabaseMissing('product_unit_conversion', [
            'product_id' => $product->product_id,
            'alternative_unit' => 'CTN',
        ]);
        $this->assertDatabaseHas('product_unit_conversion', [
            'product_id' => $product->product_id,
            'alternative_unit' => 'KG',
        ]);
    }

    public function test_admin_of_other_company_cannot_modify_conversions(): void
    {
        $product = $this->createProduct();
        $adminB = AppUser::factory()->companyAdmin()->create([
            'employee_id' => null,
            'company_id' => $this->companyB->company_id,
        ]);

        $this->actingAs($adminB)->patch('/products/'.$product->product_id, [
            'product_description' => 'Hijacked',
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
            'conversions' => [
                ['alternative_unit' => 'CTN', 'numerator' => '1', 'denominator' => '1'],
            ],
        ])->assertForbidden();

        // Nothing changed.
        $this->assertDatabaseHas('product_master', [
            'product_id' => $product->product_id,
            'product_description' => $product->product_description,
        ]);
        $this->assertDatabaseMissing('product_unit_conversion', ['product_id' => $product->product_id]);
    }

    public function test_sales_employee_cannot_modify_conversions(): void
    {
        $product = $this->createProduct();
        $employee = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->companyA->company_id,
        ]);

        $this->actingAs($employee)->patch('/products/'.$product->product_id, [
            'product_description' => 'Hijacked',
            'product_sku' => $product->product_sku,
            'basic_unit' => 'PCS',
        ])->assertForbidden();
    }
}
