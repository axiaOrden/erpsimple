<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductMaster extends Model
{
    use HasFactory;

    protected $table = 'product_master';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id',
        'company_id',
        'product_description',
        'product_category',
        'product_sku',
        'sku_description',
        'basic_unit',
        'ext_product_id',
        'issuing_company',
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

    public function basicUnit()
    {
        return $this->belongsTo(UnitMaster::class, 'basic_unit', 'unit_code');
    }

    public function unitConversions()
    {
        return $this->hasMany(ProductUnitConversion::class, 'product_id', 'product_id');
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('product_description', 'like', "%{$term}%")
                ->orWhere('product_sku', 'like', "%{$term}%");
        });
    }
}
