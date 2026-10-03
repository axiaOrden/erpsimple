<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Install the ERP domain baseline on a new database.
 *
 * The application originally kept this schema only in database/schema. This
 * baseline makes a normal `php artisan migrate` sufficient for a fresh
 * environment while remaining a no-op for an existing ERP installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_master')) {
            $missing = collect($this->tables())
                ->reject(fn (string $table): bool => Schema::hasTable($table));

            if ($missing->isNotEmpty()) {
                throw new RuntimeException(
                    'The existing ERP schema is incomplete. Missing tables: '.$missing->implode(', ').'.',
                );
            }

            return;
        }

        DB::unprepared((string) file_get_contents(database_path('schema/erp-schema.sql')));
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (array_reverse($this->tables()) as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            'company_master',
            'unit_master',
            'sales_region',
            'product_master',
            'product_unit_conversion',
            'customer_master',
            'employee_master',
            'app_user',
            'customer_employee',
            'employee_product',
            'customer_fjp',
            'customer_visit_attendance',
            'price_condition',
            'price_condition_item',
            'deal_condition',
            'deal_qualifier',
            'deal_reward',
            'inventory',
            'stock_count',
            'stock_count_item',
            'sales_order',
            'sales_order_rejection_reason',
            'sales_order_item',
            'delivery',
            'delivery_item',
            'shipment',
            'shipment_delivery',
            'inventory_movement',
            'delivery_confirmation',
            'invoice',
            'invoice_item',
            'payment',
            'payment_allocation',
            'payment_evidence',
            'customer_credit',
            'credit_allocation',
            'transit_stock',
            'transit_stock_allocation',
        ];
    }
};
