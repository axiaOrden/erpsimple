<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- The seller (supplying Primary) owns this document, not the company. --}}
    <title>{{ $invoice->invoice_no }} · {{ $parties['seller']['name'] }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface text-on-surface antialiased">
<main class="mx-auto w-full max-w-md space-y-3 p-4 pb-10">

    {{--
        SELLER = the supplying Primary (the Distributor who made the sale).
        The company behind the sales employee is not the seller: it appears in
        the "Powered by" footer only. The debtor stays the sold-to Secondary.
    --}}
    <header class="m-card p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate font-semibold">{{ $parties['seller']['name'] }}</p>
                <p class="text-xs text-on-surface-variant">Seller · supplies this invoice</p>
            </div>
            <span class="m-chip shrink-0 {{ $invoice->payment_status->value === 'PAID' ? 'm-chip-active' : ($invoice->payment_status->value === 'UNPAID' ? 'm-chip-error' : '') }}">
                {{ str_replace('_', ' ', $invoice->payment_status->value) }}
            </span>
        </div>

        @if ($parties['seller']['lines'] !== [] || $parties['seller']['phone'] || $parties['seller']['email'])
            <p class="mt-1 text-xs text-on-surface-variant">
                {{ implode(', ', $parties['seller']['lines']) }}@if ($parties['seller']['lines'] !== [] && ($parties['seller']['phone'] || $parties['seller']['email'])) · @endif
                {{ implode(' · ', array_filter([$parties['seller']['phone'], $parties['seller']['email']])) }}
            </p>
        @endif

        @if ($parties['warehouse'] !== null)
            <p class="mt-1 text-xs text-on-surface-variant">
                Warehouse · {{ $parties['warehouse']['name'] }}@if ($parties['warehouse']['lines'] !== []) — {{ implode(', ', $parties['warehouse']['lines']) }}@endif
            </p>
        @endif

        <dl class="mt-3 space-y-1 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-on-surface-variant">Invoice number</dt>
                <dd class="font-medium">{{ $invoice->invoice_no }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-on-surface-variant">Invoice date</dt>
                <dd class="font-medium">{{ $invoice->invoice_date?->translatedFormat('j F Y') }}</dd>
            </div>
            @if ($invoice->payment_term === \App\Enums\PaymentTerm::PAY_LATER && $invoice->due_date)
                <div class="flex justify-between gap-3">
                    <dt class="text-on-surface-variant">Due date</dt>
                    <dd class="font-medium">{{ $invoice->due_date->translatedFormat('j F Y') }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-3">
                <dt class="text-on-surface-variant">Sales order</dt>
                <dd class="font-medium">{{ $invoice->sales_order_no }}</dd>
            </div>
        </dl>
    </header>

    <section class="m-card p-4">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-on-surface-variant">Bill to (debtor)</h2>
        <p class="mt-1 font-semibold">{{ $parties['debtor']['name'] }}</p>
        @if ($parties['debtor']['lines'] !== [])
            <p class="text-xs text-on-surface-variant">{{ implode(', ', $parties['debtor']['lines']) }}</p>
        @endif
        @if ($parties['debtor']['phone'])
            <p class="text-xs text-on-surface-variant">{{ $parties['debtor']['phone'] }}</p>
        @endif
    </section>

    <section class="m-card p-4">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-on-surface-variant">Items</h2>
        <div class="mt-2 divide-y divide-outline-variant">
            @foreach ($invoice->items->sortBy('item_no') as $item)
                <div class="flex items-start justify-between gap-3 py-2 text-sm">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ $item->product?->product_description ?? $item->product_id }}</p>
                        <p class="text-xs text-on-surface-variant">
                            {{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }} {{ $item->invoice_unit }}
                            × {{ number_format((float) $item->unit_price, 2) }}
                            @if ($item->is_free_item) · free item @endif
                        </p>
                    </div>
                    <span class="shrink-0 tabular-nums">
                        {{ $item->is_free_item ? 'FREE' : number_format((float) $item->subtotal_amount, 2) }}
                    </span>
                </div>
            @endforeach
        </div>

        @php
            $discount = (float) $invoice->discount_amount;
            $tax = (float) $invoice->tax_amount;
            $settled = (float) $invoice->settled_amount + (float) $invoice->credit_amount;
            $outstanding = (float) $invoice->outstandingAmount();
        @endphp

        <dl class="mt-3 space-y-1 border-t border-outline-variant pt-3 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-on-surface-variant">Subtotal</dt>
                <dd class="tabular-nums">{{ $invoice->currency }} {{ number_format((float) $invoice->gross_amount, 2) }}</dd>
            </div>

            {{-- Conditional display: a component with no value is not rendered. --}}
            @if ($discount > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-on-surface-variant">Discount</dt>
                    <dd class="tabular-nums">-{{ $invoice->currency }} {{ number_format($discount, 2) }}</dd>
                </div>
            @endif

            @if ($tax > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-on-surface-variant">Tax</dt>
                    <dd class="tabular-nums">{{ $invoice->currency }} {{ number_format($tax, 2) }}</dd>
                </div>
            @endif

            <div class="flex justify-between gap-3 border-t border-outline-variant pt-2 text-base font-semibold">
                <dt>Total</dt>
                <dd class="tabular-nums">{{ $invoice->currency }} {{ number_format((float) $invoice->invoice_amount, 2) }}</dd>
            </div>

            @if ($settled > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-on-surface-variant">Settled</dt>
                    <dd class="tabular-nums">{{ $invoice->currency }} {{ number_format($settled, 2) }}</dd>
                </div>
            @endif

            @if ($outstanding > 0)
                <div class="flex justify-between gap-3 font-semibold">
                    <dt>Outstanding</dt>
                    <dd class="tabular-nums">{{ $invoice->currency }} {{ number_format($outstanding, 2) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{--
        Payment evidence: the customer/Primary has no application account, so
        the read-only public page itself must show what was paid and the proof
        behind it. Only THIS invoice's own allocated payments are shown, and the
        receipt links are scoped to the invoice's unguessable token.
    --}}
    @if ($payments->isNotEmpty())
        <section class="m-card p-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-on-surface-variant">Payment</h2>

            <div class="mt-2 space-y-3">
                @foreach ($payments as $entry)
                    @php $payment = $entry['payment']; @endphp
                    <div class="rounded-m border border-outline-variant p-3">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-medium">{{ str_replace('_', ' ', $payment->payment_method->value) }}</p>
                            <span class="m-chip shrink-0 {{ $payment->payment_status->value === 'CONFIRMED' ? 'm-chip-active' : '' }}">
                                {{ $payment->payment_status->value }}
                            </span>
                        </div>

                        <dl class="mt-2 space-y-1 text-xs">
                            <div class="flex justify-between gap-3">
                                <dt class="text-on-surface-variant">Amount</dt>
                                <dd class="font-medium tabular-nums">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-on-surface-variant">Applied to this invoice</dt>
                                <dd class="font-medium tabular-nums">{{ $payment->currency }} {{ number_format((float) $entry['allocated'], 2) }}</dd>
                            </div>
                            @if ($payment->payment_reference)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-on-surface-variant">Reference</dt>
                                    <dd class="font-medium break-all">{{ $payment->payment_reference }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between gap-3">
                                <dt class="text-on-surface-variant">Confirmed at</dt>
                                <dd class="font-medium">{{ $payment->payment_date?->translatedFormat('j M Y H:i') }}</dd>
                            </div>
                        </dl>

                        @if ($entry['evidence']->isNotEmpty())
                            <p class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant">Proof of Payment</p>
                            <div class="mt-1 flex flex-col gap-2">
                                @foreach ($entry['evidence'] as $evidence)
                                    <a href="{{ route('invoice.public.evidence', ['token' => $invoice->public_token, 'payment' => $payment->payment_id, 'evidence' => $evidence->evidence_id]) }}"
                                       target="_blank" rel="noopener noreferrer"
                                       class="block overflow-hidden rounded-m border border-outline-variant bg-surface-variant">
                                        <img src="{{ route('invoice.public.evidence', ['token' => $invoice->public_token, 'payment' => $payment->payment_id, 'evidence' => $evidence->evidence_id]) }}"
                                             alt="Proof of payment{{ $entry['evidence']->count() > 1 ? ' '.$loop->iteration : '' }}"
                                             class="max-h-80 w-full object-contain" loading="lazy">
                                        <span class="block px-3 py-2 text-center text-xs font-medium text-on-surface-variant">
                                            Open proof of payment{{ $entry['evidence']->count() > 1 ? ' '.$loop->iteration : '' }}
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="mt-2 text-[11px] text-on-surface-variant">
                Read-only evidence for this invoice only. Receipts are supplied by the sales employee who
                verified the settlement at the Primary and can never be edited from this page.
            </p>
        </section>
    @endif

    <section class="m-card p-4 text-center">
        <img src="{{ $qrDataUri }}" alt="QR code for this invoice" class="mx-auto h-40 w-40">

        <div class="mt-3 flex flex-col gap-2">
            <a href="{{ route('invoice.public.image', ['token' => $invoice->public_token, 'download' => 1]) }}"
               class="inline-flex h-12 items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary">
                Download invoice
            </a>
            <a href="{{ route('invoice.public.image', $invoice->public_token) }}" target="_blank" rel="noopener"
               class="inline-flex h-11 items-center justify-center rounded-full bg-surface-variant text-sm font-medium text-on-surface-variant">
                Open image (share to WhatsApp)
            </a>
        </div>

        <p class="mt-3 text-[11px] text-on-surface-variant">
            Read-only public link — no account required. Payments are settled at the Primary office;
            this page never changes the invoice.
        </p>

        {{-- Powered by the sales employee + their company; the raw public URL is
             deliberately not printed — the QR code is the only public handle. --}}
        <div class="mt-3 border-t border-outline-variant pt-3 text-left">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant">Powered by</p>
            @php
                $poweredBy = collect([$parties['employee']['id'], $parties['employee']['name']])->filter()->implode(' - ');
            @endphp
            @if ($poweredBy !== '')
                <p class="text-sm font-medium">{{ $poweredBy }}</p>
            @endif
            @if ($parties['company']['name'])
                <p class="text-xs text-on-surface-variant">{{ $parties['company']['name'] }}</p>
            @endif
            <p class="mt-1 text-[11px] text-on-surface-variant">Scan the QR code above to reopen this invoice anytime.</p>
        </div>
    </section>
</main>
</body>
</html>
