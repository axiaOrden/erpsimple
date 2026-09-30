<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;

/**
 * Sales Order = DEMAND ONLY. No inventory effect.
 *
 * The three customer identifiers are INDEPENDENT and must not be collapsed:
 * - supplying_customer_id: the Primary distributor commercially supplying the order.
 * - source_customer_id:    the physical stock source (the Primary or one of its SHIP_TOs).
 * - sold_to_customer_id:   the Secondary/VAN/other customer whose demand is captured.
 *
 * invoice.customer_id (the financial debtor) is deliberately NOT derived here —
 * see docs/ARCHITECTURE.md risk 3. One SO = one supplying Primary.
 */
class SalesOrder extends Model
{
    protected $table = 'sales_order';

    protected $primaryKey = 'sales_order_no';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sales_order_no',
        'company_id',
        'supplying_customer_id',
        'source_customer_id',
        'sold_to_customer_id',
        'sales_employee_id',
        'order_type',
        'order_status',
        'order_date',
        'pricing_date',
        'currency',
        'gross_amount',
        'discount_amount',
        'tax_amount',
        'net_amount',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_type' => OrderType::class,
            'order_status' => OrderStatus::class,
            'order_date' => 'datetime',
            'pricing_date' => 'date',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function supplyingCustomer()
    {
        return $this->belongsTo(CustomerMaster::class, 'supplying_customer_id', 'customer_id');
    }

    public function sourceCustomer()
    {
        return $this->belongsTo(CustomerMaster::class, 'source_customer_id', 'customer_id');
    }

    public function soldToCustomer()
    {
        return $this->belongsTo(CustomerMaster::class, 'sold_to_customer_id', 'customer_id');
    }

    public function salesEmployee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'sales_employee_id', 'employee_id');
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_no', 'sales_order_no');
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class, 'sales_order_no', 'sales_order_no');
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'sales_order_no', 'sales_order_no');
    }
}
