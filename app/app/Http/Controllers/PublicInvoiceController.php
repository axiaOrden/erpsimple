<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidence;
use App\Services\InvoiceImageService;
use App\Services\InvoicePartyService;
use App\Services\InvoiceService;
use App\Services\PaymentEvidenceService;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PUBLIC read-only customer invoice (Primary/Secondary customers have no
 * application accounts). Access is granted ONLY by an unguessable random
 * token — never by a sequential id — and the page is strictly read-only:
 * no POD, no payment, no edit, no ERP operation is reachable from it.
 */
class PublicInvoiceController extends Controller
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /** Image types a proof of payment may be (kept in step with the uploader). */
    private const EVIDENCE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/avif' => 'avif',
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceImageService $image,
        private readonly QrCodeService $qr,
        private readonly InvoicePartyService $parties,
        private readonly PaymentEvidenceService $evidence,
    ) {}

    public function show(string $token): View
    {
        $invoice = $this->resolve($token);

        return view('invoice.public', [
            'invoice' => $invoice->load(['items.product', 'customer', 'company']),
            'parties' => $this->parties->parties($invoice),
            'payments' => $this->paymentsFor($invoice),
            'publicUrl' => route('invoice.public', $token),
            'qrDataUri' => $this->qr->pngDataUri(route('invoice.public', $token), 6),
        ]);
    }

    /**
     * Read-only proof of payment for ONE payment of THIS invoice.
     *
     * The unguessable invoice token authorizes access only to that invoice, the
     * payments authoritatively allocated to it and their evidence. There is no
     * way to reach another invoice's evidence: the payment must be allocated to
     * the token's own invoice (and belong to the same debtor + company), the
     * evidence must belong to that payment, and the stored type must be an
     * allowed image. Any mismatch is a 404 — never a hint that anything exists.
     * Nothing here can edit, pay or reach authenticated ERP functionality.
     */
    public function evidence(string $token, Payment $payment, PaymentEvidence $evidence): StreamedResponse
    {
        $invoice = $this->resolve($token);

        abort_unless((int) $evidence->payment_id === (int) $payment->payment_id, 404);

        abort_unless(
            PaymentAllocation::where('payment_id', $payment->payment_id)
                ->where('invoice_no', $invoice->invoice_no)
                ->exists(),
            404,
        );

        abort_unless(
            $payment->customer_id === $invoice->customer_id
                && $payment->company_id === $invoice->company_id,
            404,
        );

        // Server-side type validation BEFORE anything is served: only a real
        // image type the uploader accepts is ever returned.
        $allowed = (array) config('erp.payment_evidence.mimes', []);
        $mime = (string) $evidence->mime_type;

        abort_unless(in_array($mime, $allowed, true), 404);
        abort_unless(isset(self::EVIDENCE_EXTENSIONS[$mime]), 404);

        $disk = Storage::disk($this->evidence->disk());

        abort_unless($disk->exists($evidence->stored_path), 404);

        // A NEUTRAL filename: internal storage names and the uploader's
        // original filename are never exposed through the public endpoint.
        return $disk->response($evidence->stored_path, 'payment-evidence.'.self::EVIDENCE_EXTENSIONS[$mime], [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="payment-evidence.'.self::EVIDENCE_EXTENSIONS[$mime].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Payments that belong to this invoice: only payments with an authoritative
     * allocation to it (the payment↔invoice relationship), never "every payment
     * of the debtor". Each one carries its own proof rows.
     *
     * @return Collection<int, array{payment: Payment, allocated: string, evidence: Collection<int, PaymentEvidence>}>
     */
    private function paymentsFor(Invoice $invoice): Collection
    {
        $allocations = PaymentAllocation::where('invoice_no', $invoice->invoice_no)
            ->get()
            ->keyBy('payment_id');

        if ($allocations->isEmpty()) {
            return collect();
        }

        $payments = Payment::whereIn('payment_id', $allocations->keys())
            ->where('customer_id', $invoice->customer_id)
            ->where('company_id', $invoice->company_id)
            ->orderBy('payment_date')
            ->orderBy('payment_id')
            ->get();

        if ($payments->isEmpty()) {
            return collect();
        }

        $evidence = PaymentEvidence::whereIn('payment_id', $payments->pluck('payment_id'))
            ->orderBy('evidence_id')
            ->get()
            ->groupBy('payment_id');

        return $payments->map(fn (Payment $payment) => [
            'payment' => $payment,
            'allocated' => (string) $allocations[$payment->payment_id]->allocated_amount,
            'evidence' => $evidence->get($payment->payment_id, collect()),
        ])->values();
    }

    /** The downloadable/shareable customer invoice image (JPEG). */
    public function image(Request $request, string $token): Response
    {
        $invoice = $this->resolve($token);

        $jpeg = $this->image->jpeg($invoice, route('invoice.public', $token));

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => $disposition.'; filename="'.$invoice->invoice_no.'.jpg"',
            'Content-Length' => (string) strlen($jpeg),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolve by public token only. A malformed or unknown token is a 404 —
     * never a hint about which invoice numbers exist.
     */
    private function resolve(string $token): Invoice
    {
        abort_unless(preg_match(self::TOKEN_PATTERN, $token) === 1, 404);

        return Invoice::where('public_token', $token)->firstOrFail();
    }
}
