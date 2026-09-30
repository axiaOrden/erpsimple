<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

/** alt qty * numerator / denominator = basic qty */
class ProductUnitConversion extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'product_unit_conversion';

    public $incrementing = false;

    protected $fillable = ['product_id', 'alternative_unit', 'numerator', 'denominator'];

    protected function casts(): array
    {
        return [
            'numerator' => 'decimal:6',
            'denominator' => 'decimal:6',
        ];
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(UnitMaster::class, 'alternative_unit', 'unit_code');
    }

    protected function compositeKeyColumns(): array
    {
        return ['product_id', 'alternative_unit'];
    }
}
