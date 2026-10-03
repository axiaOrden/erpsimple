<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMaster extends Model
{
    use HasFactory;

    protected $table = 'customer_master';

    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'business_name',
        'customer_type',
        'parent_customer_id',
        'ext_origin_id',
        'ext_origin_company',
        'contact_person',
        'phone_number',
        'phone_canonical',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_customer_id', 'customer_id');
    }

    public function shipToLocations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_customer_id', 'customer_id');
    }

    public function employees(): BelongsToMany
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

    public function salesRegion(): BelongsTo
    {
        return $this->belongsTo(SalesRegion::class, 'sales_region', 'region_code');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class, 'customer_id', 'customer_id');
    }

    /**
     * Orders where this customer is the demand owner (Secondary/VAN/etc.).
     * NOTE: this does NOT imply the customer is the invoice debtor — that
     * rule is unresolved (docs/ARCHITECTURE.md risk 3).
     */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'sold_to_customer_id', 'customer_id');
    }
}
