<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('product_master'));

        if (! $indexes->contains(fn (array $index): bool => $index['name'] === 'idx_pm_company_sku')) {
            Schema::table('product_master', function (Blueprint $table) {
                $table->index(['company_id', 'product_sku'], 'idx_pm_company_sku');
            });
        }

        if ($indexes->contains(fn (array $index): bool => $index['name'] === 'uq_pm_company_sku')) {
            Schema::table('product_master', function (Blueprint $table) {
                $table->dropUnique('uq_pm_company_sku');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('product_master'));

        if (! $indexes->contains(fn (array $index): bool => $index['name'] === 'uq_pm_company_sku')) {
            Schema::table('product_master', function (Blueprint $table) {
                $table->unique(['company_id', 'product_sku'], 'uq_pm_company_sku');
            });
        }

        if ($indexes->contains(fn (array $index): bool => $index['name'] === 'idx_pm_company_sku')) {
            Schema::table('product_master', function (Blueprint $table) {
                $table->dropIndex('idx_pm_company_sku');
            });
        }
    }
};
