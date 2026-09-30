<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Get 1 CTN Product B free" — reward granted per for_each quantity of qualifiers. */
class DealReward extends Model
{
    public $timestamps = false;

    protected $table = 'deal_reward';

    public $incrementing = false;

    protected $fillable = [
        'deal_no',
        'product_id',
        'reward_qty',
        'reward_unit',
        'for_each_qty',
        'for_each_unit',
    ];

    protected function casts(): array
    {
        return [
            'reward_qty' => 'decimal:3',
            'for_each_qty' => 'decimal:3',
        ];
    }

    public function deal()
    {
        return $this->belongsTo(DealCondition::class, 'deal_no', 'deal_no');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function rewardUnit()
    {
        return $this->belongsTo(UnitMaster::class, 'reward_unit', 'unit_code');
    }
}
