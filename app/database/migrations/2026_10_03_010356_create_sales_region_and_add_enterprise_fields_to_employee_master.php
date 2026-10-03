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
        if (Schema::hasTable('sales_region')
            && Schema::hasColumn('employee_master', 'region_code')
            && Schema::hasColumn('employee_master', 'partner_function')
            && Schema::hasColumn('employee_master', 'partner_id')) {
            return;
        }

        if (! Schema::hasTable('sales_region')) {
            Schema::create('sales_region', function (Blueprint $table) {
                $table->string('region_code', 20)->primary();
                $table->string('description', 100);
                $table->string('zone', 50);
                $table->unsignedInteger('sort_order');
                $table->timestamps();

                $table->index(['zone', 'sort_order']);
            });
        }

        if (! Schema::hasColumn('employee_master', 'region_code')) {
            Schema::table('employee_master', function (Blueprint $table) {
                $table->string('region_code', 20)->nullable()->after('employee_name');
                $table->foreign('region_code')->references('region_code')->on('sales_region');
                $table->index(['company_id', 'region_code']);
            });
        }

        if (! Schema::hasColumn('employee_master', 'partner_function')) {
            Schema::table('employee_master', function (Blueprint $table) {
                $table->string('partner_function', 20)->nullable()->after('region_code');
                $table->index(['company_id', 'partner_function']);
            });
        }

        if (! Schema::hasColumn('employee_master', 'partner_id')) {
            Schema::table('employee_master', function (Blueprint $table) {
                $table->string('partner_id', 50)->nullable()->after('partner_function');
                $table->foreign('partner_id')->references('employee_id')->on('employee_master');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_master', function (Blueprint $table) {
            $table->dropForeign(['region_code']);
            $table->dropForeign(['partner_id']);
            $table->dropIndex(['company_id', 'region_code']);
            $table->dropIndex(['company_id', 'partner_function']);
            $table->dropColumn(['region_code', 'partner_function', 'partner_id']);
        });

        Schema::dropIfExists('sales_region');
    }
};
