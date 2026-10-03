<?php

namespace Tests\Feature;

use App\Models\CustomerMaster;
use Database\Seeders\LagosCustomerSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LagosCustomerSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeds_lagos_customers_with_auto_increment_ids_and_origin_details(): void
    {
        CustomerMaster::query()->delete();

        $this->seed(LagosCustomerSeeder::class);

        $this->assertSame(3758, CustomerMaster::count());
        $this->assertSame(3698, CustomerMaster::where('customer_type', 'SECONDARY')->count());
        $this->assertSame(60, CustomerMaster::where('customer_type', 'PRIMARY')->count());

        $secondary = CustomerMaster::where('business_name', 'Iya ayo store')->firstOrFail();
        $this->assertIsInt($secondary->customer_id);
        $this->assertSame('EMANL', $secondary->ext_origin_company);
        $this->assertNull($secondary->ext_origin_id);
        $this->assertSame('+2349052938003', $secondary->phone_canonical);

        $this->assertDatabaseHas('customer_master', [
            'business_name' => 'Adicom',
            'customer_type' => 'PRIMARY',
            'ext_origin_id' => '1000001',
            'ext_origin_company' => 'EMANL',
        ]);

        $this->assertSame(2, CustomerMaster::whereIn('business_name', [
            'Iya Aisha and son',
            'Iya Aishat And Son Enterprise',
        ])->whereNull('phone_canonical')->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        CustomerMaster::query()->delete();

        $this->seed(LagosCustomerSeeder::class);
        $this->seed(LagosCustomerSeeder::class);

        $this->assertSame(3758, CustomerMaster::count());
    }
}
