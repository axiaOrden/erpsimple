<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate">{{ $greeting }}, {{ explode(' ', (string) ($employee->employee_name ?? auth()->user()->name))[0] }}</h1>
                <p class="text-xs text-on-surface-variant">
                    {{ now()->translatedFormat('l, j F Y') }}
                    @if ($employee)
                        · {{ $employee->employee_id }}
                    @endif
                </p>
            </div>
            @if ($employee)
                <span class="m-chip shrink-0">{{ $assignedCount }} customers</span>
            @endif
        </div>
    </x-slot>

    @if ($employee === null)
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No field employee profile</p>
            <p class="text-sm text-on-surface-variant mt-1">Your login is not linked to an employee record.</p>
        </div>
    @else

        {{-- Context tabs: same application, compact context switches --}}
        <div class="mb-4 -mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
            <a href="{{ route('primary.index') }}" class="m-chip shrink-0">Primary</a>
            <a href="{{ route('secondary.index') }}" class="m-chip shrink-0">Secondary</a>
            <a href="{{ route('visits.today') }}" class="m-chip shrink-0">FJP</a>
            <a href="{{ route('more.index') }}" class="m-chip shrink-0">More</a>
        </div>

        {{--
            Today's pipeline: ONE section per ORDER QUANTITY UNIT (CTN, PCS, …).

            Demand is never converted into one global display unit — a CTN order
            stays CTN demand and a PCS order stays PCS demand, so products whose
            practical selling unit differs are never mixed in one pipeline. Every
            quantity came from the authoritative SO lines / POD outcomes (see
            SalesLifecycleService::unitGroups), and the mutually exclusive
            buckets inside a unit conserve its demand exactly once.
        --}}
        <section class="m-card p-4 mb-4">
            @php
                $groups = $lifecycle['unit_groups'];
            @endphp

            <div class="mb-3 flex items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">Today's pipeline</h2>
                @if ($groups !== [])
                    <span class="text-[11px] text-on-surface-variant">by order unit</span>
                @endif
            </div>

            @if ($groups === [])
                <p class="text-sm text-on-surface-variant">
                    Nothing confirmed yet today. Create an order from a customer card and its quantity pipeline appears here.
                </p>
            @else
                <div class="space-y-3">
                    @foreach ($groups as $group)
                        @php
                            $unit = $group['unit'];
                            $bucket = fn (string $key): float => (float) $group[$key];
                        @endphp

                        <article class="rounded-m bg-surface-container-high/60 px-3 py-2.5"
                                 data-unit-group="{{ $unit }}"
                                 data-unit-total="{{ number_format((float) $group['total'], 0, '.', '') }}"
                                 data-unit-balanced="{{ $group['balanced'] ? '1' : '0' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-base font-semibold leading-tight tabular-nums">
                                        {{ number_format((float) $group['total'], 0) }}
                                        <span class="text-[11px] font-normal text-on-surface-variant">{{ $unit }} in play today</span>
                                    </p>
                                    <p class="text-[11px] text-on-surface-variant">
                                        {{ $group['lines'] }} line(s) · {{ $group['order_count'] }} order(s)
                                        @if ($group['free_lines'] > 0)
                                            · {{ $group['free_lines'] }} free
                                        @endif
                                    </p>
                                </div>
                                <span class="m-chip shrink-0">{{ $unit }}</span>
                            </div>

                            <x-lifecycle-bar
                                class="mt-2"
                                :open="$bucket('open')"
                                :allocated="$bucket('allocated')"
                                :unpaid="$bucket('delivered_unpaid')"
                                :paid="$bucket('delivered_paid')"
                                :rejected="$bucket('rejected')"
                                :unit="$unit" />

                            <details class="mt-1.5">
                                <summary class="cursor-pointer text-[11px] font-medium text-primary">
                                    Products &amp; orders in this unit
                                </summary>
                                <div class="mt-1 space-y-1 text-[11px] text-on-surface-variant">
                                    <p>{{ implode(', ', array_slice(array_values($group['products']), 0, 6)) }}</p>
                                    @foreach ($lifecycle['orders']->whereIn('sales_order_no', array_keys($group['orders'])) as $order)
                                        <a href="{{ route('orders.show', $order) }}" class="flex items-center justify-between gap-2 hover:text-on-surface">
                                            <span class="truncate">{{ $order->sales_order_no }} · {{ $order->soldToCustomer->business_name ?? $order->sold_to_customer_id }}</span>
                                            <span class="shrink-0">{{ $order->order_status->value }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>

                @if ($lifecycle['partial_invoices'] !== [])
                    <div class="mt-3 rounded-m bg-surface-variant/60 p-2.5 text-[11px] text-on-surface-variant">
                        <p class="font-medium text-on-surface">Partially settled invoices</p>
                        @foreach ($lifecycle['partial_invoices'] as $note)
                            <p>
                                {{ $note['invoice_no'] }}: settled {{ number_format((float) $note['settled'], 2) }},
                                credit {{ number_format((float) $note['credit'], 2) }},
                                outstanding {{ number_format((float) $note['outstanding'], 2) }}.
                            </p>
                        @endforeach
                        <p class="mt-1">
                            A partially settled multi-line invoice cannot be split deterministically, so its quantity
                            stays in "Delivered · unpaid" and the amounts carry the detail — no paid quantities are
                            invented.
                        </p>
                    </div>
                @endif
            @endif
        </section>

        {{-- Needs attention --}}
        <section class="mb-4">
            <h2 class="mb-2 text-base font-semibold">Needs attention</h2>
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('pod.index') }}" class="m-card p-3">
                    <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Awaiting POD</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $attention['awaiting_pod_orders'] }}</p>
                    <p class="text-[11px] text-on-surface-variant">order(s)</p>
                </a>
                <a href="{{ route('orders.index') }}" class="m-card p-3">
                    <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Ongoing orders</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $attention['ongoing_orders'] }}</p>
                    <p class="text-[11px] text-on-surface-variant">need action</p>
                </a>
                <a href="{{ route('finance.invoices.index') }}" class="m-card p-3">
                    <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Unpaid invoices</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $attention['unpaid_invoices'] }}</p>
                    <p class="text-[11px] text-on-surface-variant">{{ number_format((float) $attention['outstanding'], 2) }} outstanding</p>
                </a>
                <a href="{{ route('visits.today') }}" class="m-card p-3">
                    <p class="text-[11px] uppercase tracking-wide text-on-surface-variant">Visits left today</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $attention['visits_remaining'] }}</p>
                    <p class="text-[11px] text-on-surface-variant">of {{ $attention['visits_today'] }} planned</p>
                </a>
            </div>

            @if ($attention['blocked_customers']->isNotEmpty())
                <div class="mt-2 rounded-m bg-error-container/70 p-3 text-[11px] text-on-error-container">
                    <p class="font-semibold">Debt block — settle before new orders</p>
                    @foreach ($attention['blocked_customers']->take(3) as $blocked)
                        <p class="mt-0.5">
                            <a class="underline" href="{{ route('finance.statement', $blocked['customer']) }}">{{ $blocked['customer']->business_name }}</a>
                            · {{ number_format((float) $blocked['outstanding'], 2) }} outstanding
                        </p>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Today's FJP --}}
        <section class="mb-4">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-base font-semibold">Today's FJP</h2>
                <a href="{{ route('visits.today') }}" class="text-sm font-medium text-primary">Open FJP</a>
            </div>

            @forelse ($plan->take(5) as $visit)
                <a href="{{ route('visits.customer', $visit['customer']->customer_id) }}" class="m-card mb-2 flex items-center gap-3 p-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-secondary-container text-xs font-semibold text-on-secondary-container">
                        {{ strtoupper(substr($visit['customer']->business_name, 0, 2)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $visit['customer']->business_name }}</p>
                        <p class="text-[11px] text-on-surface-variant">
                            @if ($visit['check_in'])
                                In {{ $visit['check_in'] }}@if ($visit['check_out']) · Out {{ $visit['check_out'] }}@endif
                            @else
                                Not visited yet
                            @endif
                            @if ($visit['last_check_in_overall'])
                                · last {{ $visit['last_check_in_overall']->translatedFormat('d M') }}
                            @endif
                        </p>
                    </div>
                    <span class="m-chip shrink-0 {{ $visit['status'] === 'PENDING' ? '' : 'm-chip-active' }}">
                        {{ $visit['status'] === 'PENDING' ? 'PENDING' : ($visit['status'] === 'CHECKED_IN' ? 'IN VISIT' : 'DONE') }}
                    </span>
                </a>
            @empty
                <div class="m-card p-4 text-sm text-on-surface-variant">
                    No customers on today's plan. Use the Secondary tab to visit any assigned customer.
                </div>
            @endforelse
        </section>

        {{-- Primaries --}}
        <section class="mb-4">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-base font-semibold">Primaries</h2>
                <a href="{{ route('primary.index') }}" class="text-sm font-medium text-primary">All</a>
            </div>

            @forelse ($primaries as $row)
                <a href="{{ route('visits.customer', $row['customer']->customer_id) }}" class="m-card mb-2 flex items-center gap-3 p-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $row['customer']->business_name }}</p>
                        <p class="text-[11px] text-on-surface-variant">
                            Last check-in
                            {{ $row['last_check_in_overall']?->translatedFormat('d M H:i') ?? 'never' }}
                            · last count
                            {{ $row['last_stock_count']?->count_date?->translatedFormat('d M') ?? 'none' }}
                        </p>
                    </div>
                    <span class="m-chip shrink-0">{{ $row['status'] === 'PENDING' ? 'TODAY — NO' : 'DONE' }}</span>
                </a>
            @empty
                <div class="m-card p-4 text-sm text-on-surface-variant">No Primary customers assigned to you yet.</div>
            @endforelse
        </section>

        @if ($attendanceToday->isNotEmpty())
            <section class="mb-4">
                <h2 class="mb-2 text-base font-semibold">Attendance today</h2>
                <div class="m-card p-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">First check-in</span>
                        <span class="font-medium">{{ $attendanceToday->first()->attendance_datetime->format('H:i') }}</span>
                    </div>
                    <div class="mt-1 flex justify-between">
                        <span class="text-on-surface-variant">Last activity</span>
                        <span class="font-medium">{{ $attendanceToday->last()->attendance_datetime->format('H:i') }}</span>
                    </div>
                    <div class="mt-1 flex justify-between">
                        <span class="text-on-surface-variant">Check-ins</span>
                        <span class="font-medium">{{ $attendanceToday->count() }}</span>
                    </div>
                </div>
            </section>
        @endif
    @endif
</x-app-layout>
