<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerMaster extends Model
{
    use HasFactory;

    protected $table = 'customer_master';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'business_name',
        'customer_type',
        'parent_customer_id',
        'contact_person',
        'phone_number',
        'email_address',
        'gps_latitude',
        'gps_longitude',
        'address',
        'address2',
        'state',
        'city',
        'postal_code',
        'country',
        'sales_region',
        'market',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'active' => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_customer_id', 'customer_id');
    }

    public function shipToLocations()
    {
        return $this->hasMany(self::class, 'parent_customer_id', 'customer_id');
    }

    public function employees()
    {
        return $this->belongsToMany(
            EmployeeMaster::class,
            'customer_employee',
            'customer_id',
            'employee_id',
            'customer_id',
            'employee_id',
        )->withPivot(['role', 'valid_from', 'valid_to']);
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'customer_id', 'customer_id');
    }

    /**
     * Orders where this customer is the demand owner (Secondary/VAN/etc.).
     * NOTE: this does NOT imply the customer is the invoice debtor — that
     * rule is unresolved (docs/ARCHITECTURE.md risk 3).
     */
    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class, 'sold_to_customer_id', 'customer_id');
    }
}
