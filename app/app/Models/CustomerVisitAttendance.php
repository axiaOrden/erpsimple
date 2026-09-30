<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Customer visit attendance (check-in/out). Multiple records per
 * employee/customer/day are allowed; MIN/MAX give effective in/out times.
 */
class CustomerVisitAttendance extends Model
{
    public $timestamps = false;

    protected $table = 'customer_visit_attendance';

    protected $primaryKey = 'attendance_id';

    protected $fillable = [
        'company_id',
        'employee_id',
        'customer_id',
        'attendance_datetime',
        'device_captured_at',
        'device_timestamp_flag',
        'gps_latitude',
        'gps_longitude',
        'gps_accuracy',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'attendance_datetime' => 'datetime',
            // Device-claimed capture time (preserved, never authoritative).
            'device_captured_at' => 'datetime',
            'device_timestamp_flag' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }
}
