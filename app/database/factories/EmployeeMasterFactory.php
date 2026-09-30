<?php

namespace Database\Factories;

use App\Models\CompanyMaster;
use App\Models\EmployeeMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeMaster> */
class EmployeeMasterFactory extends Factory
{
    protected $model = EmployeeMaster::class;

    public function definition(): array
    {
        return [
            // 6 chars of entropy: lexify's unique() state resets per test app
            // instance, so short IDs can collide across tests.
            'employee_id' => 'EMP-'.strtoupper($this->faker->unique()->lexify('??????')),
            'company_id' => CompanyMaster::factory()->create()->company_id,
            'employee_name' => $this->faker->name(),
            'active' => true,
        ];
    }
}
