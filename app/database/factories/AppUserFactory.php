<?php

namespace Database\Factories;

use App\Models\AppUser;
use App\Models\EmployeeMaster;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<AppUser> */
class AppUserFactory extends Factory
{
    protected $model = AppUser::class;

    public function definition(): array
    {
        $employee = EmployeeMaster::factory()->create();

        return [
            'employee_id' => $employee->employee_id,
            'company_id' => $employee->company_id,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role' => 'SALES_EMPLOYEE',
            'active' => true,
        ];
    }

    public function salesEmployee(): static
    {
        return $this->state(fn () => ['role' => 'SALES_EMPLOYEE']);
    }

    public function companyAdmin(): static
    {
        return $this->state(fn () => ['role' => 'COMPANY_ADMIN']);
    }

    public function superadmin(): static
    {
        return $this->state(fn () => [
            'role' => 'SUPERADMIN',
            'employee_id' => null,
            'company_id' => null,
        ]);
    }
}
