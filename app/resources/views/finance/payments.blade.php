<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>Payments</h1>
                <p class="text-sm text-on-surface-variant">Recorded against the sold-to debtor</p>
            </div>
            <a href="{{ route('finance.payments.create') }}" class="m-chip m-chip-active">Record payment</a>
        </div>
    </x-slot>

    <div class="space-y-3">
        @forelse ($payments as $payment)
            <a href="{{ route('finance.payments.show', $payment) }}"
               class="m-card p-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium tabular-nums">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</p>
                    <p class="text-xs text-on-surface-variant truncate">
                        {{ $payment->customer_id }} · {{ $payment->payment_method->value }}
                        · {{ $payment->payment_date?->format('d M Y') }}
                        @if ($payment->payment_reference) · {{ $payment->payment_reference }} @endif
                    </p>
                </div>
                <span class="m-chip {{ $payment->payment_status->value === 'CONFIRMED' ? 'm-chip-active' : ($payment->payment_status->value === 'CANCELLED' ? 'm-chip-error' : '') }}">
                    {{ $payment->payment_status->value }}
                </span>
            </a>
        @empty
            <div class="m-card p-6 text-center text-sm text-on-surface-variant">No payments recorded yet.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
</x-app-layout>
