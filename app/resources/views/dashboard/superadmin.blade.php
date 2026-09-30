<x-app-layout>
    <x-slot name="header">
        <h1>All companies</h1>
        <p class="text-sm text-on-surface-variant">
            Super administration
            @if (auth()->user()->currentCompanyId())
                · working in <span class="m-chip m-chip-active">{{ auth()->user()->currentCompanyId() }}</span>
            @endif
        </p>
    </x-slot>

    <div class="grid gap-3 md:grid-cols-2">
        @foreach ($companies as $row)
            <div class="m-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $row['company']->company_name }}</p>
                        <p class="text-xs text-on-surface-variant">{{ $row['company']->company_id }}</p>
                    </div>
                    <span class="m-chip {{ $row['company']->active ? 'm-chip-active' : 'm-chip-error' }}">
                        {{ $row['company']->active ? 'ACTIVE' : 'INACTIVE' }}
                    </span>
                </div>

                <dl class="grid grid-cols-3 gap-2 mt-4 text-center">
                    <div>
                        <dt class="text-xs text-on-surface-variant">Products</dt>
                        <dd class="text-lg font-semibold">{{ $row['products'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-on-surface-variant">Employees</dt>
                        <dd class="text-lg font-semibold">{{ $row['employees'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-on-surface-variant">Users</dt>
                        <dd class="text-lg font-semibold">{{ $row['users'] }}</dd>
                    </div>
                </dl>
            </div>
        @endforeach
    </div>
</x-app-layout>
