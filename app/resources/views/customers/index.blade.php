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

    <div class="m-card mb-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h2 class="text-sm font-semibold">Upload company preferred visits</h2>
                <p class="mt-1 text-xs text-on-surface-variant">
                    Upload a pipe-delimited CSV or TXT file using
                    <code>customer id|preferred week|preferred day</code>.
                    Week accepts 1-4 or blank/*/EVERY. Day accepts Sunday-Saturday or 0-6.
                    Each uploaded customer's existing company schedule is replaced.
                </p>
            </div>
            <form method="POST" action="{{ route('customers.fjp.import') }}" enctype="multipart/form-data"
                  class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="file" name="fjp_file" accept=".csv,.txt,text/csv,text/plain" required
                       class="block max-w-64 text-xs text-on-surface-variant file:mr-2 file:rounded-full file:border-0 file:bg-surface-variant file:px-3 file:py-2 file:font-medium">
                <button type="submit"
                        class="inline-flex h-10 items-center rounded-full bg-primary px-4 text-sm font-semibold text-on-primary">
                    Upload FJP
                </button>
            </form>
        </div>
        <x-input-error :messages="$errors->get('fjp_file')" class="mt-2" />
    </div>

    @if ($customers->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No customers found</p>
            <p class="text-sm text-on-surface-variant mt-1">
                Customers are global: PRIMARY (distributors), SECONDARY, VAN and SHIP_TO delivery locations.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('customers.fjp.destroy-selected') }}"
              x-data="{ selected: [] }"
              @submit="if (! confirm('Clear all preferred visit times for the selected customers in your company? This cannot be undone.')) { $event.preventDefault() }">
            @csrf
            @method('delete')

            <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                <button type="button"
                        class="m-button-tonal px-4"
                        :class="selected.length === {{ $customers->count() }} ? 'bg-primary-container text-on-primary-container' : ''"
                        @click="selected = selected.length === {{ $customers->count() }} ? [] : {{ Js::from($customers->pluck('customer_id')->map(fn ($id) => (string) $id)->values()) }}">
                    <span x-text="selected.length === {{ $customers->count() }} ? 'Clear selection' : 'Select all'"></span>
                </button>
                <button type="submit" :disabled="selected.length === 0"
                        class="inline-flex h-10 items-center rounded-full bg-error-container px-4 text-sm font-semibold text-on-error-container disabled:cursor-not-allowed disabled:opacity-50">
                    Clear FJP
                </button>
            </div>

            <div class="space-y-2">
                @foreach ($customers as $customer)
                    <div class="m-list-item cursor-pointer rounded-m border transition-colors"
                         :class="selected.includes('{{ $customer->customer_id }}') ? 'border-primary bg-primary-container shadow-m1' : 'border-outline-variant bg-surface-container/80'"
                         @click="selected.includes('{{ $customer->customer_id }}') ? selected = selected.filter(id => id !== '{{ $customer->customer_id }}') : selected.push('{{ $customer->customer_id }}')">
                    <label class="grid h-11 w-11 shrink-0 cursor-pointer place-items-center" @click.stop>
                        <input type="checkbox" name="customer_ids[]" value="{{ $customer->customer_id }}" x-model="selected"
                               aria-label="Select {{ $customer->business_name }}" class="sr-only">
                        <span class="grid h-7 w-7 place-items-center rounded-full border-2 transition-colors"
                              :class="selected.includes('{{ $customer->customer_id }}') ? 'border-primary bg-primary text-on-primary' : 'border-outline bg-transparent text-transparent'"
                              aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </span>
                    </label>
                    <span class="w-10 h-10 rounded-full bg-surface-variant text-on-surface-variant grid place-items-center text-xs font-semibold shrink-0">
                        {{ strtoupper(substr($customer->business_name, 0, 2)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('customers.show', $customer) }}" class="block font-medium truncate text-primary" @click.stop>
                            {{ $customer->business_name }}
                        </a>
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

                    <a href="{{ route('customers.show', $customer) }}"
                       class="h-10 px-4 inline-flex items-center rounded-full bg-surface-variant text-on-surface-variant text-sm font-medium shrink-0" @click.stop>
                        View
                    </a>

                    @can('update', $customer)
                        <a href="{{ route('customers.edit', $customer) }}"
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0" @click.stop>
                            Edit
                        </a>
                    @endcan
                    </div>
                @endforeach
            </div>
        </form>

        <div class="mt-4">{{ $customers->links() }}</div>
    @endif
</x-app-layout>
