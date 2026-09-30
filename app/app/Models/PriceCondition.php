<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Recommended price header: company + region + validity window. */
class PriceCondition extends Model
{
    protected $table = 'price_condition';

    protected $primaryKey = 'condition_price_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'condition_price_no',
        'company_id',
        'sales_region',
        'valid_from',
        'valid_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function items()
    {
        return $this->hasMany(PriceConditionItem::class, 'condition_price_no', 'condition_price_no');
    }

    /** Is this condition valid on the given date? */
    public function validOn(\DateTimeInterface $date): bool
    {
        $d = $date->format('Y-m-d');

        return $this->active
            && $d >= $this->valid_from->format('Y-m-d')
            && $d <= $this->valid_to->format('Y-m-d');
    }
}
