<x-app-layout>
    <x-slot name="header">
        <div>
            <h1>Transit stock</h1>
            <p class="text-sm text-on-surface-variant">Issued-but-unaccepted stock in the delivery operation's custody</p>
        </div>
    </x-slot>

    <div class="space-y-3">
        @forelse ($rows as $transit)
            <a href="{{ route('transit.show', $transit) }}"
               class="m-card p-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium tabular-nums">
                        {{ rtrim(rtrim((string) $transit->quantity, '0'), '.') }} {{ $transit->basic_unit }}
                        · {{ $transit->product->product_description ?? $transit->product_id }}
                    </p>
                    <p class="text-xs text-on-surface-variant truncate">
                        {{ $transit->holding_employee_id }} · from {{ $transit->origin_delivery_no }}
                        · source {{ $transit->source_customer_id }}
                    </p>
                </div>
                <div class="flex flex-col items-end gap-1 shrink-0">
                    <span class="m-chip {{ match ($transit->transit_status->value) {
                        'REUSABLE' => 'm-chip-active',
                        'DAMAGED', 'LOSS', 'WRITTEN_OFF' => 'm-chip-error',
                        'DISCREPANCY', 'PENDING_SOURCE_RECEIPT' => 'm-chip-warning',
                        default => '',
                    } }}">{{ $transit->transit_status->value }}</span>
                    @if ($transit->liability_party->value !== 'NONE')
                        <span class="text-[10px] text-on-surface-variant">liability: {{ $transit->liability_party->value }}</span>
                    @endif
                </div>
            </a>
        @empty
            <div class="m-card p-6 text-center text-sm text-on-surface-variant">
                No transit stock. Unaccepted delivery quantities will appear here.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
</x-app-layout>
