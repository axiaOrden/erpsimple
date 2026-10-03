<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_fjp AS kept
            INNER JOIN (
                SELECT MIN(fjp_id) AS keep_id, MAX(active) AS active
                FROM customer_fjp
                GROUP BY company_id, customer_id, preferred_week, preferred_day
            ) AS grouped ON grouped.keep_id = kept.fjp_id
            SET kept.active = grouped.active
            SQL);

        DB::statement(<<<'SQL'
            DELETE duplicate
            FROM customer_fjp AS duplicate
            INNER JOIN customer_fjp AS kept
                ON kept.company_id = duplicate.company_id
                AND kept.customer_id = duplicate.customer_id
                AND kept.preferred_week <=> duplicate.preferred_week
                AND kept.preferred_day = duplicate.preferred_day
                AND kept.fjp_id < duplicate.fjp_id
            SQL);

        if (! $this->indexExists('customer_fjp', 'idx_fjp_company')) {
            Schema::table('customer_fjp', function (Blueprint $table) {
                $table->index('company_id', 'idx_fjp_company');
            });
        }

        if ($this->foreignKeyExists('customer_fjp', 'fk_fjp_employee')) {
            Schema::table('customer_fjp', function (Blueprint $table) {
                $table->dropForeign('fk_fjp_employee');
            });
        }

        Schema::table('customer_fjp', function (Blueprint $table) {
            $table->dropIndex('idx_fjp_plan');
            $table->dropIndex('idx_fjp_employee');
            $table->dropColumn('employee_id');

            $table->index(['company_id', 'preferred_day', 'preferred_week'], 'idx_fjp_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_fjp', function (Blueprint $table) {
            $table->dropIndex('idx_fjp_plan');
            $table->string('employee_id', 50)->nullable()->after('company_id');
            $table->index(['company_id', 'employee_id', 'preferred_day'], 'idx_fjp_plan');
            $table->index('employee_id', 'idx_fjp_employee');
            $table->foreign('employee_id', 'fk_fjp_employee')
                ->references('employee_id')
                ->on('employee_master');
            $table->dropIndex('idx_fjp_company');
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
