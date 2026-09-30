<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Customers</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $customers->total() }} global (shared across companies)
                    @can('create', \App\Models\CustomerMaster::class)
                        · <a href="{{ route('customers.create') }}" class="text-primary font-medium">Add customer</a>
                    @endcan
                </p>
            </div>

            @can('create', \App\Models\CustomerMaster::class)
                <a href="{{ route('customers.create') }}"
                   class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                    + New customer
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="mb-4 flex flex-wrap gap-3 items-center">
        <form method="GET" class="flex-1 min-w-[240px]">
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search name, ID or city…"
                   class="w-full rounded-full border-outline-variant bg-surface-container" aria-label="Search customers">
        </form>

        <div class="flex gap-2 overflow-x-auto">
            <a href="{{ route('customers.index', array_filter(['q' => $filters['q']])) }}"
               class="m-chip {{ blank($filters['type']) ? 'm-chip-active' : '' }}">All</a>
            @foreach ($types as $type)
                <a href="{{ route('customers.index', array_filter(['q' => $filters['q'], 'type' => $type])) }}"
                   class="m-chip {{ $filters['type'] === $type ? 'm-chip-active' : '' }}">{{ $type }}</a>
            @endforeach
        </div>
    </div>

    @if ($customers->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No customers found</p>
            <p class="text-sm text-on-surface-variant mt-1">
                Customers are global: PRIMARY (distributors), SECONDARY, VAN and SHIP_TO delivery locations.
            </p>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($customers as $customer)
                <div class="m-list-item">
                    <span class="w-10 h-10 rounded-full bg-surface-variant text-on-surface-variant grid place-items-center text-xs font-semibold shrink-0">
                        {{ strtoupper(substr($customer->business_name, 0, 2)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">{{ $customer->business_name }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            {{ $customer->customer_id }}
                            @if ($customer->parent)
                                · SHIP_TO of {{ $customer->parent->business_name }}
                            @endif
                            @if ($customer->city)
                                · {{ $customer->city }}
                            @endif
                        </p>
                    </div>
                    <span class="m-chip shrink-0 {{ $customer->active ? '' : 'm-chip-error' }}">{{ $customer->customer_type->value }}</span>

                    @can('update', $customer)
                        <a href="{{ route('customers.edit', $customer) }}"
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0">
                            Edit
                        </a>
                    @endcan
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $customers->links() }}</div>
    @endif
</x-app-layout>
