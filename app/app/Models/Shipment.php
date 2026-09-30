<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Shipment groups Deliveries from one source customer.
 * START = physical goods issue (restricted stock leaves the ledger).
 */
class Shipment extends Model
{
    protected $table = 'shipment';

    protected $primaryKey = 'shipment_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const CREATED_AT = 'created_on';

    const UPDATED_AT = null;

    protected $fillable = [
        'shipment_no',
        'company_id',
        'source_customer_id',
        'shipment_status',
        'created_by',
        'carrier_employee_id',
        'vehicle_reference',
        'ready_on',
        'started_on',
        'completed_on',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'shipment_status' => ShipmentStatus::class,
            'created_on' => 'datetime',
            'ready_on' => 'datetime',
            'started_on' => 'datetime',
            'completed_on' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function sourceCustomer()
    {
        return $this->belongsTo(CustomerMaster::class, 'source_customer_id', 'customer_id');
    }

    public function createdByEmployee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'created_by', 'employee_id');
    }

    public function carrier()
    {
        return $this->belongsTo(EmployeeMaster::class, 'carrier_employee_id', 'employee_id');
    }

    public function deliveries()
    {
        return $this->belongsToMany(
            Delivery::class,
            'shipment_delivery',
            'shipment_no',
            'delivery_no',
            'shipment_no',
            'delivery_no',
        )->withPivot('sequence_no');
    }
}
