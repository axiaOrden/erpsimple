<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 audit addition (approved): persist the device-claimed capture time
 * and the skew flag. attendance_datetime stays the authoritative server
 * timestamp; these columns never influence ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_visit_attendance', 'device_captured_at')) {
            Schema::table('customer_visit_attendance', function (Blueprint $table) {
                $table->dateTime('device_captured_at')->nullable()->after('attendance_datetime');
            });
        }

        if (! Schema::hasColumn('customer_visit_attendance', 'device_timestamp_flag')) {
            Schema::table('customer_visit_attendance', function (Blueprint $table) {
                $table->boolean('device_timestamp_flag')->default(false)->after('device_captured_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['device_timestamp_flag', 'device_captured_at'] as $column) {
            if (Schema::hasColumn('customer_visit_attendance', $column)) {
                Schema::table('customer_visit_attendance', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
