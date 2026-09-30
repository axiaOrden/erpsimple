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
        Schema::table('customer_visit_attendance', function (Blueprint $table) {
            $table->dateTime('device_captured_at')->nullable()->after('attendance_datetime');
            $table->boolean('device_timestamp_flag')->default(false)->after('device_captured_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_visit_attendance', function (Blueprint $table) {
            $table->dropColumn(['device_captured_at', 'device_timestamp_flag']);
        });
    }
};
