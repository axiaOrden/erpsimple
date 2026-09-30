<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $count->count_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $count->customer->business_name ?? $count->customer_id }}
                    · {{ $count->count_type->value }}
                </p>
            </div>
            <span class="m-chip {{ $count->count_status->value === 'SUBMITTED' ? 'm-chip-active' : '' }}">
                {{ $count->count_status->value }}
            </span>
        </div>
    </x-slot>

    <div class="space-y-4 max-w-2xl">
        <div class="m-card p-4 text-sm text-on-surface-variant">
            <div class="flex justify-between"><span>Count date</span><span>{{ $count->count_date?->format('d M Y') }}</span></div>
            <div class="flex justify-between mt-1"><span>Counted by</span><span>{{ $count->employee->employee_name ?? $count->employee_id }}</span></div>
            <div class="flex justify-between mt-1"><span>Submitted</span><span>{{ $count->submitted_at?->format('d M Y H:i') ?? '—' }}</span></div>
        </div>

        <div class="m-card divide-y divide-outline-variant text-sm">
            @foreach ($count->items as $item)
                <div class="p-4 flex items-center justify-between">
                    <div>
                        <p class="font-medium">{{ $item->product->product_description ?? $item->product_id }}</p>
                        <p class="text-xs text-on-surface-variant">
                            expected {{ $item->expected_qty ?? '—' }} · variance {{ $item->variance_qty }}
                        </p>
                    </div>
                    <p class="font-semibold tabular-nums">{{ $item->counted_qty }} {{ $item->count_unit }}</p>
                </div>
            @endforeach
        </div>

        @if ($count->count_status->value === 'DRAFT')
            @php
                $authoritative = $count->count_type->value === 'PRIMARY_OPERATIONAL';
            @endphp
            <form method="POST" action="{{ route('inventory.counts.submit', $count) }}"
                  onsubmit="return confirm('{{ $authoritative
                    ? "Submit as AUTHORITATIVE count? Inventory rows with restricted stock will reject the submission; accepted rows get a new physical baseline."
                    : "Submit this observational count? Inventory is not changed." }}')">
                @csrf
                <button type="submit"
                        class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                    {{ $authoritative ? 'Submit authoritative count' : 'Submit observational count' }}
                </button>
            </form>
        @endif
    </div>
</x-app-layout>
