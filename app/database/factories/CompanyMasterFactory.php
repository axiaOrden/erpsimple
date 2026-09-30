<?php

namespace Database\Factories;

use App\Models\CompanyMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyMaster> */
class CompanyMasterFactory extends Factory
{
    protected $model = CompanyMaster::class;

    public function definition(): array
    {
        return [
            // 6 chars of entropy: lexify's unique() state resets per test app
            // instance, so short IDs can collide across tests.
            'company_id' => 'C'.strtoupper($this->faker->unique()->lexify('??????')),
            'company_name' => $this->faker->company(),
            'active' => true,
        ];
    }
}
