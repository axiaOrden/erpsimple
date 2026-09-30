<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Employees</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $employees->total() }} in {{ $companyId }}
                </p>
            </div>

            @can('create', \App\Models\EmployeeMaster::class)
                <a href="{{ route('employees.create', request('company') ? ['company' => request('company')] : []) }}"
                   class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                    + New employee
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="mb-4 flex flex-wrap gap-3 items-center">
        <form method="GET" class="flex-1 min-w-[240px]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or ID…"
                   class="w-full rounded-full border-outline-variant bg-surface-container" aria-label="Search employees">
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

    @if ($employees->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No employees yet</p>
            <p class="text-sm text-on-surface-variant mt-1">Employees belong to one company and can be assigned customers and product scope.</p>
            @can('create', \App\Models\EmployeeMaster::class)
                <a href="{{ route('employees.create') }}" class="inline-block mt-4 text-primary font-medium">Create the first employee</a>
            @endcan
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($employees as $employee)
                <div class="m-list-item">
                    <span class="w-10 h-10 rounded-full bg-secondary-container text-on-secondary-container grid place-items-center text-xs font-semibold shrink-0">
                        {{ strtoupper(substr($employee->employee_name, 0, 2)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">{{ $employee->employee_name }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            {{ $employee->employee_id }}
                            @if ($employee->user)
                                · login: {{ $employee->user->email }}
                            @else
                                · no login
                            @endif
                        </p>
                    </div>
                    <span class="m-chip shrink-0 {{ $employee->active ? 'm-chip-active' : 'm-chip-error' }}">
                        {{ $employee->active ? 'ACTIVE' : 'INACTIVE' }}
                    </span>
                    <a href="{{ route('assignments.edit', $employee) }}"
                       class="h-10 px-4 inline-flex items-center rounded-full bg-tertiary-container text-on-tertiary-container text-sm font-medium shrink-0">
                        Assignments
                    </a>
                    @can('update', $employee)
                        <a href="{{ route('employees.edit', $employee) }}"
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0">
                            Edit
                        </a>
                    @endcan
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $employees->links() }}</div>
    @endif
</x-app-layout>
