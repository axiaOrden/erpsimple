<x-app-layout>
    <x-slot name="header">
        <h1>Good {{ now()->format('H') < 12 ? 'morning' : (now()->format('H') < 17 ? 'afternoon' : 'evening') }}, {{ explode(' ', $employee->employee_name ?? $user?->name ?? auth()->user()->name)[0] }} 👋</h1>
        <p class="text-sm text-on-surface-variant">{{ now()->translatedFormat('l, j F Y') }}</p>
    </x-slot>

    {{-- Quick actions --}}
    <div class="grid grid-cols-2 gap-3 mb-6">
        <a href="{{ route('visits.today') }}" class="m-card p-4 flex flex-col items-start gap-2 active:scale-[.98] transition-transform">
            <span class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container grid place-items-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </span>
            <span class="font-semibold">Check In</span>
            <span class="text-xs text-on-surface-variant">Record a visit</span>
        </a>
        <a href="{{ route('orders.create') }}" class="m-card p-4 flex flex-col items-start gap-2 active:scale-[.98] transition-transform">
            <span class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container grid place-items-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            </span>
            <span class="font-semibold">Create Order</span>
            <span class="text-xs text-on-surface-variant">New sales order</span>
        </a>
        <a href="{{ route('inventory.counts.create') }}" class="m-card p-4 flex flex-col items-start gap-2 active:scale-[.98] transition-transform">
            <span class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container grid place-items-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </span>
            <span class="font-semibold">Stock Count</span>
            <span class="text-xs text-on-surface-variant">Count & observe</span>
        </a>
        <a href="{{ route('visits.today') }}" class="m-card p-4 flex flex-col items-start gap-2 active:scale-[.98] transition-transform">
            <span class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container grid place-items-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </span>
            <span class="font-semibold">View Customers</span>
            <span class="text-xs text-on-surface-variant">Assigned to me</span>
        </a>
    </div>

    {{-- Today's journey plan --}}
    <section class="mb-6">
        <div class="flex items-center justify-between mb-2">
            <h2>Today's visits</h2>
            <span class="m-chip">{{ $todayVisits->count() }} planned</span>
        </div>

        @if ($todayVisits->isEmpty())
            <div class="m-card p-6 text-center text-on-surface-variant">
                <p class="font-medium text-on-surface">No visits planned for today</p>
                <p class="text-sm mt-1">Your Fixed Journey Plan decides which customers appear here.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($todayVisits as $visit)
                    <a href="{{ route('visits.customer', $visit->customer->customer_id) }}" class="m-card p-4 flex items-center gap-3">
                        <span class="w-10 h-10 rounded-full bg-secondary-container text-on-secondary-container grid place-items-center font-semibold">
                            {{ strtoupper(substr($visit->customer->business_name, 0, 2)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium truncate">{{ $visit->customer->business_name }}</p>
                            <p class="text-xs text-on-surface-variant">
                                {{ $visit->customer->customer_type->value }}
                                @if ($visit->customer->city) · {{ $visit->customer->city }} @endif
                            </p>
                        </div>
                        <span class="m-chip">{{ $visit->preferred_week ? 'RW'.$visit->preferred_week : 'Weekly' }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Assigned customers --}}
    <section class="mb-6">
        <div class="flex items-center justify-between mb-2">
            <h2>My customers</h2>
            <span class="m-chip">{{ $customers->count() }} assigned</span>
        </div>

        @if ($customers->isEmpty())
            <div class="m-card p-6 text-center text-on-surface-variant">
                <p class="font-medium text-on-surface">No customers assigned yet</p>
                <p class="text-sm mt-1">Your administrator assigns customers in master data.</p>
            </div>
        @else
            <div class="m-card divide-y divide-outline-variant">
                @foreach ($customers->take(8) as $customer)
                    <a href="{{ route('visits.customer', $customer->customer_id) }}" class="m-list-item">
                        <span class="w-9 h-9 rounded-full bg-surface-variant text-on-surface-variant grid place-items-center text-xs font-semibold">
                            {{ strtoupper(substr($customer->business_name, 0, 2)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium truncate">{{ $customer->business_name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $customer->customer_type->value }}</p>
                        </div>
                        <svg class="w-4 h-4 text-on-surface-variant" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                @endforeach
            </div>
            @if ($customers->count() > 8)
                <p class="text-xs text-on-surface-variant mt-2 text-center">+ {{ $customers->count() - 8 }} more</p>
            @endif
        @endif
    </section>

    {{-- Attendance today --}}
    @if ($attendanceToday->isNotEmpty())
        <section class="mb-6">
            <h2 class="mb-2">Attendance today</h2>
            <div class="m-card p-4">
                <div class="flex justify-between text-sm">
                    <span class="text-on-surface-variant">First check-in</span>
                    <span class="font-medium">{{ $checkIn?->attendance_datetime?->format('H:i') ?? '—' }}</span>
                </div>
                <div class="flex justify-between text-sm mt-2">
                    <span class="text-on-surface-variant">Last activity</span>
                    <span class="font-medium">{{ $checkOut?->attendance_datetime?->format('H:i') ?? '—' }}</span>
                </div>
            </div>
        </section>
    @endif
</x-app-layout>
