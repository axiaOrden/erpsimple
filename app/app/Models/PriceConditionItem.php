<?php

namespace App\Models;

use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Model;

class PriceConditionItem extends Model
{
    public $timestamps = false;

    protected $table = 'price_condition_item';

    public $incrementing = false;

    protected $fillable = [
        'condition_price_no',
        'product_id',
        'price',
        'currency',
        'tax_type',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tax_type' => TaxType::class,
        ];
    }

    public function condition()
    {
        return $this->belongsTo(PriceCondition::class, 'condition_price_no', 'condition_price_no');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }
}
