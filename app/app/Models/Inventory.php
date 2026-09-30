<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use App\Services\Decimal;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock at a customer (PRIMARY / SHIP_TO / VAN).
 * ON HAND = unrestricted_qty + restricted_qty.
 * Row (customer_id, product_id) is locked FOR UPDATE during allocation.
 */
class Inventory extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'inventory';

    public $incrementing = false;

    protected $fillable = [
        'customer_id',
        'product_id',
        'unrestricted_qty',
        'restricted_qty',
        'basic_unit',
    ];

    protected function casts(): array
    {
        return [
            'unrestricted_qty' => 'decimal:3',
            'restricted_qty' => 'decimal:3',
            'last_updated' => 'datetime',
        ];
    }

    public function onHandQty(): string
    {
        return Decimal::add((string) $this->unrestricted_qty, (string) $this->restricted_qty, 3);
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function basicUnit()
    {
        return $this->belongsTo(UnitMaster::class, 'basic_unit', 'unit_code');
    }

    protected function compositeKeyColumns(): array
    {
        return ['customer_id', 'product_id'];
    }
}
