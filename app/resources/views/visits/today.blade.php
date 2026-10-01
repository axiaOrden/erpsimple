<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>FJP</h1>
                <p class="text-xs text-on-surface-variant">
                    {{ today()->translatedFormat('l, j F Y') }}
                    @if ($rotationWeek)
                        · rotation week {{ $rotationWeek }}
                    @endif
                </p>
            </div>
            <span class="m-chip shrink-0">{{ $planTotal }} planned</span>
        </div>
    </x-slot>

    @if ($employee === null)
        <div class="m-card p-8 text-center">
            <p class="font-medium">No field employee profile</p>
            <p class="mt-1 text-sm text-on-surface-variant">Your login is not linked to an employee record.</p>
        </div>
    @else
        <div x-data="visitQueue()" class="space-y-3">

            <form method="GET" action="{{ route('visits.today') }}" class="flex gap-2">
                <input type="search" name="q" value="{{ $search }}" placeholder="Search today's plan or any assigned customer…"
                       class="w-full rounded-full border-outline-variant bg-surface-container px-4 text-sm">
                <button type="submit" class="h-11 shrink-0 rounded-full bg-secondary-container px-4 text-sm font-semibold text-on-secondary-container">
                    Search
                </button>
            </form>

            @forelse ($visits as $visit)
                @php $customer = $visit['customer']; @endphp
                <div class="m-card p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('visits.customer', $customer->customer_id) }}" class="block truncate font-semibold text-primary">
                                {{ $customer->business_name }}
                            </a>
                            <p class="text-[11px] text-on-surface-variant">
                                {{ $customer->customer_type->value }}
                                @if ($customer->city) · {{ $customer->city }} @endif
                            </p>
                        </div>
                        <span class="m-chip shrink-0 {{ $visit['status'] === 'PENDING' ? '' : 'm-chip-active' }}">
                            {{ $visit['status'] === 'PENDING' ? 'PENDING' : ($visit['status'] === 'CHECKED_IN' ? 'IN VISIT' : 'DONE') }}
                        </span>
                    </div>

                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px]">
                        <div class="flex justify-between gap-2">
                            <dt class="text-on-surface-variant">First check-in</dt>
                            <dd class="font-medium">{{ $visit['first_check_in']?->format('H:i') ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-on-surface-variant">Last check-in</dt>
                            <dd class="font-medium">{{ $visit['last_check_in']?->format('H:i') ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-on-surface-variant">Last stock count</dt>
                            <dd class="font-medium">{{ $visit['last_stock_count']?->count_date?->translatedFormat('d M') ?? 'none' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-on-surface-variant">Preferred visit</dt>
                            <dd class="font-medium">
                                @if (isset($visit['plan']) && $visit['plan'])
                                    <x-fjp-chip :week="$visit['plan']->preferred_week" :day="$visit['plan']->preferred_day" />
                                @else
                                    served via search
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-3 flex gap-2">
                        <button type="button" @click="checkIn('{{ $customer->customer_id }}')"
                                class="h-11 flex-1 rounded-full bg-primary-container text-sm font-medium text-on-primary-container">
                            {{ $visit['first_check_in'] ? 'Check in again' : 'Check in' }}
                        </button>
                        @if ($visit['first_check_in'] && $visit['status'] === 'CHECKED_IN')
                            <button type="button" @click="checkOut('{{ $customer->customer_id }}')"
                                    class="h-11 flex-1 rounded-full bg-secondary-container text-sm font-medium text-on-secondary-container">
                                Check out
                            </button>
                        @endif
                        <a href="{{ route('visits.customer', $customer->customer_id) }}"
                           class="inline-flex h-11 items-center rounded-full bg-surface-variant px-4 text-sm font-medium text-on-surface-variant">
                            Open
                        </a>
                    </div>
                </div>
            @empty
                <div class="m-card p-6 text-center">
                    <p class="font-medium">No customers on today's plan</p>
                    <p class="mt-1 text-sm text-on-surface-variant">
                        Your Fixed Journey Plan decides who appears here. You can still check in to any assigned customer below.
                    </p>
                </div>
            @endforelse

            @if ($paginator && $paginator->hasMorePages())
                <a href="{{ route('visits.today', array_merge(request()->query(), ['per_page' => $nextPerPage, 'page' => 1])) }}"
                   class="inline-flex h-11 w-full items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary">
                    Load more ({{ $planTotal }} planned)
                </a>
            @endif

            @if ($searchMatches->isNotEmpty())
                <section class="m-card p-3">
                    <h2 class="mb-2 text-sm font-semibold">Other assigned customers matching “{{ $search }}”</h2>
                    <div class="divide-y divide-outline-variant">
                        @foreach ($searchMatches as $customer)
                            <div class="flex items-center gap-2 py-2">
                                <a href="{{ route('visits.customer', $customer->customer_id) }}" class="min-w-0 flex-1 truncate text-sm text-primary">
                                    {{ $customer->business_name }}
                                </a>
                                <button type="button" @click="checkIn('{{ $customer->customer_id }}')"
                                        class="h-9 shrink-0 rounded-full bg-primary-container px-3 text-xs font-medium text-on-primary-container">
                                    Check in
                                </button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Manual check-in for any assigned customer --}}
            <details class="m-card p-3">
                <summary class="cursor-pointer text-sm font-semibold">Record a visit for any assigned customer</summary>
                <div class="mt-2">
                    <select x-ref="quickCustomer" class="w-full rounded-m border-outline-variant bg-surface text-sm">
                        <option value="">Choose customer…</option>
                        @foreach ($assignedCustomers as $c)
                            <option value="{{ $c->customer_id }}">{{ $c->business_name }} ({{ $c->customer_type->value }})</option>
                        @endforeach
                    </select>
                    <button type="button" @click="quickCheckIn($refs.quickCustomer.value)"
                            class="mt-2 h-11 w-full rounded-full bg-primary font-semibold text-on-primary">
                        Check in
                    </button>
                </div>
            </details>

            <p x-cloak x-show="gpsNote" x-text="gpsNote" class="text-xs text-on-surface-variant"></p>
            <p x-cloak x-show="lastResult" x-text="lastResult" class="text-xs text-primary"></p>

            {{-- Pending local operations --}}
            <div class="m-card p-3" x-cloak x-show="pendingCount > 0" x-transition>
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium"><span x-text="pendingCount"></span> visit(s) saved on this device</p>
                        <p class="text-[11px] text-on-surface-variant">Waiting to synchronize — do not close the app before they clear.</p>
                    </div>
                    <button type="button" @click="syncNow()"
                            class="h-10 shrink-0 rounded-full bg-tertiary-container px-4 text-xs font-medium text-on-tertiary-container">
                        Retry now
                    </button>
                </div>
                <ul class="mt-2 space-y-1 text-[11px] text-on-surface-variant">
                    <template x-for="op in pendingOps" :key="op.uuid">
                        <li>
                            <span x-text="op.type === 'CHECK_IN' ? 'Check-in' : 'Check-out'"></span>
                            · <span x-text="op.customerId"></span>
                            · <span x-text="op.state"></span>
                            <span x-cloak x-show="op.error" x-text="op.error" class="text-error"></span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    @endif
</x-app-layout>
