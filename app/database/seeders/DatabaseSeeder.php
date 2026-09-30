<?php

namespace Database\Seeders;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerEmployee;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
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

        $this->seedDemoFieldData();
        $this->seedDemoFjp();
        $this->seedDemoProducts();
    }

    /** Demo products for each company so the product list has content. */
    private function seedDemoProducts(): void
    {
        $products = [
            ['company_id' => 'EMANL', 'product_id' => 'EM-PASTA-500', 'product_description' => 'Pasta 500g', 'product_sku' => 'EM-PAS-500', 'product_category' => 'Food', 'basic_unit' => 'PCS'],
            ['company_id' => 'EMANL', 'product_id' => 'EM-OIL-1L', 'product_description' => 'Vegetable Oil 1L', 'product_sku' => 'EM-OIL-1L', 'product_category' => 'Food', 'basic_unit' => 'PCS'],
            ['company_id' => 'PB', 'product_id' => 'PB-BISC-CTN', 'product_description' => 'Biscuits Carton', 'product_sku' => 'PB-BIS-CTN', 'product_category' => 'Food', 'basic_unit' => 'CTN'],
            ['company_id' => 'NB', 'product_id' => 'NB-SOAP-CTN', 'product_description' => 'Soap Carton', 'product_sku' => 'NB-SOA-CTN', 'product_category' => 'Home Care', 'basic_unit' => 'CTN'],
            ['company_id' => 'PF', 'product_id' => 'PF-RICE-50KG', 'product_description' => 'Rice 50kg Bag', 'product_sku' => 'PF-RIC-50', 'product_category' => 'Food', 'basic_unit' => 'KG'],
        ];

        foreach ($products as $p) {
            ProductMaster::updateOrCreate(
                ['product_id' => $p['product_id']],
                $p + ['active' => true],
            );
        }
    }

    /**
     * Demo customers + assignments + journey plan for the sales employee,
     * so the field dashboard has real content on first run.
     */
    private function seedDemoFieldData(): void
    {
        if (CustomerMaster::count() > 0) {
            return;
        }

        $customers = [
            ['customer_id' => 'MIMZA', 'business_name' => 'Mimza Distribution', 'customer_type' => 'PRIMARY', 'city' => 'Lagos', 'sales_region' => 'SW'],
            ['customer_id' => 'ABC', 'business_name' => 'ABC Traders', 'customer_type' => 'PRIMARY', 'city' => 'Lagos', 'sales_region' => 'SW'],
            ['customer_id' => 'MAMA-CHI', 'business_name' => 'Mama Chi Stores', 'customer_type' => 'SECONDARY', 'city' => 'Lagos', 'sales_region' => 'SW'],
            ['customer_id' => 'VAN-01', 'business_name' => 'Van 01 (Lagos)', 'customer_type' => 'VAN', 'city' => 'Lagos', 'sales_region' => 'SW'],
        ];

        foreach ($customers as $c) {
            CustomerMaster::updateOrCreate(
                ['customer_id' => $c['customer_id']],
                $c + ['active' => true],
            );

            CustomerEmployee::updateOrCreate(
                [
                    'customer_id' => $c['customer_id'],
                    'employee_id' => 'EMP-SE-001',
                    'role' => 'SE',
                ],
                [],
            );
        }

    }

    /** FJP demo: every weekday across all four rotation weeks. */
    private function seedDemoFjp(): void
    {
        foreach (['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'] as $day) {
            foreach ([1, 2, 3, 4] as $week) {
                CustomerFjp::updateOrCreate(
                    [
                        'company_id' => 'EMANL',
                        'employee_id' => 'EMP-SE-001',
                        'customer_id' => 'MAMA-CHI',
                        'preferred_week' => $week,
                        'preferred_day' => $day,
                    ],
                    ['active' => true],
                );
            }
        }
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
