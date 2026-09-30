<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Services\Decimal;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public $timestamps = false;

    protected $table = 'payment';

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'company_id',
        'customer_id',
        'payment_date',
        'payment_method',
        'amount',
        'currency',
        'payment_reference',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentRecordStatus::class,
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** Confirmed amount minus what is already allocated to invoices. */
    public function unallocatedAmount(): string
    {
        if ($this->payment_status !== PaymentRecordStatus::CONFIRMED) {
            return '0.00';
        }

        return Decimal::sub((string) $this->amount, (string) $this->allocations()->sum('allocated_amount'));
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id', 'payment_id');
    }
}
