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
        if (! Schema::hasColumn('product_master', 'ext_product_id')) {
            Schema::table('product_master', function (Blueprint $table) {
                $table->string('ext_product_id', 100)->nullable()->after('basic_unit');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('product_master', 'ext_product_id')) {
            Schema::table('product_master', function (Blueprint $table) {
                $table->dropColumn('ext_product_id');
            });
        }
    }
};
