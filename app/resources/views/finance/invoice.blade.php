<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $invoice->invoice_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    Debtor {{ $invoice->customer_id }} · SO {{ $invoice->sales_order_no }} · {{ $invoice->invoice_date?->format('d M Y') }}
                </p>
            </div>
            <span class="m-chip {{ $invoice->payment_status->value === 'PAID' ? 'm-chip-active' : '' }}">{{ $invoice->payment_status->value }}</span>
        </div>
    </x-slot>

    <div class="space-y-4 max-w-2xl">
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
                <div class="flex justify-between mt-1"><span>Discount</span><span>{{ number_format((float) $invoice->discount_amount, 2) }}</span></div>
                <div class="flex justify-between mt-1"><span>Tax</span><span>{{ number_format((float) $invoice->tax_amount, 2) }}</span></div>
                <div class="flex justify-between mt-1 font-semibold"><span>Invoice amount</span><span>{{ number_format((float) $invoice->invoice_amount, 2) }} {{ $invoice->currency }}</span></div>
                <div class="flex justify-between mt-1 text-on-surface-variant"><span>Settled (payments)</span><span>{{ number_format((float) $invoice->settled_amount, 2) }}</span></div>
                <div class="flex justify-between mt-1 text-on-surface-variant"><span>Credited</span><span>{{ number_format((float) $invoice->credit_amount, 2) }}</span></div>
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

        <a href="{{ route('finance.invoices.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All invoices
        </a>
    </div>
</x-app-layout>
