<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

class CreditAllocation extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'credit_allocation';

    public $incrementing = false;

    protected $fillable = ['credit_no', 'invoice_no', 'allocated_amount', 'allocated_at'];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'allocated_at' => 'datetime',
        ];
    }

    public function credit()
    {
        return $this->belongsTo(CustomerCredit::class, 'credit_no', 'credit_no');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_no', 'invoice_no');
    }

    protected function compositeKeyColumns(): array
    {
        return ['credit_no', 'invoice_no'];
    }
}
