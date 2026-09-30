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

        @php
            $countType = match ($customer->customer_type->value) {
                'PRIMARY' => 'PRIMARY_OPERATIONAL',
                'VAN' => 'VAN_CLOSING',
                default => 'SECONDARY_OBSERVATION',
            };
        @endphp

        <div class="grid grid-cols-2 gap-2">
            <a href="{{ route('inventory.counts.create', ['customer' => $customer->customer_id, 'type' => $countType]) }}"
               class="h-12 inline-flex items-center justify-center rounded-full bg-primary text-on-primary font-semibold">
                Count inventory
            </a>
            @if ($customer->customer_type->value === 'SECONDARY')
                <a href="{{ route('orders.create', ['customer' => $customer->customer_id]) }}"
                   class="h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                    New order
                </a>
            @else
                <a href="{{ route('shipments.create', ['source' => $customer->customer_id]) }}"
                   class="h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                    Plan shipment
                </a>
            @endif
            <a href="{{ route('finance.statement', $customer) }}"
               class="col-span-2 h-11 inline-flex items-center justify-center rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Statement & outstanding
            </a>
        </div>

        @if ((float) $exposure['net_exposure'] > 0)
            <div class="rounded-m bg-error-container p-4 text-sm text-on-error-container">
                <p class="font-semibold">New exposure is blocked</p>
                <p class="mt-1">
                    Outstanding {{ number_format((float) $exposure['outstanding'], 2) }} NGN,
                    available credit {{ number_format((float) $exposure['available_credit'], 2) }} NGN,
                    net exposure {{ number_format((float) $exposure['net_exposure'], 2) }} NGN.
                    Existing dispatched goods remain available for POD and accountability. Ask a finance administrator to record settlement.
                </p>
            </div>
        @endif

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
                <p class="text-xs text-on-surface-variant mt-2">The latest submitted Primary count is the authoritative baseline.</p>
            </div>
        @endif

        <div class="m-card p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Recent counts</h2>
                <a href="{{ route('inventory.counts.index') }}" class="text-sm text-primary font-medium">All counts</a>
            </div>
            @forelse ($recentCounts as $count)
                <a href="{{ route('inventory.counts.show', $count) }}" class="mt-2 flex items-center justify-between gap-3 text-sm">
                    <span>{{ $count->count_no }} · {{ $count->items->count() }} line(s)</span>
                    <span class="m-chip">{{ $count->count_status->value }}</span>
                </a>
            @empty
                <p class="mt-2 text-sm text-on-surface-variant">No count recorded for this customer yet.</p>
            @endforelse
        </div>

        @if ($customer->customer_type->value === 'SECONDARY')
            <div class="m-card p-4">
                <h2 class="text-base font-semibold">Recent purchases</h2>
                <p class="text-xs text-on-surface-variant">Guidance only—nothing is added to a new order automatically.</p>
                @forelse ($recentPurchases as $purchase)
                    <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                        <span class="min-w-0 truncate">{{ $purchase['product'] }} · {{ $purchase['date'] }}</span>
                        <span class="shrink-0 tabular-nums">{{ $purchase['quantity'] }} {{ $purchase['unit'] }}</span>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-on-surface-variant">No confirmed purchase history yet.</p>
                @endforelse
            </div>
        @endif

        <div class="m-card p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Open orders</h2>
                <a href="{{ route('orders.index') }}" class="text-sm text-primary font-medium">All orders</a>
            </div>
            @forelse ($openOrders as $order)
                <a href="{{ route('orders.show', $order) }}" class="mt-2 flex items-center justify-between gap-3 text-sm">
                    <span>{{ $order->sales_order_no }} · {{ $order->items->count() }} line(s)</span>
                    <span class="m-chip">{{ $order->order_status->value }}</span>
                </a>
            @empty
                <p class="mt-2 text-sm text-on-surface-variant">No open orders for this customer.</p>
            @endforelse
        </div>

        @if ($activeDeliveries->isNotEmpty())
            <div class="m-card p-4">
                <h2 class="text-base font-semibold">Active deliveries</h2>
                @foreach ($activeDeliveries as $delivery)
                    <a href="{{ $delivery->delivery_status->value === 'SHIPPED' ? route('pod.show', $delivery) : route('deliveries.show', $delivery) }}"
                       class="mt-2 flex items-center justify-between gap-3 text-sm">
                        <span>{{ $delivery->delivery_no }} · {{ $delivery->items->count() }} line(s)</span>
                        <span class="m-chip">{{ $delivery->delivery_status->value }}</span>
                    </a>
                @endforeach
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
