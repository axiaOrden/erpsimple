<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Stock counts</h1>
                <p class="text-sm text-on-surface-variant">{{ $counts->total() }} counts</p>
            </div>
            <a href="{{ route('inventory.counts.create') }}"
               class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                + New count
            </a>
        </div>
    </x-slot>

    @if ($counts->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No stock counts yet</p>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($counts as $count)
                <a href="{{ route('inventory.counts.show', $count) }}" class="m-list-item">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">{{ $count->customer->business_name ?? $count->customer_id }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            {{ $count->count_no }} · {{ $count->count_type->value }} · {{ $count->count_date?->format('d M Y') }}
                            · by {{ $count->employee->employee_name ?? $count->employee_id }}
                        </p>
                    </div>
                    <span class="m-chip {{ $count->count_status->value === 'SUBMITTED' ? 'm-chip-active' : '' }}">
                        {{ $count->count_status->value }}
                    </span>
                </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $counts->links() }}</div>
    @endif
</x-app-layout>
