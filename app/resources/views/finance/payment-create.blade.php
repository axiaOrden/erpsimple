<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>Record payment</h1>
                <p class="text-sm text-on-surface-variant">Against the sold-to debtor — allocated to invoices after confirmation</p>
            </div>
            <a href="{{ route('finance.payments.index') }}" class="m-chip">Back</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('finance.payments.store') }}" class="space-y-4 max-w-2xl">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Debtor (sold-to customer)</span>
            <select name="customer_id" required class="w-full rounded-m border-outline-variant bg-surface text-sm">
                <option value="">Choose debtor…</option>
                @foreach ($debtors as $debtor)
                    <option value="{{ $debtor->customer_id }}">{{ $debtor->business_name }} ({{ $debtor->customer_type }})</option>
                @endforeach
            </select>
        </label>

        <div class="m-card p-4 grid grid-cols-2 gap-3">
            <label class="space-y-1">
                <span class="text-xs text-on-surface-variant">Amount</span>
                <input type="number" name="amount" step="0.01" min="0.01" required
                       class="w-full rounded-m border-outline-variant bg-surface text-sm">
            </label>
            <label class="space-y-1">
                <span class="text-xs text-on-surface-variant">Method</span>
                <select name="payment_method" class="w-full rounded-m border-outline-variant bg-surface text-sm">
                    @foreach (['CASH', 'TRANSFER', 'POS', 'OTHER'] as $method)
                        <option value="{{ $method }}">{{ $method }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Reference (optional)</span>
            <input type="text" name="payment_reference" maxlength="100"
                   class="w-full rounded-m border-outline-variant bg-surface text-sm" placeholder="receipt / transfer ref">
        </label>

        <button type="submit"
                onclick="return confirm('Record this payment? It is created as PENDING; confirm it before allocating.')"
                class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
            Record payment
        </button>
    </form>
</x-app-layout>
