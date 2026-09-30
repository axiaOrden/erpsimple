<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyMaster extends Model
{
    use HasFactory;

    protected $table = 'company_master';

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['company_id', 'company_name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function products()
    {
        return $this->hasMany(ProductMaster::class, 'company_id', 'company_id');
    }

    public function employees()
    {
        return $this->hasMany(EmployeeMaster::class, 'company_id', 'company_id');
    }

    public function users()
    {
        return $this->hasMany(AppUser::class, 'company_id', 'company_id');
    }
}
