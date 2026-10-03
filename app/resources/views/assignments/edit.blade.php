<x-app-layout>
    <x-slot name="header">
        <h1>Assignments — {{ $employee->employee_name }}</h1>
        <p class="text-sm text-on-surface-variant">
            {{ $employee->employee_id }} · {{ $employee->company_id }} · Region {{ $employee->region_code ?: 'not set' }}
        </p>
    </x-slot>

    <form method="POST" action="{{ route('assignments.update', $employee) }}" class="max-w-3xl space-y-5"
          x-data="{
              selectedCustomers: {{ Js::from($customers->filter(fn ($row) => $row['assigned'])->map(fn ($row) => (string) $row['model']->customer_id)->values()) }},
              selectedProducts: {{ Js::from($products->filter(fn ($row) => $row['scoped'])->map(fn ($row) => (string) $row['model']->product_id)->values()) }}
          }">
        @csrf
        @method('put')

        <div class="m-card p-6">
            <h2>Assigned customers</h2>
            <p class="text-sm text-on-surface-variant mt-1 mb-4">
                The commercial relationship. These are the customers the employee can record visits
                and orders for. Only active customers in region <strong>{{ $employee->region_code ?: 'not set' }}</strong>
                are eligible. The supplying Primary is chosen per order, not by this list.
            </p>

            @if ($customers->isEmpty())
                <p class="text-sm text-on-surface-variant">No active customers exist in this employee's sales region.</p>
            @else
                <div class="grid sm:grid-cols-2 gap-2 max-h-96 overflow-y-auto">
                    @foreach ($customers as $row)
                        <label class="flex cursor-pointer items-center gap-3 rounded-m border p-3 transition-colors {{ $row['assigned'] ? 'border-primary bg-primary-container' : 'border-outline-variant bg-surface-container-low/50' }}"
                               :class="selectedCustomers.includes('{{ $row['model']->customer_id }}') ? 'border-primary bg-primary-container shadow-m1' : 'border-outline-variant bg-surface-container-low/50 hover:bg-surface-container-high'">
                            <input type="checkbox" name="customer_ids[]" value="{{ $row['model']->customer_id }}"
                                   class="sr-only" x-model="selectedCustomers"
                                   @checked($row['assigned'])>
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full border-2 transition-colors"
                                  :class="selectedCustomers.includes('{{ $row['model']->customer_id }}') ? 'border-primary bg-primary text-on-primary' : 'border-outline bg-transparent text-transparent'"
                                  aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium truncate">{{ $row['model']->business_name }}</span>
                                <span class="block text-xs text-on-surface-variant">
                                    {{ $row['model']->customer_type->value }}
                                    @if ($row['model']->city) · {{ $row['model']->city }} @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="m-card p-6" x-data="{ mode: '{{ $hasProductScope ? 'SELECTED' : 'ALL' }}' }">
            <h2>Product scope</h2>
            <p class="text-sm text-on-surface-variant mt-1 mb-4">
                No selection means the employee may sell <strong>all active {{ $employee->company_id }} products</strong>.
            </p>

            <div class="flex gap-2 mb-4">
                <label class="m-chip cursor-pointer {{ $hasProductScope ? '' : 'm-chip-active' }}">
                    <input type="radio" name="product_scope_mode" value="ALL" class="sr-only"
                           x-model="mode" @change="mode = 'ALL'">
                    All products
                </label>
                <label class="m-chip cursor-pointer {{ $hasProductScope ? 'm-chip-active' : '' }}">
                    <input type="radio" name="product_scope_mode" value="SELECTED" class="sr-only"
                           x-model="mode" @change="mode = 'SELECTED'">
                    Selected products
                </label>
            </div>

            <div x-show="mode === 'SELECTED'" x-transition class="grid sm:grid-cols-2 gap-2 max-h-96 overflow-y-auto">
                @foreach ($products as $row)
                    <label class="flex cursor-pointer items-center gap-3 rounded-m border p-3 transition-colors {{ $row['scoped'] ? 'border-primary bg-primary-container' : 'border-outline-variant bg-surface-container-low/50' }}"
                           :class="selectedProducts.includes('{{ $row['model']->product_id }}') ? 'border-primary bg-primary-container shadow-m1' : 'border-outline-variant bg-surface-container-low/50 hover:bg-surface-container-high'">
                        <input type="checkbox" name="product_ids[]" value="{{ $row['model']->product_id }}"
                               class="sr-only" x-model="selectedProducts"
                               @checked($row['scoped'])>
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full border-2 transition-colors"
                              :class="selectedProducts.includes('{{ $row['model']->product_id }}') ? 'border-primary bg-primary text-on-primary' : 'border-outline bg-transparent text-transparent'"
                              aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium truncate">{{ $row['model']->product_description }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ $row['model']->product_id }} · {{ $row['model']->basic_unit }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('employees.index', request('company') ? ['company' => $employee->company_id] : []) }}"
               class="h-11 inline-flex items-center px-5 rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Cancel
            </a>
            <button type="submit"
                    class="h-11 inline-flex items-center px-6 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                Save assignments
            </button>
        </div>
    </form>
</x-app-layout>
