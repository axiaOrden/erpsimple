<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>Secondary</h1>
                <p class="text-xs text-on-surface-variant">{{ $customers->total() }} assigned customer(s)</p>
            </div>
            <a href="{{ route('secondary.create') }}"
               class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary text-xl font-semibold text-on-primary"
               title="Register a new Secondary customer" aria-label="Register a new Secondary customer">+</a>
        </div>
    </x-slot>

    <div class="mb-4 -mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
        <a href="{{ route('primary.index') }}" class="m-chip shrink-0">Primary</a>
        <a href="{{ route('secondary.index') }}" class="m-chip m-chip-active shrink-0">Secondary</a>
        <a href="{{ route('visits.today') }}" class="m-chip shrink-0">FJP</a>
        <a href="{{ route('more.index') }}" class="m-chip shrink-0">More</a>
    </div>

    <form method="GET" action="{{ route('secondary.index') }}" class="mb-3 flex gap-2">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search customers…"
               class="w-full rounded-full border-outline-variant bg-surface-container px-4 text-sm">
        <button type="submit" class="h-11 shrink-0 rounded-full bg-secondary-container px-4 text-sm font-semibold text-on-secondary-container">
            Search
        </button>
    </form>

    @if ($customers->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium">{{ $search ? 'No customer matches "' . $search . '"' : 'No Secondary customers assigned' }}</p>
            <p class="mt-1 text-sm text-on-surface-variant">
                Register a new customer with the + button — it is assigned to you automatically.
            </p>
            <a href="{{ route('secondary.create') }}" class="mt-4 inline-block font-medium text-primary">Register a customer</a>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($customers as $customer)
                <div class="m-card p-3">
                    <div class="flex items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-surface-variant text-xs font-semibold">
                            {{ strtoupper(substr($customer->business_name, 0, 2)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('visits.customer', $customer->customer_id) }}" class="block truncate font-medium text-primary">
                                {{ $customer->business_name }}
                            </a>
                            <p class="truncate text-xs text-on-surface-variant">
                                {{ $customer->contact_person ?: 'No contact person' }}
                                @if ($customer->phone_number) · {{ $customer->phone_number }} @endif
                            </p>
                            <p class="truncate text-xs text-on-surface-variant">
                                {{ $customer->address ?: 'No address' }}@if ($customer->city), {{ $customer->city }}@endif
                            </p>
                        </div>
                        <span class="m-chip shrink-0">{{ $customer->customer_type->value }}</span>
                    </div>

                    <div class="mt-2 flex gap-2">
                        <a href="{{ route('visits.customer', $customer->customer_id) }}"
                           class="inline-flex h-10 flex-1 items-center justify-center rounded-full bg-primary-container text-xs font-semibold text-on-primary-container">
                            Open
                        </a>
                        @if ($customer->gps_latitude !== null && $customer->gps_longitude !== null)
                            <a href="{{ route('visits.customer', $customer->customer_id) }}#location"
                               class="inline-flex h-10 flex-1 items-center justify-center rounded-full bg-surface-variant text-xs font-medium text-on-surface-variant">
                                View in map
                            </a>
                        @else
                            <span class="inline-flex h-10 flex-1 items-center justify-center rounded-full bg-surface-variant/50 text-xs text-on-surface-variant/60">
                                No location yet
                            </span>
                        @endif
                        <a href="{{ route('orders.create', ['customer' => $customer->customer_id]) }}"
                           class="inline-flex h-10 flex-1 items-center justify-center rounded-full bg-secondary-container text-xs font-semibold text-on-secondary-container">
                            New order
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center justify-between gap-3">
            <p class="text-xs text-on-surface-variant">
                Showing {{ $customers->count() }} of {{ $customers->total() }}
            </p>
            @if ($customers->hasMorePages())
                <a href="{{ route('secondary.index', array_merge(request()->query(), ['per_page' => $nextPerPage, 'page' => 1])) }}"
                   class="inline-flex h-11 items-center justify-center rounded-full bg-primary px-5 text-sm font-semibold text-on-primary">
                    Load more
                </a>
            @endif
        </div>

        @if ($customers->total() > $perPage)
            <p class="mt-2 text-center text-[11px] text-on-surface-variant">
                “Load more” grows the list in place — {{ $perPage }} at a time.
            </p>
        @endif
    @endif
</x-app-layout>
