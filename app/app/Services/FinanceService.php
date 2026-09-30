<?php

namespace App\Services;

use App\Enums\CreditStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentRecordStatus;
use App\Models\CreditAllocation;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 — money movement against the sold-to debtor.
 *
 * Rulings (docs/PHASE8_DESIGN.md REV 2 §21/§23):
 *  - Only CONFIRMED payments allocate; allocations are IMMUTABLE history.
 *  - Cancellation: PENDING → CANCELLED ok; CONFIRMED without allocations ok;
 *    CONFIRMED with allocations REJECTED (no refunds/reversals in Phase 8).
 *  - Credits: MANUAL_ADJUSTMENT + OVERPAYMENT implemented; POD_DAMAGE/RETURN
 *    enum-reserved for future workflows. Credits reduce net exposure.
 *  - Every write re-derives invoice settled_amount/payment_status inside the
 *    transaction; money math via Decimal; invoices locked in invoice_no order.
 */
class FinanceService
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function nextCreditNumber(string $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "CR-$companyId-$year-";

        $max = CustomerCredit::where('credit_no', 'like', $prefix.'%')->max('credit_no');

        $seq = $max !== null ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    // ---- Payments ------------------------------------------------------------

    public function createPayment(string $companyId, string $customerId, string $method, string $amount, ?string $reference = null, ?string $paymentDate = null): Payment
    {
        if (Decimal::compare($amount, '0', 2) <= 0) {
            abort(422, 'Payment amount must be positive.');
        }

        return Payment::create([
            'company_id' => $companyId,
            'customer_id' => $customerId, // RULED debtor (sold-to identity)
            'payment_date' => $paymentDate ?? now(),
            'payment_method' => $method,
            'amount' => $amount,
            'currency' => 'NGN',
            'payment_reference' => $reference,
            'payment_status' => PaymentRecordStatus::PENDING,
        ]);
    }

    public function confirmPayment(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->payment_id)->lockForUpdate()->first();

            if ($locked->payment_status !== PaymentRecordStatus::PENDING) {
                abort(422, 'Only PENDING payments can be confirmed.');
            }

            $locked->payment_status = PaymentRecordStatus::CONFIRMED;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Cancellation per ruling §23.2: PENDING always; CONFIRMED only with no
     * allocations (immutable history — never delete/mutate allocations, no
     * refunds in Phase 8).
     */
    public function cancelPayment(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->payment_id)->lockForUpdate()->first();

            if ($locked->payment_status === PaymentRecordStatus::CANCELLED) {
                return $locked;
            }

            $allocated = PaymentAllocation::where('payment_id', $locked->payment_id)->exists();

            if ($locked->payment_status === PaymentRecordStatus::CONFIRMED && $allocated) {
                abort(422, 'This payment already has allocations. Payment history is immutable — use a future reversal workflow.');
            }

            $locked->payment_status = PaymentRecordStatus::CANCELLED;
            $locked->save();

            return $locked;
        });
    }

    /** Allocated so far (immutable allocation rows are the single truth). */
    public function allocatedAmount(Payment $payment): string
    {
        $sum = PaymentAllocation::where('payment_id', $payment->payment_id)->get()
            ->sum(fn (PaymentAllocation $a) => (float) $a->allocated_amount);

        return Decimal::format((float) $sum, 2);
    }

    public function unallocatedAmount(Payment $payment): string
    {
        // Overpayment credit already carved out of this payment's remainder
        // counts as allocated: converting twice (or allocating what a credit
        // already represents) would create money out of nothing.
        $converted = CustomerCredit::where('credit_source', 'OVERPAYMENT')
            ->where('source_reference', 'Payment '.$payment->payment_id)
            ->whereIn('credit_status', [CreditStatus::OPEN, CreditStatus::PARTIALLY_USED])
            ->sum('original_amount');

        return Decimal::sub(
            Decimal::sub((string) $payment->amount, $this->allocatedAmount($payment), 2),
            (string) $converted,
            2,
        );
    }

    /**
     * Allocate a CONFIRMED payment to outstanding invoices of the same debtor
     * (FIFO by invoice_date by default; explicit amounts override).
     *
     * @param  Collection<string, string>|null  $perInvoice  invoice_no => amount
     * @return array{payment: Payment, allocations: Collection}
     */
    public function allocatePayment(Payment $payment, ?Collection $perInvoice = null): array
    {
        /** @var array{payment: Payment, allocations: Collection}|null $result */
        $result = null;

        DB::transaction(function () use (&$result, $payment, $perInvoice) {
            $locked = Payment::whereKey($payment->payment_id)->lockForUpdate()->first();

            if ($locked->payment_status !== PaymentRecordStatus::CONFIRMED) {
                abort(422, 'Only CONFIRMED payments can be allocated.');
            }

            $available = $this->unallocatedAmount($locked);

            if (Decimal::compare($available, '0', 2) <= 0) {
                abort(422, 'This payment is fully allocated.');
            }

            // Explicit requests may not ask for more than the unallocated
            // remainder (§19.10: over-allocation is REJECTED, not clamped).
            if ($perInvoice !== null) {
                $requested = '0.00';

                foreach ($perInvoice as $requestedAmount) {
                    $requested = Decimal::add($requested, (string) $requestedAmount, 2);
                }

                if (Decimal::compare($requested, $available, 2) > 0) {
                    abort(422, "Requested allocation {$requested} exceeds the payment's unallocated remainder {$available}.");
                }
            }

            // FIFO candidates of the same debtor, oldest first, locked in
            // deterministic invoice_no order.
            $invoices = Invoice::where('customer_id', $locked->customer_id)
                ->where('company_id', $locked->company_id)
                ->whereIn('payment_status', [InvoicePaymentStatus::UNPAID, InvoicePaymentStatus::PARTIALLY_PAID])
                ->orderBy('invoice_date')
                ->orderBy('invoice_no')
                ->lockForUpdate()
                ->get();

            $targets = collect();

            foreach ($invoices as $invoice) {
                $targets[$invoice->invoice_no] = $invoice;
            }

            if ($perInvoice !== null) {
                foreach ($perInvoice as $invoiceNo => $amount) {
                    if (! $targets->has($invoiceNo)) {
                        abort(422, "Invoice {$invoiceNo} is not an outstanding invoice of this debtor.");
                    }
                }

                $targets = $targets->filter(fn ($i, $no) => $perInvoice->has($no));
            }

            $remaining = $available;
            $created = collect();

            foreach ($targets as $invoice) {
                if (Decimal::compare($remaining, '0', 2) <= 0) {
                    break;
                }

                $outstanding = Decimal::sub((string) $invoice->invoice_amount, (string) $invoice->settled_amount, 2);

                if (Decimal::compare($outstanding, '0', 2) <= 0) {
                    continue;
                }

                $amount = $perInvoice !== null
                    ? min((float) $perInvoice[$invoice->invoice_no], (float) $remaining, (float) $outstanding)
                    : min((float) $remaining, (float) $outstanding);

                $amount = Decimal::format((float) $amount, 2);

                if (Decimal::compare($amount, '0', 2) <= 0) {
                    continue;
                }

                // One allocation row per payment+invoice (composite PK). When
                // this payment has already allocated to this invoice, the new
                // FIFO amount EXTENDS the immutable row — never a duplicate.
                $existing = PaymentAllocation::where('payment_id', $locked->payment_id)
                    ->where('invoice_no', $invoice->invoice_no)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->allocated_amount = Decimal::add(
                        (string) $existing->allocated_amount, $amount, 2,
                    );
                    $existing->save();
                } else {
                    PaymentAllocation::create([
                        'payment_id' => $locked->payment_id,
                        'invoice_no' => $invoice->invoice_no,
                        'allocated_amount' => $amount,
                    ]);
                }

                $this->rederiveInvoiceSettlement($invoice);

                $remaining = Decimal::sub($remaining, $amount, 2);
                $created->push(['invoice_no' => $invoice->invoice_no, 'amount' => $amount]);
            }

            $result = ['payment' => $locked->fresh(), 'allocations' => $created];
        });

        return $result;
    }

    // ---- Credits ---------------------------------------------------------------

    public function createManualCredit(string $companyId, string $customerId, string $amount, ?string $remarks = null): CustomerCredit
    {
        if (Decimal::compare($amount, '0', 2) <= 0) {
            abort(422, 'Credit amount must be positive.');
        }

        return CustomerCredit::create([
            'credit_no' => $this->nextCreditNumber($companyId),
            'company_id' => $companyId,
            'customer_id' => $customerId, // debtor identity
            'credit_source' => 'MANUAL_ADJUSTMENT',
            'source_reference' => null,
            'original_amount' => $amount,
            'remaining_amount' => $amount,
            'currency' => 'NGN',
            'credit_status' => CreditStatus::OPEN,
            'remarks' => $remarks,
        ]);
    }

    /** Convert an unallocated CONFIRMED payment remainder into OVERPAYMENT credit. */
    public function convertRemainderToOverpaymentCredit(Payment $payment): CustomerCredit
    {
        return DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->payment_id)->lockForUpdate()->first();

            if ($locked->payment_status !== PaymentRecordStatus::CONFIRMED) {
                abort(422, 'Only CONFIRMED payments can convert a remainder to credit.');
            }

            $remainder = $this->unallocatedAmount($locked);

            if (Decimal::compare($remainder, '0', 2) <= 0) {
                abort(422, 'This payment has no unallocated remainder.');
            }

            $credit = CustomerCredit::create([
                'credit_no' => $this->nextCreditNumber($locked->company_id),
                'company_id' => $locked->company_id,
                'customer_id' => $locked->customer_id,
                'credit_source' => 'OVERPAYMENT',
                'source_reference' => 'Payment '.$locked->payment_id,
                'original_amount' => $remainder,
                'remaining_amount' => $remainder,
                'currency' => $locked->currency,
                'credit_status' => CreditStatus::OPEN,
                'remarks' => 'Converted from unallocated payment remainder',
            ]);

            return $credit;
        });
    }

    public function creditRemaining(CustomerCredit $credit): string
    {
        $credit->refresh();

        return (string) $credit->remaining_amount;
    }

    /** @return array{credit: CustomerCredit, allocations: Collection} */
    public function allocateCredit(CustomerCredit $credit, ?Collection $perInvoice = null): array
    {
        /** @var array{credit: CustomerCredit, allocations: Collection}|null $result */
        $result = null;

        DB::transaction(function () use (&$result, $credit, $perInvoice) {
            $locked = CustomerCredit::whereKey($credit->credit_no)->lockForUpdate()->first();

            if (in_array($locked->credit_status, [CreditStatus::USED, CreditStatus::CANCELLED], true)) {
                abort(422, 'This credit has no remaining amount to allocate.');
            }

            $remaining = (string) $locked->remaining_amount;

            if (Decimal::compare($remaining, '0', 2) <= 0) {
                abort(422, 'This credit is fully used.');
            }

            // Same over-allocation rejection as payments (§19.10).
            if ($perInvoice !== null) {
                $requested = '0.00';

                foreach ($perInvoice as $requestedAmount) {
                    $requested = Decimal::add($requested, (string) $requestedAmount, 2);
                }

                if (Decimal::compare($requested, $remaining, 2) > 0) {
                    abort(422, "Requested allocation {$requested} exceeds the credit's remaining amount {$remaining}.");
                }
            }

            $invoices = Invoice::where('customer_id', $locked->customer_id)
                ->where('company_id', $locked->company_id)
                ->whereIn('payment_status', [InvoicePaymentStatus::UNPAID, InvoicePaymentStatus::PARTIALLY_PAID])
                ->orderBy('invoice_date')
                ->orderBy('invoice_no')
                ->lockForUpdate()
                ->get()
                ->keyBy('invoice_no');

            if ($perInvoice !== null) {
                foreach ($perInvoice as $invoiceNo => $amount) {
                    if (! $invoices->has($invoiceNo)) {
                        abort(422, "Invoice {$invoiceNo} is not an outstanding invoice of this debtor.");
                    }
                }

                $invoices = $invoices->filter(fn ($i, $no) => $perInvoice->has($no));
            }

            $created = collect();

            foreach ($invoices as $invoice) {
                if (Decimal::compare($remaining, '0', 2) <= 0) {
                    break;
                }

                $outstanding = Decimal::sub((string) $invoice->invoice_amount, (string) $invoice->settled_amount, 2);

                if (Decimal::compare($outstanding, '0', 2) <= 0) {
                    continue;
                }

                $amount = $perInvoice !== null
                    ? min((float) $perInvoice[$invoice->invoice_no], (float) $remaining, (float) $outstanding)
                    : min((float) $remaining, (float) $outstanding);

                $amount = Decimal::format((float) $amount, 2);

                if (Decimal::compare($amount, '0', 2) <= 0) {
                    continue;
                }

                CreditAllocation::create([
                    'credit_no' => $locked->credit_no,
                    'invoice_no' => $invoice->invoice_no,
                    'allocated_amount' => $amount,
                ]);

                $remaining = Decimal::sub($remaining, $amount, 2);
                $created->push(['invoice_no' => $invoice->invoice_no, 'amount' => $amount]);
            }

            $locked->remaining_amount = $remaining;
            $locked->credit_status = match (true) {
                Decimal::compare($remaining, '0', 2) === 0 => CreditStatus::USED,
                Decimal::compare($remaining, (string) $locked->original_amount, 2) < 0 => CreditStatus::PARTIALLY_USED,
                default => CreditStatus::OPEN,
            };
            $locked->save();

            foreach ($created as $entry) {
                $this->rederiveInvoiceSettlement($invoices[$entry['invoice_no']]);
            }

            $result = ['credit' => $locked->fresh(), 'allocations' => $created];
        });

        return $result;
    }

    /** Recompute settled_amount / credit_amount / payment_status (in-transaction). */
    private function rederiveInvoiceSettlement(Invoice $invoice): void
    {
        $paid = PaymentAllocation::where('invoice_no', $invoice->invoice_no)->get()
            ->sum(fn (PaymentAllocation $a) => (float) $a->allocated_amount);
        $credited = CreditAllocation::where('invoice_no', $invoice->invoice_no)->get()
            ->sum(fn ($a) => (float) $a->allocated_amount);

        $settled = Decimal::format((float) $paid, 2);
        $creditedAmount = Decimal::format((float) $credited, 2);

        $invoice->settled_amount = $settled;
        $invoice->credit_amount = $creditedAmount;

        $total = Decimal::add($settled, $creditedAmount, 2);
        $amount = (string) $invoice->invoice_amount;

        $invoice->payment_status = match (true) {
            Decimal::compare($total, $amount, 2) >= 0 => InvoicePaymentStatus::PAID,
            Decimal::compare($total, '0', 2) > 0 => InvoicePaymentStatus::PARTIALLY_PAID,
            default => InvoicePaymentStatus::UNPAID,
        };

        $invoice->save();
    }
}
