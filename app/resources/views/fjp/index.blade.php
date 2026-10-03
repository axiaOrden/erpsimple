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
                <button type="button" class="m-button-tonal px-4"
                        :class="selected.length === {{ $plans->count() }} ? 'bg-primary-container text-on-primary-container' : ''"
                        @click="selected = selected.length === {{ $plans->count() }} ? [] : {{ Js::from($plans->pluck('fjp_id')->map(fn ($id) => (string) $id)->values()) }}">
                    <span x-text="selected.length === {{ $plans->count() }} ? 'Clear selection' : 'Select all on this page'"></span>
                </button>
                <button type="submit" :disabled="selected.length === 0"
                        class="inline-flex h-10 items-center rounded-full bg-error-container px-4 text-sm font-semibold text-on-error-container disabled:cursor-not-allowed disabled:opacity-50">
                    Clear selected
                </button>
            </div>

            <div class="space-y-2">
                @foreach ($plans as $plan)
                    <div class="m-list-item cursor-pointer rounded-m border transition-colors"
                         :class="selected.includes('{{ $plan->fjp_id }}') ? 'border-primary bg-primary-container shadow-m1' : 'border-outline-variant bg-surface-container/80'"
                         @click="selected.includes('{{ $plan->fjp_id }}') ? selected = selected.filter(id => id !== '{{ $plan->fjp_id }}') : selected.push('{{ $plan->fjp_id }}')">
                        <label class="grid h-11 w-11 shrink-0 cursor-pointer place-items-center" @click.stop>
                            <input type="checkbox" name="fjp_ids[]" value="{{ $plan->fjp_id }}" x-model="selected"
                                   aria-label="Select {{ $plan->customer->business_name }} {{ $plan->visitLabel() }}" class="sr-only">
                            <span class="grid h-7 w-7 place-items-center rounded-full border-2 transition-colors"
                                  :class="selected.includes('{{ $plan->fjp_id }}') ? 'border-primary bg-primary text-on-primary' : 'border-outline bg-transparent text-transparent'"
                                  aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </span>
                        </label>
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
                           class="h-10 px-4 inline-flex items-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium shrink-0" @click.stop>
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
