<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $credit->credit_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $credit->credit_source->value }} · debtor {{ $credit->customer_id }} · {{ $credit->created_at?->format('d M Y') }}
                </p>
            </div>
            <span class="m-chip {{ $credit->credit_status->value === 'OPEN' ? 'm-chip-active' : ($credit->credit_status->value === 'CANCELLED' ? 'm-chip-error' : '') }}">
                {{ $credit->credit_status->value }}
            </span>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="space-y-4 max-w-2xl">
        <div class="m-card p-4 text-sm tabular-nums">
            <div class="flex justify-between"><span>Original</span><span>{{ number_format((float) $credit->original_amount, 2) }} {{ $credit->currency }}</span></div>
            <div class="flex justify-between mt-1 font-semibold"><span>Remaining</span><span>{{ number_format((float) $credit->remaining_amount, 2) }}</span></div>
            @if ($credit->source_reference)
                <div class="flex justify-between mt-1 text-on-surface-variant"><span>Source</span><span>{{ $credit->source_reference }}</span></div>
            @endif
            @if ($credit->remarks)
                <p class="mt-2 text-on-surface-variant">{{ $credit->remarks }}</p>
            @endif
        </div>

        @if ($allocations->isNotEmpty())
            <div class="m-card divide-y divide-outline-variant text-sm">
                <p class="p-4 pb-2 font-medium">Allocations (immutable history)</p>
                @foreach ($allocations as $allocation)
                    <a href="{{ route('finance.invoices.show', $allocation->invoice_no) }}" class="p-4 flex items-center justify-between">
                        <span>{{ $allocation->invoice_no }}</span>
                        <span class="tabular-nums">{{ number_format((float) $allocation->allocated_amount, 2) }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        @if (! auth()->user()->isSalesEmployee() && ! in_array($credit->credit_status->value, ['USED', 'CANCELLED']) && (float) $credit->remaining_amount > 0)
            <form method="POST" action="{{ route('finance.credits.allocate', $credit) }}" class="m-card p-4 space-y-3">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">
                <p class="font-medium text-sm">Apply {{ number_format((float) $credit->remaining_amount, 2) }} {{ $credit->currency }}</p>
                <p class="text-xs text-on-surface-variant">Leave all fields empty for automatic FIFO (oldest invoice first), or enter explicit amounts per invoice.</p>
                @foreach ($outstandingInvoices as $invoice)
                    <label class="flex items-center justify-between gap-3 text-sm">
                        <span class="min-w-0 truncate">
                            {{ $invoice->invoice_no }}
                            <span class="text-xs text-on-surface-variant">
                                · outstanding {{ number_format((float) $invoice->invoice_amount - (float) $invoice->settled_amount - (float) $invoice->credit_amount, 2) }}
                            </span>
                        </span>
                        <input type="number" name="amounts[{{ $invoice->invoice_no }}]" step="0.01" min="0"
                               placeholder="FIFO"
                               class="w-28 rounded-m border-outline-variant bg-surface text-sm tabular-nums">
                    </label>
                @endforeach
                @if ($outstandingInvoices->isEmpty())
                    <p class="text-xs text-on-surface-variant">No outstanding invoices for this debtor right now.</p>
                @endif
                @if ($outstandingInvoices->isNotEmpty())
                    <button class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">Apply credit</button>
                @endif
            </form>
        @endif

        <a href="{{ route('finance.credits.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All credits
        </a>
    </div>
</x-app-layout>
