<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>New credit</h1>
                <p class="text-sm text-on-surface-variant">MANUAL_ADJUSTMENT — applied to outstanding invoices after creation</p>
            </div>
            <a href="{{ route('finance.credits.index') }}" class="m-chip">Back</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('finance.credits.store') }}" class="space-y-4 max-w-2xl">
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

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Amount</span>
            <input type="number" name="amount" step="0.01" min="0.01" required
                   class="w-full rounded-m border-outline-variant bg-surface text-sm">
        </label>

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Remarks (optional)</span>
            <input type="text" name="remarks" maxlength="255"
                   class="w-full rounded-m border-outline-variant bg-surface text-sm" placeholder="reason for the adjustment">
        </label>

        <button type="submit"
                onclick="return confirm('Create this MANUAL_ADJUSTMENT credit? It reduces the debtor\'s net exposure once allocated.')"
                class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
            Create credit
        </button>
    </form>
</x-app-layout>
