<?php

namespace Database\Factories;

use App\Models\CompanyMaster;
use App\Models\ProductMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductMaster> */
class ProductMasterFactory extends Factory
{
    protected $model = ProductMaster::class;

    public function definition(): array
    {
        $company = CompanyMaster::factory()->create();

        return [
            'product_id' => 'PRD-'.strtoupper($this->faker->unique()->lexify('??????')),
            'company_id' => $company->company_id,
            'product_description' => $this->faker->words(3, true),
            'product_sku' => 'SKU-'.strtoupper($this->faker->unique()->lexify('??????')),
            'basic_unit' => 'PCS',
            'active' => true,
        ];
    }

    /** Pin the product to an existing company (avoids creating a new one). */
    public function forCompany(CompanyMaster $company): static
    {
        return $this->state(fn () => ['company_id' => $company->company_id]);
    }
}
