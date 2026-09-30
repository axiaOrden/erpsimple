<x-app-layout>
    <x-slot name="header">
        <div>
            <h1>{{ $customer->business_name }}</h1>
            <p class="text-sm text-on-surface-variant">Statement · debtor {{ $customer->customer_id }}</p>
        </div>
    </x-slot>

    <div class="space-y-4 max-w-2xl">
        <div class="m-card p-4 text-sm">
            <p class="font-medium mb-2">Exposure</p>
            <div class="flex justify-between"><span>Invoice outstanding</span><span class="tabular-nums">{{ number_format((float) $exposure['outstanding'], 2) }}</span></div>
            <div class="flex justify-between mt-1"><span>Available credit</span><span class="tabular-nums">{{ number_format((float) $exposure['available_credit'], 2) }}</span></div>
            <div class="flex justify-between mt-1 font-semibold"><span>Net exposure</span><span class="tabular-nums">{{ number_format((float) $exposure['net_exposure'], 2) }}</span></div>
            <p class="mt-2 text-xs text-on-surface-variant">A debtor with positive net exposure cannot confirm new orders.</p>
        </div>

        <div class="m-card divide-y divide-outline-variant text-sm">
            <p class="p-4 pb-2 font-medium">Invoices</p>
            @forelse ($invoices as $invoice)
                <a href="{{ route('finance.invoices.show', $invoice) }}" class="p-4 flex items-center justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block truncate">{{ $invoice->invoice_no }} · {{ $invoice->invoice_date?->format('d M Y') }}</span>
                        <span class="block text-xs text-on-surface-variant tabular-nums">
                            settled {{ number_format((float) $invoice->settled_amount, 2) }}
                            + credited {{ number_format((float) $invoice->credit_amount, 2) }}
                        </span>
                    </span>
                    <span class="text-right">
                        <span class="block tabular-nums">{{ number_format((float) $invoice->invoice_amount, 2) }}</span>
                        <span class="block text-xs {{ $invoice->payment_status->value === 'PAID' ? 'text-on-surface-variant' : 'text-error' }}">{{ $invoice->payment_status->value }}</span>
                    </span>
                </a>
            @empty
                <p class="p-4 text-on-surface-variant">No invoices.</p>
            @endforelse
        </div>

        <div class="m-card divide-y divide-outline-variant text-sm">
            <p class="p-4 pb-2 font-medium">Payments</p>
            @forelse ($payments as $payment)
                <a href="{{ route('finance.payments.show', $payment) }}" class="p-4 flex items-center justify-between">
                    <span>#{{ $payment->payment_id }} · {{ $payment->payment_date?->format('d M Y') }} · {{ $payment->payment_method->value }}</span>
                    <span class="tabular-nums">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</span>
                </a>
            @empty
                <p class="p-4 text-on-surface-variant">No payments.</p>
            @endforelse
        </div>

        <div class="m-card divide-y divide-outline-variant text-sm">
            <p class="p-4 pb-2 font-medium">Credits</p>
            @forelse ($credits as $credit)
                <a href="{{ route('finance.credits.show', $credit) }}" class="p-4 flex items-center justify-between">
                    <span>{{ $credit->credit_no }} · {{ $credit->credit_source->value }}</span>
                    <span class="tabular-nums">remaining {{ number_format((float) $credit->remaining_amount, 2) }}</span>
                </a>
            @empty
                <p class="p-4 text-on-surface-variant">No credits.</p>
            @endforelse
        </div>

        <a href="{{ route('customers.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All customers
        </a>
    </div>
</x-app-layout>
