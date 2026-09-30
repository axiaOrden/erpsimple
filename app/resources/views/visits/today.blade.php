<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1>Today's visits</h1>
                <p class="text-sm text-on-surface-variant">{{ today()->translatedFormat('l, j F Y') }}</p>
            </div>
            <span class="m-chip">{{ $visits->count() }} planned</span>
        </div>
    </x-slot>

    @if ($employee === null)
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No field employee profile</p>
            <p class="text-sm text-on-surface-variant mt-1">Your login is not linked to an employee record.</p>
        </div>
    @else
        <div x-data="visitQueue()" class="space-y-4">

            {{-- Quick check-in for assigned customers not on today's plan --}}
            <div class="m-card p-4">
                <label for="quick-customer" class="block text-xs font-medium text-on-surface-variant mb-1">
                    Record a visit for any assigned customer
                </label>
                <select id="quick-customer" x-ref="quickCustomer"
                        class="w-full rounded-m border-outline-variant bg-surface">
                    <option value="">Choose customer…</option>
                    @foreach ($assignedCustomers as $c)
                        <option value="{{ $c->customer_id }}">{{ $c->business_name }} ({{ $c->customer_type->value }})</option>
                    @endforeach
                </select>
                <button type="button"
                        @click="quickCheckIn($refs.quickCustomer.value)"
                        class="mt-3 w-full h-11 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                    Check in
                </button>
                <p x-show="gpsNote" x-text="gpsNote" class="text-xs text-on-surface-variant mt-2"></p>
            </div>

            {{-- Today's planned visits --}}
            @forelse ($visits as $visit)
                <div class="m-card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('visits.customer', $visit['customer']->customer_id) }}"
                               class="font-semibold text-primary truncate block">
                                {{ $visit['customer']->business_name }}
                            </a>
                            <p class="text-xs text-on-surface-variant">
                                {{ $visit['customer']->customer_type->value }}
                                @if ($visit['customer']->city || $visit['customer']->address)
                                    · {{ $visit['customer']->address ?? '' }} {{ $visit['customer']->city ?? '' }}
                                @endif
                            </p>
                        </div>
                        <span class="m-chip shrink-0 {{ $visit['status'] === 'PENDING' ? '' : 'm-chip-active' }}">
                            {{ $visit['status'] === 'PENDING' ? 'PENDING' : ($visit['status'] === 'CHECKED_IN' ? 'IN VISIT' : 'DONE') }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between mt-3 text-sm">
                        <span class="text-on-surface-variant">
                            @if ($visit['check_in']) In {{ $visit['check_in'] }} @endif
                            @if ($visit['check_out']) · Out {{ $visit['check_out'] }} @endif
                            @if (! $visit['check_in']) Not yet visited @endif
                        </span>
                        <span class="text-xs text-on-surface-variant">{{ $visit['records'] }} ping(s)</span>
                    </div>

                    <div class="flex gap-2 mt-3">
                        <button type="button" @click="checkIn('{{ $visit['customer']->customer_id }}')"
                                class="flex-1 h-11 rounded-full bg-primary-container text-on-primary-container font-medium">
                            {{ $visit['check_in'] ? 'Check in again' : 'Check in' }}
                        </button>
                        @if ($visit['check_in'] && ! $visit['check_out'])
                            <button type="button" @click="checkOut('{{ $visit['customer']->customer_id }}')"
                                    class="flex-1 h-11 rounded-full bg-secondary-container text-on-secondary-container font-medium">
                                Check out
                            </button>
                        @endif
                        <a href="{{ route('visits.customer', $visit['customer']->customer_id) }}"
                           class="h-11 px-4 inline-flex items-center rounded-full bg-surface-variant text-on-surface-variant font-medium">
                            Details
                        </a>
                    </div>
                </div>
            @empty
                <div class="m-card p-8 text-center">
                    <p class="font-medium text-on-surface">No visits planned for today</p>
                    <p class="text-sm text-on-surface-variant mt-1">
                        Your Fixed Journey Plan decides which customers appear here.
                        You can still check in to any assigned customer above.
                    </p>
                </div>
            @endforelse

            {{-- Pending local operations --}}
            <div class="m-card p-4" x-show="pendingCount > 0" x-transition>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium">
                            <span x-text="pendingCount"></span> visit(s) saved on this device
                        </p>
                        <p class="text-xs text-on-surface-variant">Waiting to synchronize — do not close the app before they clear.</p>
                    </div>
                    <button type="button" @click="syncNow()"
                            class="h-10 px-4 rounded-full bg-tertiary-container text-on-tertiary-container text-sm font-medium">
                        Retry now
                    </button>
                </div>
                <ul class="mt-3 space-y-1 text-xs text-on-surface-variant">
                    <template x-for="op in pendingOps" :key="op.uuid">
                        <li>
                            <span x-text="op.type === 'CHECK_IN' ? 'Check-in' : 'Check-out'"></span>
                            · <span x-text="op.customerId"></span>
                            · <span x-text="op.state"></span>
                            <span x-show="op.error" x-text="op.error" class="text-error"></span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        @push('modals')
            <div x-data="visitQueue()" x-cloak class="hidden"></div>
        @endpush
    @endif
</x-app-layout>
