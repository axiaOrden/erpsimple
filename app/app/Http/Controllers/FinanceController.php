<?php

namespace App\Http\Controllers;

use App\Models\CreditAllocation;
use App\Models\CustomerCredit;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\FinanceService;
use App\Services\InvoicePartyService;
use App\Services\InvoiceService;
use App\Services\QrCodeService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Phase 8 — Finance screens. Money movement is admin-only; sales employees
 * get read-only views/statements of their assigned sold-to customers.
 * Debtor identity always comes from the financial records (sold-to), never
 * from client input or the location of the cash.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly FinanceService $finance,
        private readonly SyncService $sync,
        private readonly QrCodeService $qr,
        private readonly InvoicePartyService $parties,
    ) {}

    // ---- Invoices ------------------------------------------------------------

    public function invoices(Request $request)
    {
        return view('finance.invoices', [
            'invoices' => $this->scopedInvoices($request->user())
                ->orderByDesc('invoice_date')->orderByDesc('invoice_no')->paginate(20)->withQueryString(),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $this->assertCanSeeDebtor($request->user(), $invoice->customer_id, $invoice->company_id);

        $invoice->load(['items.product', 'items']);

        $payments = PaymentAllocation::where('invoice_no', $invoice->invoice_no)->get();
        $credits = CreditAllocation::where('invoice_no', $invoice->invoice_no)->get();

        // Opening the invoice is the share moment: ensure the unguessable
        // public token exists so the QR/JPG can be handed to the customer.
        $token = $this->invoices->publicToken($invoice);
        $invoice->refresh();
        $publicUrl = route('invoice.public', $token);

        return view('finance.invoice', [
            'invoice' => $invoice,
            'paymentAllocations' => $payments,
            'creditAllocations' => $credits,
            'exposure' => $this->invoices->exposure($invoice->customer_id, $invoice->company_id),
            'parties' => $this->parties->parties($invoice),
            'publicUrl' => $publicUrl,
            'qrDataUri' => $this->qr->pngDataUri($publicUrl, 6),
        ]);
    }

    // ---- Payments ------------------------------------------------------------

    public function payments(Request $request)
    {
        return view('finance.payments', [
            'payments' => $this->scopedPayments($request->user())
                ->orderByDesc('payment_id')->paginate(20)->withQueryString(),
        ]);
    }

    public function payment(Request $request, Payment $payment)
    {
        $this->assertCanSeeDebtor($request->user(), $payment->customer_id, $payment->company_id);

        $allocations = PaymentAllocation::where('payment_id', $payment->payment_id)->get();

        $outstanding = $this->scopedInvoices($request->user())
            ->whereIn('payment_status', ['UNPAID', 'PARTIALLY_PAID'])
            ->where('customer_id', $payment->customer_id)
            ->orderBy('invoice_date')->orderBy('invoice_no')->get();

        return view('finance.payment', [
            'payment' => $payment,
            'allocations' => $allocations,
            'unallocated' => $this->finance->unallocatedAmount($payment),
            'outstandingInvoices' => $outstanding,
        ]);
    }

    /** Payment creation form (debtor selector scoped to the viewer). */
    public function createPayment(Request $request)
    {
        $this->assertFinanceAdmin($request->user());

        return view('finance.payment-create', ['debtors' => $this->debtors($request->user())]);
    }

    /** Credit creation form. */
    public function createCredit(Request $request)
    {
        $this->assertFinanceAdmin($request->user());

        return view('finance.credit-create', ['debtors' => $this->debtors($request->user())]);
    }

    /** Sold-to customers (potential debtors) visible to this user. */
    private function debtors($user): Collection
    {
        $debtors = CustomerMaster::query()->where('active', true)->orderBy('business_name');

        if ($user->isSalesEmployee()) {
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
            $debtors->whereIn('customer_id', $assigned);
        }

        return $debtors->get();
    }

    /** Record a payment (PENDING) — idempotent via the sync mechanism. */
    public function storePayment(Request $request)
    {
        $user = $request->user();
        $this->assertFinanceAdmin($user);

        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'payment_method' => ['required', 'in:CASH,TRANSFER,POS,OTHER'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->sync->process('payment_create', $request, function () use ($user, $validated) {
            $payment = $this->finance->createPayment(
                $user->currentCompanyId(),
                $validated['customer_id'], // debtor identity, taken as input but scoped below
                $validated['payment_method'],
                (string) $validated['amount'],
                $validated['payment_reference'] ?? null,
            );

            return ['payment_id' => $payment->payment_id, 'amount' => (string) $payment->amount];
        });

        $message = $result['replayed']
            ? 'Payment (idempotent replay — not recorded twice).'
            : 'Payment of '.$result['payload']['amount'].' recorded (PENDING). Confirm it, then allocate.';

        return redirect()->route('finance.payments.show', $result['payload']['payment_id'])->with('status', $message);
    }

    public function confirmPayment(Request $request, Payment $payment)
    {
        $this->assertFinanceAdmin($request->user());

        $this->finance->confirmPayment($payment);

        return back()->with('status', 'Payment confirmed — it can now be allocated.');
    }

    public function cancelPayment(Request $request, Payment $payment)
    {
        $this->assertFinanceAdmin($request->user());

        $this->finance->cancelPayment($payment);

        return back()->with('status', 'Payment cancelled.');
    }

    /** Allocate a CONFIRMED payment: auto-FIFO or explicit per-invoice amounts. */
    public function allocatePayment(Request $request, Payment $payment)
    {
        $this->assertFinanceAdmin($request->user());

        $validated = $request->validate([
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $perInvoice = collect($validated['amounts'] ?? [])
            ->filter(fn ($a) => (float) $a > 0);

        $result = $this->sync->process('payment_allocate', $request, function () use ($payment, $perInvoice) {
            $allocated = $this->finance->allocatePayment($payment, $perInvoice->isNotEmpty() ? $perInvoice : null);

            return ['total' => (string) $allocated['allocations']->sum(fn ($a) => (float) $a['amount'])];
        });

        $message = $result['replayed']
            ? 'Allocation (idempotent replay — nothing applied twice).'
            : 'Allocated '.$result['payload']['total'].' to invoices (FIFO).';

        return redirect()->route('finance.payments.show', $payment)->with('status', $message);
    }

    /** Convert an unallocated CONFIRMED remainder into OVERPAYMENT credit. */
    public function convertRemainder(Request $request, Payment $payment)
    {
        $this->assertFinanceAdmin($request->user());

        $credit = $this->finance->convertRemainderToOverpaymentCredit($payment);

        return redirect()->route('finance.credits.show', $credit)
            ->with('status', 'Remainder converted to OVERPAYMENT credit '.$credit->credit_no.'.');
    }

    // ---- Credits ------------------------------------------------------------

    public function credits(Request $request)
    {
        return view('finance.credits', [
            'credits' => $this->scopedCredits($request->user())
                ->orderByDesc('created_at')->paginate(20)->withQueryString(),
        ]);
    }

    public function credit(Request $request, CustomerCredit $credit)
    {
        $this->assertCanSeeDebtor($request->user(), $credit->customer_id, $credit->company_id);

        $allocations = CreditAllocation::where('credit_no', $credit->credit_no)->get();

        $outstanding = $this->scopedInvoices($request->user())
            ->whereIn('payment_status', ['UNPAID', 'PARTIALLY_PAID'])
            ->where('customer_id', $credit->customer_id)
            ->orderBy('invoice_date')->orderBy('invoice_no')->get();

        return view('finance.credit', [
            'credit' => $credit,
            'allocations' => $allocations,
            'outstandingInvoices' => $outstanding,
        ]);
    }

    public function storeCredit(Request $request)
    {
        $this->assertFinanceAdmin($request->user());

        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->sync->process('credit_create', $request, function () use ($request, $validated) {
            $credit = $this->finance->createManualCredit(
                $request->user()->currentCompanyId(),
                $validated['customer_id'],
                (string) $validated['amount'],
                $validated['remarks'] ?? null,
            );

            return ['credit_no' => $credit->credit_no];
        });

        $message = $result['replayed']
            ? 'Credit (idempotent replay — not created twice).'
            : 'Credit '.$result['payload']['credit_no'].' created (MANUAL_ADJUSTMENT).';

        return redirect()->route('finance.credits.show', $result['payload']['credit_no'])->with('status', $message);
    }

    public function allocateCredit(Request $request, CustomerCredit $credit)
    {
        $this->assertFinanceAdmin($request->user());

        $validated = $request->validate([
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $perInvoice = collect($validated['amounts'] ?? [])->filter(fn ($a) => (float) $a > 0);

        $result = $this->sync->process('credit_allocate', $request, function () use ($credit, $perInvoice) {
            $allocated = $this->finance->allocateCredit($credit, $perInvoice->isNotEmpty() ? $perInvoice : null);

            return ['total' => (string) $allocated['allocations']->sum(fn ($a) => (float) $a['amount'])];
        });

        $message = $result['replayed']
            ? 'Credit allocation (idempotent replay — nothing applied twice).'
            : 'Applied '.$result['payload']['total'].' of credit to invoices (FIFO).';

        return redirect()->route('finance.credits.show', $credit)->with('status', $message);
    }

    // ---- Statement ------------------------------------------------------------

    public function statement(Request $request, CustomerMaster $customer)
    {
        // customer_master is global — pass null so the company check inside
        // assertCanSeeDebtor is skipped; document queries below are still
        // company-scoped for non-superadmins (§16).
        $this->assertCanSeeDebtor($request->user(), $customer->customer_id, null);

        // Company scope (§16): admins see their own company's documents;
        // superadmins see all companies' records of this debtor.
        $companyId = $request->user()->isSuperadmin() ? null : $request->user()->currentCompanyId();

        return view('finance.statement', [
            'customer' => $customer,
            'invoices' => Invoice::where('customer_id', $customer->customer_id)
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->orderByDesc('invoice_date')->get(),
            'payments' => Payment::where('customer_id', $customer->customer_id)
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->orderByDesc('payment_id')->limit(50)->get(),
            'credits' => CustomerCredit::where('customer_id', $customer->customer_id)
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->orderByDesc('created_at')->limit(50)->get(),
            'exposure' => $this->invoices->exposure($customer->customer_id, $companyId),
        ]);
    }

    // ---- Scoping / authorization ------------------------------------------------

    private function scopedInvoices($user)
    {
        return Invoice::query()
            ->when($user->isSalesEmployee(), function ($q) use ($user) {
                $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
                $q->whereIn('customer_id', $assigned);
            })
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id));
    }

    private function scopedPayments($user)
    {
        return Payment::query()
            ->when($user->isSalesEmployee(), function ($q) use ($user) {
                $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
                $q->whereIn('customer_id', $assigned);
            })
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id));
    }

    private function scopedCredits($user)
    {
        return CustomerCredit::query()
            ->when($user->isSalesEmployee(), function ($q) use ($user) {
                $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
                $q->whereIn('customer_id', $assigned);
            })
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id));
    }

    private function assertCanSeeDebtor($user, string $customerId, ?string $companyId): void
    {
        if ($user->isSalesEmployee()) {
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)
                ->where('customer_id', $customerId)->exists();

            abort_if(! $assigned, 403, 'This debtor is outside your assigned customers.');

            return;
        }

        if ($companyId !== null && ! $user->isSuperadmin()) {
            abort_if($companyId !== $user->company_id, 403, 'This record belongs to another company.');
        }
    }

    private function assertFinanceAdmin($user): void
    {
        abort_if($user->isSalesEmployee(), 403, 'Only administrators record money movement.');
    }
}
