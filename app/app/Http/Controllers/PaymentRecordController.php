<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\CustomerEmployee;
use App\Models\EmployeeMaster;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvidence;
use App\Services\Decimal;
use App\Services\FinanceService;
use App\Services\InvoiceService;
use App\Services\PaymentEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Field settlement recording.
 *
 * The customer settles AT THE PRIMARY — the sales employee verifies it and
 * records it. Money movement goes through FinanceService exclusively
 * (create → confirm → allocate); this controller never writes invoice state
 * and never touches payment records directly.
 *
 * Methods are the explicit Primary-routed ones: a bare CASH is NOT offered,
 * because it would imply the employee collected the money personally.
 */
class PaymentRecordController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly InvoiceService $invoices,
        private readonly PaymentEvidenceService $evidence,
    ) {}

    /** Record-payment screen for one invoice (contextual entry point). */
    public function create(Request $request, Invoice $invoice): View
    {
        $employee = $this->authorizeInvoice($request, $invoice);

        return view('payments.record', [
            'invoice' => $invoice->load(['items.product', 'customer', 'company']),
            'employee' => $employee,
            'outstanding' => $invoice->outstandingAmount(),
            'methods' => PaymentMethod::fieldOptions(),
            'publicToken' => $invoice->public_token,
        ]);
    }

    /** Record + confirm + allocate in one verified field action. */
    public function store(Request $request, Invoice $invoice)
    {
        $employee = $this->authorizeInvoice($request, $invoice);

        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::fieldOptions()))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'proof' => ['required', 'file'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'captured_at' => ['nullable', 'date'],
            'watermark_text' => ['nullable', 'string', 'max:255'],
        ]);

        // 0. Validate the proof FIRST: an unacceptable upload must not leave a
        //    half-recorded settlement behind.
        $this->evidence->assertAcceptable($request->file('proof'));

        // 1. Payment record (PENDING) — FinanceService owns the money rules.
        $payment = $this->finance->createPayment(
            $invoice->company_id,
            $invoice->customer_id, // debtor identity from the invoice, never the client
            $validated['payment_method'],
            (string) $validated['amount'],
            $validated['payment_reference'] ?? null,
        );

        // 2-4. Money movement + evidence: one atomic unit, so a failure at any
        //      step never leaves a payment without money or money without proof.
        DB::transaction(function () use ($invoice, $payment, $request, $employee, $validated) {
            // Confirmation — the employee IS the verifier (the customer settled
            // at the Primary); this immediately updates finance state.
            $this->finance->confirmPayment($payment);

            // Allocation to the invoice being settled (a remainder is left
            // unallocated deliberately — never invented across invoices).
            $outstanding = (string) $invoice->fresh()->outstandingAmount();

            if (Decimal::compare($outstanding, '0', 2) > 0) {
                $applied = Decimal::compare((string) $validated['amount'], $outstanding, 2) > 0
                    ? $outstanding
                    : (string) $validated['amount'];

                $this->finance->allocatePayment($payment, collect([$invoice->invoice_no => $applied]));
            }

            // Proof of payment: server-validated, attached to the payment
            // (audit artefact, never input to the money math).
            $this->evidence->store($payment, $request->file('proof'), $employee, [
                'gps_latitude' => $validated['gps_latitude'] ?? null,
                'gps_longitude' => $validated['gps_longitude'] ?? null,
                'gps_accuracy' => $validated['gps_accuracy'] ?? null,
                'captured_at' => $validated['captured_at'] ?? null,
                'watermark_text' => $validated['watermark_text'] ?? null,
            ]);
        });

        $settled = $invoice->fresh();

        $message = 'Payment recorded and allocated: '.$settled->payment_status->value.'.';

        if (Decimal::compare($settled->outstandingAmount(), '0', 2) > 0) {
            $message .= ' Outstanding '.$settled->outstandingAmount().' '.$settled->currency.'.';
        }

        return redirect()->route('finance.invoices.show', $invoice)->with('status', $message);
    }

    /** View the stored proof (assigned employees and scoped administrators). */
    public function evidence(Request $request, Payment $payment, PaymentEvidence $evidence): StreamedResponse
    {
        abort_unless($evidence->payment_id === $payment->payment_id, 404);

        $user = $request->user();

        if ($user->isSalesEmployee()) {
            $employee = $user->employee;
            abort_if($employee === null, 403);

            $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
                ->where('customer_id', $payment->customer_id)
                ->exists();

            abort_unless($assigned && $payment->company_id === $employee->company_id, 403, 'This record is outside your assigned customers.');
        } elseif (! $user->isSuperadmin()) {
            abort_unless($payment->company_id === $user->company_id, 403, 'This record belongs to another company.');
        }

        $disk = Storage::disk($this->evidence->disk());

        abort_unless($disk->exists($evidence->stored_path), 404);

        return $disk->response($evidence->stored_path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A field employee may record settlement ONLY for an invoice of a customer
     * they are assigned to, inside their own company.
     */
    private function authorizeInvoice(Request $request, Invoice $invoice): EmployeeMaster
    {
        $employee = $request->user()->employee;

        abort_if($employee === null, 403, 'Only sales employees record field payments.');

        abort_unless($invoice->company_id === $employee->company_id, 403, 'This invoice belongs to another company.');

        $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->where('customer_id', $invoice->customer_id)
            ->exists();

        abort_unless($assigned, 403, 'This invoice is outside your assigned customers.');

        return $employee;
    }
}
