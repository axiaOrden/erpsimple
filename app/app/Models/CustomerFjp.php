<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fixed Journey Plan: company + employee + customer + preferred visit schedule. */
class CustomerFjp extends Model
{
    protected $table = 'customer_fjp';

    protected $primaryKey = 'fjp_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'employee_id',
        'customer_id',
        'preferred_week',
        'preferred_day',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
