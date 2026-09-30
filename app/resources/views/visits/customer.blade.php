<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $customer->business_name }}</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $customer->customer_type->value }}
                    @if ($customer->city) · {{ $customer->city }} @endif
                    @if ($customer->sales_region) · {{ $customer->sales_region }} @endif
                </p>
            </div>
            <a href="{{ route('visits.today') }}" class="m-chip">← Today</a>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="m-card p-4">
            <dl class="text-sm space-y-2">
                @if ($customer->address)
                    <div class="flex justify-between gap-4">
                        <dt class="text-on-surface-variant">Address</dt>
                        <dd class="text-right">{{ $customer->address }}{{ $customer->address2 ? ', '.$customer->address2 : '' }}</dd>
                    </div>
                @endif
                @if ($customer->contact_person)
                    <div class="flex justify-between gap-4">
                        <dt class="text-on-surface-variant">Contact</dt>
                        <dd>{{ $customer->contact_person }}{{ $customer->phone_number ? ' · '.$customer->phone_number : '' }}</dd>
                    </div>
                @endif
                @if ($customer->parent)
                    <div class="flex justify-between gap-4">
                        <dt class="text-on-surface-variant">Delivers for</dt>
                        <dd>{{ $customer->parent->business_name }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt class="text-on-surface-variant">Today</dt>
                    <dd>
                        @if ($todaySummary['check_in'])
                            In {{ $todaySummary['check_in'] }}
                            {{ $todaySummary['check_out'] ? '· Out '.$todaySummary['check_out'] : '' }}
                        @else
                            Not visited yet
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <div class="m-card p-4" x-data="visitQueue()">
            <h2 class="text-base font-semibold mb-2">Record attendance</h2>
            <p class="text-xs text-on-surface-variant mb-3">
                Works offline — if there is no connection the visit is stored on this
                device and synchronized automatically later.
            </p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" @click="checkIn('{{ $customer->customer_id }}')"
                        class="h-12 rounded-full bg-primary-container text-on-primary-container font-semibold">
                    Check in
                </button>
                <button type="button" @click="checkOut('{{ $customer->customer_id }}')"
                        class="h-12 rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                    Check out
                </button>
            </div>
            <input type="text" x-ref="remarks" placeholder="Remarks (optional)" maxlength="255"
                   class="mt-3 w-full rounded-m border-outline-variant bg-surface text-sm">
            <p x-show="gpsNote" x-text="gpsNote" class="text-xs text-on-surface-variant mt-2"></p>
            <p x-show="lastResult" x-text="lastResult" class="text-xs text-primary mt-2"></p>
        </div>

        @if ($inventory->isNotEmpty())
            <div class="m-card p-4">
                <h2 class="text-base font-semibold mb-2">Stock here (latest count basis)</h2>
                <div class="divide-y divide-outline-variant">
                    @foreach ($inventory as $row)
                        <div class="flex items-center justify-between py-2 text-sm">
                            <span class="min-w-0 truncate">{{ $row->product->product_description }}</span>
                            <span class="text-on-surface-variant shrink-0 ml-3">
                                {{ $row->unrestricted_qty + $row->restricted_qty }} {{ $row->basic_unit }}
                            </span>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-on-surface-variant mt-2">Full stock counts arrive in Phase 5.</p>
            </div>
        @endif

        <div class="m-card p-4">
            <h2 class="text-base font-semibold mb-2">Recent visits</h2>
            @if ($visits->isEmpty())
                <p class="text-sm text-on-surface-variant">No visits recorded yet.</p>
            @else
                <div class="divide-y divide-outline-variant">
                    @foreach ($visits as $visit)
                        <div class="py-2 flex items-center justify-between text-sm">
                            <span>{{ $visit->attendance_datetime->translatedFormat('D, j M · H:i') }}</span>
                            <span class="text-xs text-on-surface-variant">
                                @if ($visit->gps_latitude && $visit->gps_longitude)
                                    GPS ±{{ $visit->gps_accuracy ?? '?' }}m
                                @else
                                    no GPS
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
