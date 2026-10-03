<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->clearCustomerDependentData();

        Schema::table('customer_master', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_master', 'ext_origin_id')) {
                $table->string('ext_origin_id', 100)->nullable()->after('parent_customer_id');
            }

            if (! Schema::hasColumn('customer_master', 'ext_origin_company')) {
                $table->string('ext_origin_company', 100)->nullable()->after('ext_origin_id');
            }
        });

        if (Schema::getColumnType('customer_master', 'customer_id') === 'bigint') {
            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $this->dropCustomerForeignKeys();
            $this->modifyCustomerColumns('BIGINT UNSIGNED');
            $this->addCustomerForeignKeys();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $this->dropCustomerForeignKeys();
            $this->modifyCustomerColumns('VARCHAR(50)', false);
            $this->addCustomerForeignKeys();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Schema::table('customer_master', function (Blueprint $table) {
            $table->dropColumn(['ext_origin_id', 'ext_origin_company']);
        });
    }

    private function clearCustomerDependentData(): void
    {
        $tables = [
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

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function dropCustomerForeignKeys(): void
    {
        foreach ($this->customerForeignKeys() as $table => $foreignKeys) {
            foreach (array_keys($foreignKeys) as $foreignKey) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$foreignKey}`");
            }
        }
    }

    private function modifyCustomerColumns(string $type, bool $autoIncrement = true): void
    {
        $primaryDefinition = $autoIncrement
            ? "{$type} NOT NULL AUTO_INCREMENT"
            : "{$type} NOT NULL";

        DB::statement("ALTER TABLE `customer_master` MODIFY `customer_id` {$primaryDefinition}");
        DB::statement("ALTER TABLE `customer_master` MODIFY `parent_customer_id` {$type} NULL");

        foreach ($this->customerForeignKeys() as $table => $foreignKeys) {
            if ($table === 'customer_master') {
                continue;
            }

            foreach ($foreignKeys as $column) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} NOT NULL");
            }
        }
    }

    private function addCustomerForeignKeys(): void
    {
        foreach ($this->customerForeignKeys() as $table => $foreignKeys) {
            foreach ($foreignKeys as $foreignKey => $column) {
                DB::statement(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$foreignKey}` "
                    ."FOREIGN KEY (`{$column}`) REFERENCES `customer_master` (`customer_id`)"
                );
            }
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function customerForeignKeys(): array
    {
        return [
            'customer_master' => ['fk_cm_parent' => 'parent_customer_id'],
            'customer_employee' => ['fk_ce_customer' => 'customer_id'],
            'customer_fjp' => ['fk_fjp_customer' => 'customer_id'],
            'customer_visit_attendance' => ['fk_cva_customer' => 'customer_id'],
            'inventory' => ['fk_inv_customer' => 'customer_id'],
            'stock_count' => ['fk_sc_customer' => 'customer_id'],
            'sales_order' => [
                'fk_so_supplier' => 'supplying_customer_id',
                'fk_so_source' => 'source_customer_id',
                'fk_so_soldto' => 'sold_to_customer_id',
            ],
            'delivery' => [
                'fk_del_source' => 'source_customer_id',
                'fk_del_customer' => 'customer_id',
            ],
            'shipment' => ['fk_ship_source' => 'source_customer_id'],
            'inventory_movement' => ['fk_im_customer' => 'customer_id'],
            'invoice' => ['fk_invoice_customer' => 'customer_id'],
            'payment' => ['fk_pay_customer' => 'customer_id'],
            'customer_credit' => ['fk_cc_customer' => 'customer_id'],
            'transit_stock' => ['fk_ts_source' => 'source_customer_id'],
        ];
    }
};
