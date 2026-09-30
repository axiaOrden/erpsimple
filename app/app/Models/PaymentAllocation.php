<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'payment_allocation';

    public $incrementing = false;

    protected $fillable = ['payment_id', 'invoice_no', 'allocated_amount', 'allocated_at'];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'allocated_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'payment_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_no', 'invoice_no');
    }

    protected function compositeKeyColumns(): array
    {
        return ['payment_id', 'invoice_no'];
    }
}
