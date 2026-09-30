<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>Credits</h1>
                <p class="text-sm text-on-surface-variant">Customer credit reduces net exposure — never POD differences</p>
            </div>
            <a href="{{ route('finance.credits.create') }}" class="m-chip m-chip-active">New credit</a>
        </div>
    </x-slot>

    <div class="space-y-3">
        @forelse ($credits as $credit)
            <a href="{{ route('finance.credits.show', $credit) }}"
               class="m-card p-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium">{{ $credit->credit_no }}</p>
                    <p class="text-xs text-on-surface-variant truncate">
                        {{ $credit->customer_id }} · {{ $credit->credit_source->value }}
                        @if ($credit->source_reference) · {{ $credit->source_reference }} @endif
                        · {{ $credit->created_at?->format('d M Y') }}
                    </p>
                    <p class="text-xs tabular-nums text-on-surface-variant">
                        remaining {{ number_format((float) $credit->remaining_amount, 2) }} {{ $credit->currency }}
                        of {{ number_format((float) $credit->original_amount, 2) }}
                    </p>
                </div>
                <span class="m-chip {{ $credit->credit_status->value === 'OPEN' ? 'm-chip-active' : ($credit->credit_status->value === 'CANCELLED' ? 'm-chip-error' : '') }}">
                    {{ $credit->credit_status->value }}
                </span>
            </a>
        @empty
            <div class="m-card p-6 text-center text-sm text-on-surface-variant">No customer credits yet.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $credits->links() }}</div>
</x-app-layout>
