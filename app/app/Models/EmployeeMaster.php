<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeMaster extends Model
{
    use HasFactory;

    protected $table = 'employee_master';

    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'employee_id',
        'company_id',
        'employee_name',
        'region_code',
        'partner_function',
        'partner_id',
        'email_address',
        'phone_number',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(SalesRegion::class, 'region_code', 'region_code');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'partner_id', 'employee_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'partner_id', 'employee_id');
    }

    public function user()
    {
        return $this->hasOne(AppUser::class, 'employee_id', 'employee_id');
    }

    public function customers()
    {
        return $this->belongsToMany(
            CustomerMaster::class,
            'customer_employee',
            'employee_id',
            'customer_id',
            'employee_id',
            'customer_id',
        )->withPivot(['role', 'valid_from', 'valid_to']);
    }

    public function productScope()
    {
        return $this->belongsToMany(
            ProductMaster::class,
            'employee_product',
            'employee_id',
            'product_id',
            'employee_id',
            'product_id',
        );
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
