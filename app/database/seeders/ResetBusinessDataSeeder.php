<?php

namespace Database\Seeders;

use App\Models\ProductMaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SplFileObject;

class ResetBusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        $introducedExternalIds = $this->introducedProductExternalIds();
        $productsToRemove = ProductMaster::query()
            ->whereNull('ext_product_id')
            ->orWhereNotIn('ext_product_id', $introducedExternalIds)
            ->pluck('product_id');

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($this->transactionalTables() as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            foreach (['employee_product', 'product_unit_conversion', 'price_condition_item', 'deal_qualifier', 'deal_reward'] as $table) {
                DB::table($table)->whereIn('product_id', $productsToRemove)->delete();
            }

            ProductMaster::whereIn('product_id', $productsToRemove)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->call([
            EmanlProductSeeder::class,
            LagosCustomerSeeder::class,
        ]);
    }

    /**
     * @return list<string>
     */
    private function introducedProductExternalIds(): array
    {
        $path = database_path('seeders/data/emanl-products.csv');
        $file = new SplFileObject($path);
        $file->setCsvControl(',', '"', '');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $headers = $file->fgetcsv();

        if (! is_array($headers)) {
            throw new RuntimeException("CSV file {$path} has no header row.");
        }

        $externalIdIndex = array_search('ext_product_id', $headers, true);

        if ($externalIdIndex === false) {
            throw new RuntimeException("CSV file {$path} has no ext_product_id column.");
        }

        $externalIds = [];

        while (! $file->eof()) {
            $row = $file->fgetcsv();

            if (is_array($row) && isset($row[$externalIdIndex]) && trim((string) $row[$externalIdIndex]) !== '') {
                $externalIds[] = trim((string) $row[$externalIdIndex]);
            }
        }

        return array_values(array_unique($externalIds));
    }

    /**
     * @return list<string>
     */
    private function transactionalTables(): array
    {
        return [
            'payment_evidence',
            'payment_allocation',
            'credit_allocation',
            'invoice_item',
            'delivery_confirmation',
            'transit_stock_allocation',
            'transit_stock',
            'inventory_movement',
            'shipment_delivery',
            'delivery_item',
            'shipment',
            'delivery',
            'sales_order_item',
            'invoice',
            'payment',
            'customer_credit',
            'sales_order',
            'stock_count_item',
            'stock_count',
            'inventory',
            'customer_visit_attendance',
            'customer_fjp',
            'customer_employee',
            'idempotency_keys',
            'customer_master',
        ];
    }
}
