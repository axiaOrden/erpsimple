<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>Primary</h1>
                <p class="text-xs text-on-surface-variant">{{ $rows->count() }} assigned Primary customer(s)</p>
            </div>
            <a href="{{ route('dashboard') }}" class="m-chip">Home</a>
        </div>
    </x-slot>

    <div class="mb-4 -mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
        <a href="{{ route('primary.index') }}" class="m-chip m-chip-active shrink-0">Primary</a>
        <a href="{{ route('secondary.index') }}" class="m-chip shrink-0">Secondary</a>
        <a href="{{ route('visits.today') }}" class="m-chip shrink-0">FJP</a>
        <a href="{{ route('more.index') }}" class="m-chip shrink-0">More</a>
    </div>

    @if ($rows->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium">No Primary customers assigned</p>
            <p class="mt-1 text-sm text-on-surface-variant">
                Your administrator assigns Primaries to you. They are the source for orders, stock counts and shipments.
            </p>
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($rows as $row)
                @php $customer = $row['customer']; @endphp
                <div class="m-card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('visits.customer', $customer->customer_id) }}" class="block truncate font-semibold text-primary">
                                {{ $customer->business_name }}
                            </a>
                            <p class="text-xs text-on-surface-variant">
                                {{ $customer->customer_id }}
                                @if ($customer->city) · {{ $customer->city }} @endif
                                @if ($customer->sales_region) · {{ $customer->sales_region }} @endif
                            </p>
                        </div>
                        <span class="m-chip shrink-0 {{ $row['status'] === 'PENDING' ? '' : 'm-chip-active' }}">
                            {{ $row['status'] === 'PENDING' ? 'NOT VISITED' : ($row['status'] === 'CHECKED_IN' ? 'IN VISIT' : 'DONE') }}
                        </span>
                    </div>

                    <dl class="mt-3 grid grid-cols-1 gap-1 text-xs sm:grid-cols-3">
                        <div>
                            <dt class="text-on-surface-variant">First check-in</dt>
                            <dd class="font-medium">{{ $row['first_check_in']?->format('H:i') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-variant">Last check-in</dt>
                            <dd class="font-medium">
                                {{ $row['last_check_in']?->format('H:i') ?? $row['last_check_in_overall']?->translatedFormat('d M H:i') ?? 'never' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-variant">Last stock count</dt>
                            <dd class="font-medium">
                                {{ $row['last_stock_count']?->count_date?->translatedFormat('d M Y') ?? 'none' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <a href="{{ route('visits.customer', $customer->customer_id) }}"
                           class="inline-flex h-10 items-center justify-center rounded-full bg-primary text-xs font-semibold text-on-primary">
                            Check in
                        </a>
                        <a href="{{ route('inventory.counts.create', ['customer' => $customer->customer_id, 'type' => $row['default_count_type']]) }}"
                           class="inline-flex h-10 items-center justify-center rounded-full bg-secondary-container text-xs font-semibold text-on-secondary-container">
                            Stock count
                        </a>
                        <a href="{{ route('shipments.create', ['source' => $customer->customer_id]) }}"
                           class="inline-flex h-10 items-center justify-center rounded-full bg-surface-variant text-xs font-medium text-on-surface-variant">
                            Shipment
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
