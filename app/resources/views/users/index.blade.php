<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>App users</h1>
                <p class="text-sm text-on-surface-variant">Login accounts and roles</p>
            </div>
            @can('create', \App\Models\AppUser::class)
                <a href="{{ route('users.create', request('company') ? ['company' => request('company')] : []) }}"
                   class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                    + New user
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="mb-4 flex flex-wrap gap-3 items-center">
        <form method="GET" class="flex-1 min-w-[240px]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or email…"
                   class="w-full rounded-full border-outline-variant bg-surface-container" aria-label="Search users">
        </form>

        @if (auth()->user()->isSuperadmin())
            <form method="GET">
                <select name="company" onchange="this.form.submit()"
                        class="rounded-full border-outline-variant bg-surface-container" aria-label="Filter by company">
                    <option value="" @selected(!request('company'))>All companies</option>
                    @foreach ($companies as $c)
                        <option value="{{ $c->company_id }}" @selected(request('company') === $c->company_id)>{{ $c->company_id }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if ($users->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No users found</p>
            <p class="text-sm text-on-surface-variant mt-1">Users are provisioned by admins; there is no self-registration.</p>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($users as $user)
                <div class="m-list-item">
                    <span class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container grid place-items-center text-xs font-semibold shrink-0">
                        {{ strtoupper(substr($user->name, 0, 2)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">{{ $user->name }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            {{ $user->email }}
                            @if ($user->company_id) · {{ $user->company_id }} @endif
                            @if ($user->employee_id) · {{ $user->employee_id }} @endif
                        </p>
                    </div>
                    <span class="m-chip shrink-0 {{ $user->role->value === 'SUPERADMIN' ? 'm-chip-active' : '' }}">{{ $user->role->value }}</span>
                    <span class="m-chip shrink-0 {{ $user->active ? '' : 'm-chip-error' }}">{{ $user->active ? 'ACTIVE' : 'DISABLED' }}</span>

                    @can('update', $user)
                        <a href="{{ route('users.edit', $user) }}"
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0">
                            Edit
                        </a>
                    @endcan
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif
</x-app-layout>
