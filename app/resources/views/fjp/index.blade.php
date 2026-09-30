<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Fixed Journey Plans</h1>
                <p class="text-sm text-on-surface-variant">
                    Visit schedules — {{ $plans->total() }} in {{ $companyId }}.
                    FJP never implies a supplying Primary.
                </p>
            </div>
            @can('create', \App\Models\EmployeeMaster::class)
                <a href="{{ route('fjp.create', request('company') ? ['company' => request('company')] : []) }}"
                   class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                    + New plan
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="mb-4 flex flex-wrap gap-3 items-center">
        <form method="GET" class="flex-1 min-w-[240px]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search customer…"
                   class="w-full rounded-full border-outline-variant bg-surface-container" aria-label="Search plans">
        </form>
        @if (auth()->user()->isSuperadmin())
            <form method="GET">
                <select name="company" onchange="this.form.submit()"
                        class="rounded-full border-outline-variant bg-surface-container" aria-label="Filter by company">
                    @foreach ($companies as $c)
                        <option value="{{ $c->company_id }}" @selected($c->company_id === $companyId)>{{ $c->company_id }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if ($plans->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No journey plans yet</p>
            <p class="text-sm text-on-surface-variant mt-1">
                A plan binds one employee to one customer with a preferred weekday (and optional week of month).
            </p>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($plans as $plan)
                <div class="m-list-item">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">
                            {{ $plan->customer->business_name }}
                            <span class="text-xs text-on-surface-variant">· {{ $plan->customer->customer_type->value }}</span>
                        </p>
                        <p class="text-xs text-on-surface-variant">
                            {{ $plan->employee->employee_name }} ({{ $plan->employee_id }})
                            · {{ $plan->preferred_week ? 'Rotation week '.$plan->preferred_week.', ' : 'Every week, ' }}{{ ucfirst(strtolower($plan->preferred_day)) }}
                        </p>
                    </div>
                    <span class="m-chip shrink-0 {{ $plan->active ? 'm-chip-active' : 'm-chip-error' }}">
                        {{ $plan->active ? 'ACTIVE' : 'OFF' }}
                    </span>
                    @can('update', $plan->employee)
                        <a href="{{ route('fjp.edit', $plan) }}"
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0">
                            Edit
                        </a>
                    @endcan
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $plans->links() }}</div>
    @endif
</x-app-layout>
