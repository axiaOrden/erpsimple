<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>More</h1>
                <p class="text-xs text-on-surface-variant">{{ $employee->employee_name }} · {{ $employee->employee_id }}</p>
            </div>
            <a href="{{ route('dashboard') }}" class="m-chip">Home</a>
        </div>
    </x-slot>

    <div class="mb-4 -mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
        <a href="{{ route('primary.index') }}" class="m-chip shrink-0">Primary</a>
        <a href="{{ route('secondary.index') }}" class="m-chip shrink-0">Secondary</a>
        <a href="{{ route('visits.today') }}" class="m-chip shrink-0">FJP</a>
        <a href="{{ route('more.index') }}" class="m-chip m-chip-active shrink-0">More</a>
    </div>

    <div class="grid grid-cols-2 gap-2 md:grid-cols-3">
        <a href="{{ route('shipments.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Shipments</p>
            <p class="text-[11px] text-on-surface-variant">Dispatch + goods issue</p>
        </a>
        <a href="{{ route('pod.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">POD</p>
            <p class="text-[11px] text-on-surface-variant">{{ $attention['awaiting_pod_orders'] }} awaiting acceptance</p>
        </a>
        <a href="{{ route('finance.invoices.index', ['status' => 'outstanding']) }}" class="m-card p-3">
            <p class="text-sm font-semibold">Pending settlements</p>
            <p class="text-[11px] text-on-surface-variant">{{ $attention['unpaid_invoices'] }} invoice(s) ready for payment</p>
        </a>
        <a href="{{ route('finance.invoices.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Invoices</p>
            <p class="text-[11px] text-on-surface-variant">{{ number_format((float) $attention['outstanding'], 2) }} outstanding</p>
        </a>
        <a href="{{ route('secondary.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Customers</p>
            <p class="text-[11px] text-on-surface-variant">{{ $attention['customers_total'] }} assigned</p>
        </a>
        <a href="{{ route('transit.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Field stock / transit</p>
            <p class="text-[11px] text-on-surface-variant">{{ $attention['custody_lines'] }} balance(s) in custody</p>
        </a>
        <a href="{{ route('inventory.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Inventory</p>
            <p class="text-[11px] text-on-surface-variant">Stock by customer</p>
        </a>
        <a href="{{ route('inventory.counts.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Stock counts</p>
            <p class="text-[11px] text-on-surface-variant">Submitted + drafts</p>
        </a>
        <a href="{{ route('deliveries.index') }}" class="m-card p-3">
            <p class="text-sm font-semibold">Deliveries</p>
            <p class="text-[11px] text-on-surface-variant">Allocations in flight</p>
        </a>
    </div>

    @if ($attention['blocked_customers']->isNotEmpty())
        <section class="mt-4">
            <h2 class="mb-2 text-base font-semibold">Debt block</h2>
            <div class="m-card divide-y divide-outline-variant">
                @foreach ($attention['blocked_customers'] as $blocked)
                    <a href="{{ route('finance.statement', $blocked['customer']) }}" class="m-list-item">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ $blocked['customer']->business_name }}</p>
                            <p class="text-xs text-on-surface-variant">New orders blocked until settlement</p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold">{{ number_format((float) $blocked['outstanding'], 2) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-4">
        <h2 class="mb-2 text-base font-semibold">Account</h2>
        <div class="m-card divide-y divide-outline-variant">
            <a href="{{ route('profile.edit') }}" class="m-list-item">
                <span class="flex-1 text-sm font-medium">Profile</span>
                <span class="text-xs text-on-surface-variant">{{ auth()->user()->email }}</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="m-list-item w-full text-left">
                    <span class="flex-1 text-sm font-medium">Log out</span>
                    <span class="text-xs text-on-surface-variant">{{ auth()->user()->name }}</span>
                </button>
            </form>
        </div>
    </section>
</x-app-layout>
