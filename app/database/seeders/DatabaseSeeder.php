<?php

namespace Database\Seeders;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\EmployeeMaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Companies and units mirror the ddl.sql seed data; keep them idempotent.
        $companies = [
            ['company_id' => 'EMANL', 'company_name' => 'Euro Mega Atlantic Nigeria LTD'],
            ['company_id' => 'PB', 'company_name' => 'Prime Bisco Limited'],
            ['company_id' => 'NB', 'company_name' => 'New Bisco Limited'],
            ['company_id' => 'PF', 'company_name' => 'Primera Food Limited'],
        ];

        foreach ($companies as $company) {
            CompanyMaster::updateOrCreate(
                ['company_id' => $company['company_id']],
                $company,
            );
        }

        $units = [
            ['unit_code' => 'PCS', 'unit_description' => 'Pieces'],
            ['unit_code' => 'CTN', 'unit_description' => 'Carton'],
            ['unit_code' => 'KG', 'unit_description' => 'Kilogram'],
            ['unit_code' => 'TON', 'unit_description' => 'Ton'],
        ];

        foreach ($units as $unit) {
            DB::table('unit_master')->updateOrInsert(
                ['unit_code' => $unit['unit_code']],
                $unit,
            );
        }

        // Demo employees + users (one per role) for Phase 1 sign-off.
        EmployeeMaster::updateOrCreate(
            ['employee_id' => 'EMP-SE-001'],
            [
                'company_id' => 'EMANL',
                'employee_name' => 'Ayo Field Seller',
                'email_address' => 'sales@emanl.test',
                'active' => true,
            ],
        );

        EmployeeMaster::updateOrCreate(
            ['employee_id' => 'EMP-ADM-001'],
            [
                'company_id' => 'EMANL',
                'employee_name' => 'Emanl Company Admin',
                'email_address' => 'admin@emanl.test',
                'active' => true,
            ],
        );

        $this->upsertUser([
            'email' => 'sales@emanl.test',
            'employee_id' => 'EMP-SE-001',
            'company_id' => 'EMANL',
            'name' => 'Ayo Field Seller',
            'role' => 'SALES_EMPLOYEE',
        ]);

        $this->upsertUser([
            'email' => 'admin@emanl.test',
            'employee_id' => 'EMP-ADM-001',
            'company_id' => 'EMANL',
            'name' => 'Emanl Company Admin',
            'role' => 'COMPANY_ADMIN',
        ]);

        $this->upsertUser([
            'email' => 'super@erp.test',
            'employee_id' => null,
            'company_id' => null,
            'name' => 'Super Admin',
            'role' => 'SUPERADMIN',
        ]);

        $this->call([
            SalesRegionSeeder::class,
            EmanlEmployeeSeeder::class,
            EmanlProductSeeder::class,
            LagosCustomerSeeder::class,
        ]);
    }

    private function upsertUser(array $attributes): void
    {
        $user = AppUser::firstOrNew(['email' => $attributes['email']]);
        $user->fill($attributes);
        $user->active = true;

        if (! $user->exists) {
            $user->password_hash = Hash::make('password');
        }

        $user->save();
    }
}
