<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;

/** Delivery = inventory allocation (unrestricted → restricted). */
class Delivery extends Model
{
    protected $table = 'delivery';

    protected $primaryKey = 'delivery_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'delivery_no',
        'company_id',
        'sales_order_no',
        'source_customer_id',
        'customer_id',
        'delivery_status',
        'created_by',
        'allocated_at',
        'shipped_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_status' => DeliveryStatus::class,
            'created_at' => 'datetime',
            'allocated_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_no', 'sales_order_no');
    }

    public function sourceCustomer()
    {
        return $this->belongsTo(CustomerMaster::class, 'source_customer_id', 'customer_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function createdByEmployee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'created_by', 'employee_id');
    }

    public function items()
    {
        return $this->hasMany(DeliveryItem::class, 'delivery_no', 'delivery_no');
    }

    public function shipmentLink()
    {
        return $this->hasOne(ShipmentDelivery::class, 'delivery_no', 'delivery_no');
    }
}
