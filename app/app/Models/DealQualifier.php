<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Buy 10 CTN Product A" — minimum qualifying quantity of a product. */
class DealQualifier extends Model
{
    public $timestamps = false;

    protected $table = 'deal_qualifier';

    public $incrementing = false;

    protected $fillable = ['deal_no', 'product_id', 'minimum_qty', 'qualifier_unit'];

    protected function casts(): array
    {
        return ['minimum_qty' => 'decimal:3'];
    }

    public function deal()
    {
        return $this->belongsTo(DealCondition::class, 'deal_no', 'deal_no');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(UnitMaster::class, 'qualifier_unit', 'unit_code');
    }
}
