<?php

namespace App\Models;

use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentTerm;
use App\Services\Decimal;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoice snapshot created at SO confirmation; never rewritten by later price
 * changes. customer_id is the FINANCIAL DEBTOR — which SO party that is is an
 * unresolved business rule (docs/ARCHITECTURE.md risk 3); it must not be
 * assumed to be the SO's sold_to_customer_id.
 */
class Invoice extends Model
{
    protected $table = 'invoice';

    protected $primaryKey = 'invoice_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_no',
        'company_id',
        'customer_id',
        'sales_order_no',
        'invoice_date',
        'due_date',
        'currency',
        'gross_amount',
        'discount_amount',
        'tax_amount',
        'invoice_amount',
        'credit_amount',
        'settled_amount',
        'payment_status',
        'payment_term',
        'public_token',
    ];

    protected function casts(): array
    {
        return [
            'payment_status' => InvoicePaymentStatus::class,
            'payment_term' => PaymentTerm::class,
            'invoice_date' => 'datetime',
            'due_date' => 'date',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'invoice_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** invoice_amount - credit_amount - settled_amount, floored at 0. */
    public function outstandingAmount(): string
    {
        $outstanding = Decimal::sub(
            Decimal::sub((string) $this->invoice_amount, (string) $this->credit_amount),
            (string) $this->settled_amount,
        );

        return Decimal::compare($outstanding, '0') > 0 ? $outstanding : '0.00';
    }

    public function isSettled(): bool
    {
        return $this->payment_status === InvoicePaymentStatus::PAID;
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_no', 'sales_order_no');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_no', 'invoice_no');
    }

    public function paymentAllocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_no', 'invoice_no');
    }

    public function creditAllocations()
    {
        return $this->hasMany(CreditAllocation::class, 'invoice_no', 'invoice_no');
    }

    /** Total applied against this invoice (payments + credits). */
    public function appliedAmount(): string
    {
        return Decimal::add((string) $this->settled_amount, (string) $this->credit_amount, 2);
    }
}
