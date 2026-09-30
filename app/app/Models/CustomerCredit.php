<?php

namespace App\Models;

use App\Enums\CreditSource;
use App\Enums\CreditStatus;
use Illuminate\Database\Eloquent\Model;

/** Customer credit: separate financial document (POD damage, return, manual, overpayment). */
class CustomerCredit extends Model
{
    protected $table = 'customer_credit';

    protected $primaryKey = 'credit_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'credit_no',
        'company_id',
        'customer_id',
        'credit_source',
        'source_reference',
        'original_amount',
        'remaining_amount',
        'currency',
        'credit_status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'credit_source' => CreditSource::class,
            'credit_status' => CreditStatus::class,
            'original_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function allocations()
    {
        return $this->hasMany(CreditAllocation::class, 'credit_no', 'credit_no');
    }
}
