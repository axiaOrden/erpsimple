<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesRegion extends Model
{
    protected $table = 'sales_region';

    protected $primaryKey = 'region_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['region_code', 'description', 'zone', 'sort_order'];

    public function employees(): HasMany
    {
        return $this->hasMany(EmployeeMaster::class, 'region_code', 'region_code');
    }
}
