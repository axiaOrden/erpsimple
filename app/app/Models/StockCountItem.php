<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'stock_count_item';

    public $incrementing = false;

    protected $fillable = [
        'count_no',
        'item_no',
        'product_id',
        'expected_qty',
        'counted_qty',
        'variance_qty',
        'count_unit',
    ];

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'variance_qty' => 'decimal:3',
        ];
    }

    public function count()
    {
        return $this->belongsTo(StockCount::class, 'count_no', 'count_no');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(UnitMaster::class, 'count_unit', 'unit_code');
    }

    protected function compositeKeyColumns(): array
    {
        return ['count_no', 'item_no'];
    }
}
