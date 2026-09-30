<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitMaster extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'unit_master';

    protected $primaryKey = 'unit_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['unit_code', 'unit_description'];
}
