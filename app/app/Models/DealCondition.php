<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Trade deal header, e.g. "Buy 10 CTN Product A, get 1 CTN Product B free". */
class DealCondition extends Model
{
    protected $table = 'deal_condition';

    protected $primaryKey = 'deal_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'deal_no',
        'company_id',
        'deal_description',
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

    public function qualifiers()
    {
        return $this->hasMany(DealQualifier::class, 'deal_no', 'deal_no');
    }

    public function rewards()
    {
        return $this->hasMany(DealReward::class, 'deal_no', 'deal_no');
    }

    public function validOn(\DateTimeInterface $date): bool
    {
        $d = $date->format('Y-m-d');

        return $this->active
            && $d >= $this->valid_from->format('Y-m-d')
            && $d <= $this->valid_to->format('Y-m-d');
    }
}
