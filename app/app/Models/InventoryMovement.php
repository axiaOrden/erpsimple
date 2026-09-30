<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;

/** Immutable physical stock ledger. Allocation is NOT a movement; GOODS_ISSUE on shipment start is. */
class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $table = 'inventory_movement';

    protected $primaryKey = 'movement_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'product_id',
        'movement_type',
        'quantity',
        'basic_unit',
        'reference_type',
        'reference_no',
        'reference_item_no',
        'movement_datetime',
        'created_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity' => 'decimal:3',
            'movement_datetime' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function createdByEmployee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'created_by', 'employee_id');
    }
}
