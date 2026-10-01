<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate">{{ $invoice->invoice_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    Debtor {{ $invoice->customer_id }} · SO {{ $invoice->sales_order_no }} · {{ $invoice->invoice_date?->format('d M Y') }}
                </p>
            </div>
            <span class="m-chip {{ $invoice->payment_status->value === 'PAID' ? 'm-chip-active' : '' }}">{{ $invoice->payment_status->value }}</span>
        </div>
    </x-slot>

    <div class="space-y-4 max-w-2xl">
        {{-- Seller is the SUPPLYING PRIMARY (the Distributor who made the sale);
             the debtor stays the sold-to Secondary. The company that employs
             the sales employee appears only as "Powered by". --}}
        <div class="m-card p-4 text-sm">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant">Seller</p>
                    <p class="truncate font-medium">{{ $parties['seller']['name'] }}</p>
                    @if ($parties['seller']['lines'] !== [])
                        <p class="text-xs text-on-surface-variant">{{ implode(', ', $parties['seller']['lines']) }}</p>
                    @endif
                    @php
                        $sellerContact = collect([$parties['seller']['phone'], $parties['seller']['email']])->filter()->implode(' · ');
                    @endphp
                    @if ($sellerContact !== '')
                        <p class="text-xs text-on-surface-variant break-words">{{ $sellerContact }}</p>
                    @endif
                    @if ($parties['warehouse'] !== null)
                        <p class="text-xs text-on-surface-variant">Warehouse · {{ $parties['warehouse']['name'] }}</p>
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-on-surface-variant">Bill to (debtor)</p>
                    <p class="truncate font-medium">{{ $parties['debtor']['name'] }}</p>
                    @if ($parties['debtor']['lines'] !== [])
                        <p class="text-xs text-on-surface-variant">{{ implode(', ', $parties['debtor']['lines']) }}</p>
                    @endif
                </div>
            </div>
            <p class="mt-2 border-t border-outline-variant pt-2 text-[11px] text-on-surface-variant">
                Powered by
                {{ collect([$parties['employee']['id'], $parties['employee']['name']])->filter()->implode(' - ') ?: '—' }}
                @if ($parties['company']['name']) · {{ $parties['company']['name'] }} @endif
            </p>
        </div>

        <div class="m-card divide-y divide-outline-variant text-sm">
            @foreach ($invoice->items as $item)
                <div class="p-4 flex items-center justify-between gap-3">
                    <div>
                        <p class="font-medium">
                            {{ $item->product->product_description ?? $item->product_id }}
                            @if ($item->is_free_item)<span class="m-chip m-chip-active ml-1">FREE</span>@endif
                        </p>
                        <p class="text-xs text-on-surface-variant">SO line {{ $item->sales_order_item_no }}</p>
                    </div>
                    <div class="text-right tabular-nums text-sm">
                        <p>{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }} {{ $item->invoice_unit }} × {{ number_format((float) $item->unit_price, 2) }}</p>
                        <p class="font-semibold">{{ number_format((float) $item->subtotal_amount, 2) }}</p>
                    </div>
                </div>
            @endforeach
            <div class="p-4 text-sm tabular-nums">
                <div class="flex justify-between"><span>Gross</span><span>{{ number_format((float) $invoice->gross_amount, 2) }} {{ $invoice->currency }}</span></div>
                @if ((float) $invoice->discount_amount > 0)
                    <div class="flex justify-between mt-1"><span>Discount</span><span>-{{ number_format((float) $invoice->discount_amount, 2) }}</span></div>
                @endif
                {{-- Conditional display: no tax row when the invoice carries no tax. --}}
                @if ((float) $invoice->tax_amount > 0)
                    <div class="flex justify-between mt-1"><span>Tax</span><span>{{ number_format((float) $invoice->tax_amount, 2) }}</span></div>
                @endif
                <div class="flex justify-between mt-1 font-semibold"><span>Invoice amount</span><span>{{ number_format((float) $invoice->invoice_amount, 2) }} {{ $invoice->currency }}</span></div>
                @if ((float) $invoice->settled_amount > 0)
                    <div class="flex justify-between mt-1 text-on-surface-variant"><span>Settled (payments)</span><span>{{ number_format((float) $invoice->settled_amount, 2) }}</span></div>
                @endif
                @if ((float) $invoice->credit_amount > 0)
                    <div class="flex justify-between mt-1 text-on-surface-variant"><span>Credited</span><span>{{ number_format((float) $invoice->credit_amount, 2) }}</span></div>
                @endif
                @if ((float) $invoice->outstandingAmount() > 0)
                    <div class="flex justify-between mt-1 font-semibold"><span>Outstanding</span><span>{{ number_format((float) $invoice->outstandingAmount(), 2) }}</span></div>
                @endif
            </div>
        </div>

        <div class="m-card p-4 text-sm">
            <p class="font-medium mb-2">Debtor exposure ({{ $invoice->customer_id }})</p>
            <div class="flex justify-between"><span>Invoice outstanding</span><span class="tabular-nums">{{ number_format((float) $exposure['outstanding'], 2) }}</span></div>
            <div class="flex justify-between mt-1"><span>Available credit</span><span class="tabular-nums">{{ number_format((float) $exposure['available_credit'], 2) }}</span></div>
            <div class="flex justify-between mt-1 font-semibold"><span>Net exposure</span><span class="tabular-nums">{{ number_format((float) $exposure['net_exposure'], 2) }}</span></div>
        </div>

        @if ($paymentAllocations->isNotEmpty() || $creditAllocations->isNotEmpty())
            <div class="m-card p-4 text-xs text-on-surface-variant">
                <p class="font-medium text-on-surface mb-1">Settlement breakdown</p>
                @foreach ($paymentAllocations as $allocation)
                    <p>payment #{{ $allocation->payment_id }} — {{ number_format((float) $allocation->allocated_amount, 2) }}</p>
                @endforeach
                @foreach ($creditAllocations as $allocation)
                    <p>credit {{ $allocation->credit_no }} — {{ number_format((float) $allocation->allocated_amount, 2) }}</p>
                @endforeach
            </div>
        @endif

        {{-- Customer hand-off: public QR/JPG (read-only, no account) + field settlement --}}
        <div class="m-card p-4">
            <div class="flex items-start gap-4">
                <img src="{{ $qrDataUri }}" alt="Invoice QR code" class="h-28 w-28 shrink-0 rounded-m bg-white p-1">
                <div class="min-w-0 text-xs text-on-surface-variant">
                    <p class="font-medium text-on-surface">Customer invoice link</p>
                    <p class="mt-1">Internal copy of the public link — the customer document carries this as a QR code only, never as printed text.</p>
                    <p class="mt-1 break-all">{{ $publicUrl }}</p>
                    <p class="mt-1">Read-only: the customer can inspect the invoice and download the A4 JPG — never change POD, payment or anything else.</p>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('invoice.public', $invoice->public_token) }}" target="_blank" rel="noopener"
                   class="inline-flex h-10 items-center rounded-full bg-surface-variant px-4 text-xs font-medium text-on-surface-variant">
                    Show QR
                </a>
                <a href="{{ route('invoice.public.image', ['token' => $invoice->public_token, 'download' => 1]) }}"
                   class="inline-flex h-10 items-center rounded-full bg-surface-variant px-4 text-xs font-medium text-on-surface-variant">
                    Download JPG
                </a>
                @if ((float) $invoice->outstandingAmount() > 0)
                    <a href="{{ route('payments.record.create', $invoice) }}"
                       class="inline-flex h-10 items-center rounded-full bg-primary px-4 text-xs font-semibold text-on-primary">
                        Record payment
                    </a>
                @endif
            </div>
        </div>

        <a href="{{ route('finance.invoices.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All invoices
        </a>
    </div>
</x-app-layout>
