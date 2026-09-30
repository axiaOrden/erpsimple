<?php

namespace Database\Factories;

use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerMaster> */
class CustomerMasterFactory extends Factory
{
    protected $model = CustomerMaster::class;

    public function definition(): array
    {
        return [
            'customer_id' => 'CUS-'.strtoupper($this->faker->unique()->lexify('??????')),
            'business_name' => $this->faker->company(),
            'customer_type' => 'SECONDARY',
            'active' => true,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['customer_type' => 'PRIMARY']);
    }

    public function van(): static
    {
        return $this->state(fn () => ['customer_type' => 'VAN']);
    }

    /** Assign the customer to an employee (commercial relationship). */
    public function forEmployee(?EmployeeMaster $employee): static
    {
        return $this->afterCreating(function (CustomerMaster $customer) use ($employee) {
            if ($employee === null) {
                return;
            }

            CustomerEmployee::create([
                'customer_id' => $customer->customer_id,
                'employee_id' => $employee->employee_id,
                'role' => 'SE',
            ]);
        });
    }
}
