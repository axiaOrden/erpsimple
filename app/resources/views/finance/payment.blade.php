<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>Payment #{{ $payment->payment_id }}</h1>
                <p class="text-sm text-on-surface-variant">Debtor {{ $payment->customer_id }} · {{ $payment->payment_date?->format('d M Y') }}</p>
            </div>
            <span class="m-chip {{ $payment->payment_status->value === 'CONFIRMED' ? 'm-chip-active' : ($payment->payment_status->value === 'CANCELLED' ? 'm-chip-error' : '') }}">
                {{ $payment->payment_status->value }}
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
            <div class="flex justify-between font-semibold"><span>Amount</span><span>{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</span></div>
            <div class="flex justify-between mt-1"><span>Method</span><span>{{ $payment->payment_method->value }}</span></div>
            @if ($payment->payment_reference)
                <div class="flex justify-between mt-1"><span>Reference</span><span>{{ $payment->payment_reference }}</span></div>
            @endif
            <div class="flex justify-between mt-1"><span>Allocated</span><span>{{ number_format((float) $payment->amount - (float) $unallocated, 2) }}</span></div>
            <div class="flex justify-between mt-1 font-semibold"><span>Unallocated</span><span>{{ number_format((float) $unallocated, 2) }}</span></div>
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

        @if (! auth()->user()->isSalesEmployee())
            @if ($payment->payment_status->value === 'PENDING')
                <div class="grid grid-cols-2 gap-3">
                    <form method="POST" action="{{ route('finance.payments.confirm', $payment) }}">
                        @csrf
                        <button class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">Confirm payment</button>
                    </form>
                    <form method="POST" action="{{ route('finance.payments.cancel', $payment) }}"
                          onsubmit="return confirm('Cancel this payment? No allocations exist yet, so this is safe.')">
                        @csrf
                        <button class="w-full h-12 rounded-full bg-error-container text-on-error-container font-semibold">Cancel payment</button>
                    </form>
                </div>
            @elseif ($payment->payment_status->value === 'CONFIRMED')
                @if ((float) $unallocated > 0)
                    <form method="POST" action="{{ route('finance.payments.allocate', $payment) }}" class="m-card p-4 space-y-3">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">
                        <p class="font-medium text-sm">Allocate {{ number_format((float) $unallocated, 2) }} {{ $payment->currency }}</p>
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
                            <p class="text-xs text-on-surface-variant">No outstanding invoices for this debtor — the remainder can be converted to overpayment credit.</p>
                        @endif
                        @if ($outstandingInvoices->isNotEmpty())
                            <button class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">Allocate</button>
                        @endif
                    </form>

                    <form method="POST" action="{{ route('finance.payments.convert-remainder', $payment) }}"
                          onsubmit="return confirm('Convert the unallocated {{ number_format((float) $unallocated, 2) }} into an OVERPAYMENT credit?')">
                        @csrf
                        <button class="w-full h-12 rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                            Convert remainder to credit
                        </button>
                    </form>
                @else
                    <div class="m-card p-4 text-sm text-on-surface-variant">Fully allocated — nothing left to apply.</div>
                @endif
            @endif
        @endif

        <a href="{{ route('finance.payments.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All payments
        </a>
    </div>
</x-app-layout>
