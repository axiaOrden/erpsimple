<x-app-layout>
    <x-slot name="header">
        <div>
            <h1>{{ $outstandingOnly ? 'Pending settlements' : 'Invoices' }}</h1>
            <p class="text-sm text-on-surface-variant">{{ $outstandingOnly ? 'Unpaid invoices for your assigned customers' : 'Generated from POD-confirmed quantities — one final invoice per order' }}</p>
        </div>
    </x-slot>

    <div class="space-y-3">
        @forelse ($invoices as $invoice)
            <a href="{{ route('finance.invoices.show', $invoice) }}"
               class="m-card p-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium">{{ $invoice->invoice_no }}</p>
                    <p class="text-xs text-on-surface-variant truncate">
                        {{ $invoice->customer_id }} · SO {{ $invoice->sales_order_no }}
                        · {{ $invoice->invoice_date?->format('d M Y') }}
                        @if ($invoice->payment_term === 'IMMEDIATE') · IMMEDIATE @else · due {{ $invoice->due_date?->format('d M Y') }} @endif
                    </p>
                    <p class="text-xs tabular-nums text-on-surface-variant">
                        {{ number_format((float) $invoice->invoice_amount, 2) }}
                        @if ((float) $invoice->settled_amount > 0 || (float) $invoice->credit_amount > 0)
                            · settled {{ number_format((float) $invoice->settled_amount + (float) $invoice->credit_amount, 2) }}
                        @endif
                    </p>
                </div>
                <span class="m-chip {{ $invoice->payment_status->value === 'PAID' ? 'm-chip-active' : ($invoice->payment_status->value === 'PARTIALLY_PAID' ? 'm-chip' : 'm-chip-error') }}">
                    {{ $invoice->payment_status->value }}
                </span>
            </a>
        @empty
            <div class="m-card p-6 text-center text-sm text-on-surface-variant">
                {{ $outstandingOnly ? 'No pending settlements.' : "No invoices yet. An invoice is generated automatically when an order's deliveries are fully POD-confirmed." }}
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
</x-app-layout>
