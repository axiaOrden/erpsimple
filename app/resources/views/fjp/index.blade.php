<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Fixed Journey Plans</h1>
                <p class="text-sm text-on-surface-variant">
                    Visit schedules — {{ $plans->total() }} in {{ $companyId }}.
                    Preferences are per customer and company; FJP never implies a supplying Primary.
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

    <div class="m-card mb-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold">Mass upload preferred visits</h2>
                <p class="mt-1 text-xs text-on-surface-variant">
                    Pipe-delimited CSV or TXT: <code>customer id|preferred week|preferred day</code>.
                    Week accepts 1-4 or blank/*/EVERY; day accepts 0-6 or a weekday name.
                    Uploaded customers replace their existing {{ $companyId }} schedule.
                </p>
            </div>
            <form method="POST" action="{{ route('fjp.import', request('company') ? ['company' => request('company')] : []) }}"
                  enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="file" name="fjp_file" accept=".csv,.txt,text/csv,text/plain" required
                       class="block max-w-64 text-xs text-on-surface-variant file:mr-2 file:rounded-full file:border-0 file:bg-surface-variant file:px-3 file:py-2 file:font-medium">
                <button type="submit"
                        class="inline-flex h-10 items-center rounded-full bg-primary px-4 text-sm font-semibold text-on-primary">
                    Upload
                </button>
            </form>
        </div>
        <x-input-error :messages="$errors->get('fjp_file')" class="mt-2" />
    </div>

    @if ($plans->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No journey plans yet</p>
            <p class="text-sm text-on-surface-variant mt-1">
                A plan records one company's preferred visit schedule for a customer in the continuous 4-week rotation.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('fjp.destroy-selected', request('company') ? ['company' => request('company')] : []) }}"
              x-data="{ selected: [] }"
              @submit="if (! confirm('Clear the selected preferred visit times for {{ $companyId }}? This cannot be undone.')) { $event.preventDefault() }">
            @csrf
            @method('delete')

            <div class="mb-2 flex items-center justify-between gap-3">
                <label class="inline-flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" class="rounded border-outline text-primary focus:ring-primary"
                           @change="selected = $event.target.checked ? {{ Js::from($plans->pluck('fjp_id')->map(fn ($id) => (string) $id)->values()) }} : []"
                           :checked="selected.length === {{ $plans->count() }}">
                    Select all on this page
                </label>
                <button type="submit" :disabled="selected.length === 0"
                        class="inline-flex h-10 items-center rounded-full bg-error-container px-4 text-sm font-semibold text-on-error-container disabled:cursor-not-allowed disabled:opacity-50">
                    Clear selected
                </button>
            </div>

            <div class="m-card divide-y divide-outline-variant">
                @foreach ($plans as $plan)
                    <div class="m-list-item">
                        <input type="checkbox" name="fjp_ids[]" value="{{ $plan->fjp_id }}" x-model="selected"
                               aria-label="Select {{ $plan->customer->business_name }} {{ $plan->visitLabel() }}"
                               class="rounded border-outline text-primary focus:ring-primary">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">
                            {{ $plan->customer->business_name }}
                            <span class="text-xs text-on-surface-variant">· {{ $plan->customer->customer_type->value }}</span>
                        </p>
                        <p class="flex flex-wrap items-center gap-1 text-xs text-on-surface-variant">
                            <x-fjp-chip :week="$plan->preferred_week" :day="$plan->preferred_day" />
                        </p>
                    </div>
                    <span class="m-chip shrink-0 {{ $plan->active ? 'm-chip-active' : 'm-chip-error' }}">
                        {{ $plan->active ? 'ACTIVE' : 'OFF' }}
                    </span>
                    @can('update', $plan)
                        <a href="{{ route('fjp.edit', $plan) }}"
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0">
                            Edit
                        </a>
                    @endcan
                    </div>
                @endforeach
            </div>
        </form>
        <div class="mt-4">{{ $plans->links() }}</div>
    @endif
</x-app-layout>
