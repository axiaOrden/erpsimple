<?php

namespace Tests\Feature;

use App\Models\CompanyMaster;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use Database\Seeders\EmanlProductSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmanlProductSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        CompanyMaster::updateOrCreate(
            ['company_id' => 'EMANL'],
            ['company_name' => 'Euro Mega Atlantic Nigeria LTD'],
        );

        foreach (['CTN' => 'Carton', 'PCS' => 'Pieces'] as $code => $description) {
            DB::table('unit_master')->updateOrInsert(
                ['unit_code' => $code],
                ['unit_description' => $description],
            );
        }
    }

    public function test_seeds_all_csv_products_and_piece_conversions(): void
    {
        $this->seed(EmanlProductSeeder::class);

        $this->assertSame(46, ProductMaster::where('company_id', 'EMANL')
            ->whereIn('product_id', $this->csvProductIds())
            ->count());
        $this->assertSame(46, ProductUnitConversion::whereIn('product_id', $this->csvProductIds())->count());

        $this->assertDatabaseHas('product_master', [
            'product_id' => 'EMANL-16000013',
            'company_id' => 'EMANL',
            'product_description' => 'SEDAP SUPREME RICH SPICES 70GR',
            'product_category' => '1902.3',
            'product_sku' => '16000013',
            'sku_description' => 'Sedap Supreme Rich Spices',
            'basic_unit' => 'CTN',
            'ext_product_id' => '16000013',
            'issuing_company' => 'EMANL',
            'active' => true,
        ]);
        $this->assertDatabaseHas('product_unit_conversion', [
            'product_id' => 'EMANL-16000013',
            'alternative_unit' => 'PCS',
            'numerator' => '1.000000',
            'denominator' => '40.000000',
        ]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(EmanlProductSeeder::class);
        $this->seed(EmanlProductSeeder::class);

        $this->assertSame(46, ProductMaster::whereIn('product_id', $this->csvProductIds())->count());
        $this->assertSame(46, ProductUnitConversion::whereIn('product_id', $this->csvProductIds())->count());
    }

    /**
     * @return list<string>
     */
    private function csvProductIds(): array
    {
        $rows = file(database_path('seeders/data/emanl-products.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(
            fn (string $row): string => 'EMANL-'.str_getcsv($row)[5],
            array_slice($rows, 1),
        );
    }
}
