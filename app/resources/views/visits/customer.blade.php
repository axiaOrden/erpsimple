<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate">{{ $customer->business_name }}</h1>
                <p class="text-xs text-on-surface-variant">
                    {{ $customer->customer_type->value }}
                    @if ($customer->city) · {{ $customer->city }} @endif
                    @if ($customer->sales_region) · {{ $customer->sales_region }} @endif
                    @if ($customer->phone_number) · {{ $customer->phone_number }} @endif
                </p>
            </div>
            <a href="{{ route('visits.today') }}" class="m-chip shrink-0">← FJP</a>
        </div>
    </x-slot>

    @php
        $latestInvoice = $invoices->firstWhere(fn ($i) => (float) $i->outstandingAmount() > 0) ?? $invoices->first();
        $isSecondary = $customer->customer_type->value === 'SECONDARY';
        $checkedIn = $todaySummary['check_in'] !== null;

        // Customer details: only fields that actually carry a value are
        // rendered, so the profile never becomes a wall of empty rows.
        $cityState = trim(implode(', ', array_filter([$customer->city, $customer->state])));
        $addressLine = trim(implode(', ', array_filter([$customer->address, $customer->address2])));
        $regionMarket = trim(implode(' · ', array_filter([$customer->salesRegion?->description, $customer->market])));

        $customerDetails = array_filter([
            'Contact person' => $customer->contact_person,
            'Phone' => $customer->phone_number,
            'Email' => $customer->email_address,
            'Address' => $addressLine,
            'City / State' => $cityState,
            'Postal code' => $customer->postal_code,
            'Country' => $customer->country,
            'Region / Market' => $regionMarket,
        ], fn ($value) => $value !== null && trim((string) $value) !== '');

        $hasCoordinates = $customer->gps_latitude !== null && $customer->gps_longitude !== null;
        $mapLink = $hasCoordinates
            ? 'https://www.openstreetmap.org/?mlat='.$customer->gps_latitude.'&mlon='.$customer->gps_longitude.'#map='.((int) config('erp.map.zoom', 16)).'/'.$customer->gps_latitude.'/'.$customer->gps_longitude
            : null;
    @endphp

    <div class="space-y-3">
        {{-- Customer profile: who they are and how to reach them — compact. --}}
        <div class="m-card p-3">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold">Customer details</h2>
                    <p class="truncate text-[11px] text-on-surface-variant">{{ $customer->customer_id }}</p>
                </div>
                <span class="m-chip shrink-0">{{ $customer->customer_type->value }}</span>
            </div>

            <dl class="mt-2 divide-y divide-outline-variant text-sm">
                @foreach ($customerDetails as $label => $value)
                    <div class="flex items-start justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                        <dt class="shrink-0 text-on-surface-variant">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-right">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-2 flex flex-wrap gap-2">
                @if ($hasCoordinates)
                    <a href="#location" class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">
                        Show on map
                    </a>
                    <a href="{{ $mapLink }}" target="_blank" rel="noopener"
                       class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">
                        Open in maps
                    </a>
                @else
                    <span class="text-[11px] text-on-surface-variant">No registered location for this customer yet.</span>
                @endif
                @if ($customer->phone_number)
                    <a href="tel:{{ $customer->phone_number }}"
                       class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">
                        Call
                    </a>
                @endif
            </div>
        </div>
        {{-- Context bar: everything the employee needs without leaving the customer --}}
        <div class="m-card p-3">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-medium">
                    @if ($checkedIn)
                        Checked in {{ $todaySummary['check_in'] }}
                        @if ($todaySummary['check_out'])
                            · last activity {{ $todaySummary['check_out'] }}
                        @endif
                    @else
                        Not checked in today
                    @endif
                </p>
                <span class="m-chip shrink-0 {{ $checkedIn ? 'm-chip-active' : '' }}">
                    {{ $checkedIn ? ($todaySummary['check_out'] ? 'VISITED' : 'IN VISIT') : 'PENDING' }}
                </span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <a href="{{ route('inventory.counts.create', ['customer' => $customer->customer_id, 'type' => $isSecondary ? 'SECONDARY_OBSERVATION' : ($customer->customer_type->value === 'VAN' ? 'VAN_CLOSING' : 'PRIMARY_OPERATIONAL')]) }}"
                   class="inline-flex h-12 items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary">
                    Inventory count
                </a>
                @if ($isSecondary)
                    <a href="{{ route('orders.create', ['customer' => $customer->customer_id]) }}"
                       class="inline-flex h-12 items-center justify-center rounded-full bg-secondary-container text-sm font-semibold text-on-secondary-container">
                        Create new order
                    </a>
                @else
                    <a href="{{ route('shipments.create', ['source' => $customer->customer_id]) }}"
                       class="inline-flex h-12 items-center justify-center rounded-full bg-secondary-container text-sm font-semibold text-on-secondary-container">
                        Plan shipment
                    </a>
                @endif

                @if ($latestInvoice && (float) $latestInvoice->outstandingAmount() > 0)
                    <a href="{{ route('payments.record.create', $latestInvoice) }}"
                       class="col-span-2 inline-flex h-11 items-center justify-center rounded-full bg-tertiary-container text-sm font-semibold text-on-tertiary-container">
                        Record payment · {{ number_format((float) $latestInvoice->outstandingAmount(), 2) }} due on {{ $latestInvoice->invoice_no }}
                    </a>
                @endif

                <a href="{{ route('finance.statement', $customer) }}"
                   class="col-span-2 inline-flex h-11 items-center justify-center rounded-full bg-surface-variant text-sm font-medium text-on-surface-variant">
                    Statement &amp; outstanding
                </a>
            </div>
        </div>

        @if ((float) $exposure['net_exposure'] > 0)
            <div class="rounded-m bg-error-container p-3 text-sm text-on-error-container">
                <p class="font-semibold">New exposure is blocked</p>
                <p class="mt-1 text-xs">
                    Outstanding {{ number_format((float) $exposure['outstanding'], 2) }},
                    available credit {{ number_format((float) $exposure['available_credit'], 2) }},
                    net exposure {{ number_format((float) $exposure['net_exposure'], 2) }}.
                    Goods already dispatched remain available for POD and accountability.
                </p>
            </div>
        @endif

        {{-- Attendance --}}
        <div class="m-card p-3" x-data="visitQueue()">
            <h2 class="text-sm font-semibold">Attendance</h2>
            <p class="mt-1 text-[11px] text-on-surface-variant">
                Works offline — without a connection the visit is stored on this device and synchronized later.
            </p>
            <div class="mt-2 grid grid-cols-2 gap-2">
                <button type="button" @click="checkIn('{{ $customer->customer_id }}')"
                        class="h-11 rounded-full bg-primary-container font-semibold text-on-primary-container">
                    Check in
                </button>
                <button type="button" @click="checkOut('{{ $customer->customer_id }}')"
                        class="h-11 rounded-full bg-secondary-container font-semibold text-on-secondary-container">
                    Check out
                </button>
            </div>
            <p x-cloak x-show="gpsNote" x-text="gpsNote" class="mt-2 text-[11px] text-on-surface-variant"></p>
            <p x-cloak x-show="lastResult" x-text="lastResult" class="mt-2 text-[11px] text-primary"></p>
        </div>

        {{-- Preferred visit: compact chips, scoped to THIS employee's company --}}
        @if ($plan->isNotEmpty())
            <div class="m-card p-3">
                <h2 class="text-sm font-semibold">Preferred visit (FJP)</h2>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($plan as $entry)
                        <x-fjp-chip :week="$entry->preferred_week" :day="$entry->preferred_day" />
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Open orders with the derived lifecycle category --}}
        <div class="m-card p-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Open orders</h2>
                <a href="{{ route('orders.index', ['from' => today()->toDateString(), 'to' => today()->toDateString(), 'q' => $customer->business_name]) }}"
                   class="text-xs font-medium text-primary">All orders</a>
            </div>

            @forelse ($openOrders as $order)
                @php $analysis = $orderAnalysis[$order->sales_order_no] ?? null; @endphp
                <a href="{{ route('orders.show', $order) }}" class="mt-2 flex items-center justify-between gap-2 text-sm">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ $order->sales_order_no }}</p>
                        <p class="truncate text-[11px] text-on-surface-variant">
                            {{ $analysis && $analysis['reasons'] !== [] ? implode(' · ', $analysis['reasons']) : $order->order_status->value }}
                        </p>
                    </div>
                    <span class="shrink-0 text-right">
                        <span class="block font-semibold tabular-nums">{{ number_format((float) $order->net_amount, 2) }}</span>
                        <span class="m-chip {{ ($analysis['state'] ?? '') === 'ONGOING' ? 'm-chip-active' : '' }}">
                            {{ $analysis['state'] ?? $order->order_status->value }}
                        </span>
                    </span>
                </a>
            @empty
                <p class="mt-2 text-sm text-on-surface-variant">No open orders.</p>
            @endforelse
        </div>

        {{-- Deliveries awaiting acceptance --}}
        @if ($activeDeliveries->isNotEmpty())
            <div class="m-card p-3">
                <h2 class="text-sm font-semibold">Deliveries</h2>
                @foreach ($activeDeliveries as $delivery)
                    <a href="{{ $delivery->delivery_status->value === 'SHIPPED' ? route('pod.show', $delivery) : route('deliveries.show', $delivery) }}"
                       class="mt-2 flex items-center justify-between gap-2 text-sm">
                        <span class="truncate">{{ $delivery->delivery_no }} · {{ $delivery->items->count() }} line(s)</span>
                        <span class="m-chip shrink-0 {{ $delivery->delivery_status->value === 'SHIPPED' ? 'm-chip-active' : '' }}">
                            {{ $delivery->delivery_status->value === 'SHIPPED' ? 'POD →' : $delivery->delivery_status->value }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($shipments->isNotEmpty())
            <div class="m-card p-3">
                <h2 class="text-sm font-semibold">Shipments in flight</h2>
                @foreach ($shipments as $shipment)
                    <a href="{{ route('shipments.show', $shipment) }}" class="mt-2 flex items-center justify-between gap-2 text-sm">
                        <span class="truncate">{{ $shipment->shipment_no }}</span>
                        <span class="m-chip shrink-0">{{ $shipment->shipment_status->value }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Invoices: QR, share, record payment --}}
        @if ($invoices->isNotEmpty())
            <div class="m-card p-3">
                <h2 class="text-sm font-semibold">Invoices</h2>
                @foreach ($invoices as $invoice)
                    <div class="mt-2 border-t border-outline-variant pt-2 first:border-0 first:pt-0">
                        <div class="flex items-start justify-between gap-2 text-sm">
                            <div class="min-w-0">
                                <a href="{{ route('finance.invoices.show', $invoice) }}" class="block truncate font-medium text-primary">
                                    {{ $invoice->invoice_no }}
                                </a>
                                <p class="text-[11px] text-on-surface-variant">
                                    {{ $invoice->invoice_date?->translatedFormat('d M Y') }} ·
                                    {{ number_format((float) $invoice->invoice_amount, 2) }} {{ $invoice->currency }}
                                    @if ((float) $invoice->outstandingAmount() > 0)
                                        · {{ number_format((float) $invoice->outstandingAmount(), 2) }} outstanding
                                    @endif
                                </p>
                            </div>
                            <span class="m-chip shrink-0 {{ $invoice->payment_status->value === 'PAID' ? 'm-chip-active' : ($invoice->payment_status->value === 'UNPAID' ? 'm-chip-error' : '') }}">
                                {{ $invoice->payment_status->value }}
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($invoice->public_token)
                                <a href="{{ route('invoice.public', $invoice->public_token) }}" target="_blank" rel="noopener"
                                   class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">
                                    Show QR / share
                                </a>
                                <a href="{{ route('invoice.public.image', ['token' => $invoice->public_token, 'download' => 1]) }}"
                                   class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">
                                    Invoice JPG
                                </a>
                            @endif
                            @if ((float) $invoice->outstandingAmount() > 0)
                                <a href="{{ route('payments.record.create', $invoice) }}"
                                   class="inline-flex h-9 items-center rounded-full bg-primary px-3 text-[11px] font-semibold text-on-primary">
                                    Record payment
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($isSecondary)
            <div class="m-card p-3">
                <h2 class="text-sm font-semibold">Recent purchases</h2>
                <p class="text-[11px] text-on-surface-variant">Guidance only — nothing is added to a new order automatically.</p>
                @forelse ($recentPurchases as $purchase)
                    <div class="mt-1.5 flex items-center justify-between gap-2 text-sm">
                        <span class="min-w-0 truncate">{{ $purchase['product'] }} · {{ $purchase['date'] }}</span>
                        <span class="shrink-0 tabular-nums">{{ $purchase['quantity'] }} {{ $purchase['unit'] }}</span>
                    </div>
                @empty
                    <p class="mt-1.5 text-sm text-on-surface-variant">No confirmed purchase history yet.</p>
                @endforelse
            </div>
        @endif

        @if ($inventory->isNotEmpty())
            <div class="m-card p-3">
                <h2 class="text-sm font-semibold">Stock here (latest count basis)</h2>
                @foreach ($inventory as $row)
                    <div class="mt-1.5 flex items-center justify-between gap-2 text-sm">
                        <span class="min-w-0 truncate">{{ $row->product->product_description }}</span>
                        <span class="shrink-0 tabular-nums text-on-surface-variant">
                            {{ $row->unrestricted_qty + $row->restricted_qty }} {{ $row->basic_unit }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="m-card p-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Recent stock counts</h2>
                <a href="{{ route('inventory.counts.index') }}" class="text-xs font-medium text-primary">All counts</a>
            </div>
            @forelse ($recentCounts as $count)
                <a href="{{ route('inventory.counts.show', $count) }}" class="mt-1.5 flex items-center justify-between gap-2 text-sm">
                    <span>{{ $count->count_no }} · {{ $count->items->count() }} line(s)</span>
                    <span class="m-chip">{{ $count->count_status->value }}</span>
                </a>
            @empty
                <p class="mt-1.5 text-sm text-on-surface-variant">No count recorded yet.</p>
            @endforelse
        </div>

        <div class="m-card p-3">
            <h2 class="text-sm font-semibold">Recent visits</h2>
            @if ($visits->isEmpty())
                <p class="mt-1.5 text-sm text-on-surface-variant">No visits recorded yet.</p>
            @else
                <div class="divide-y divide-outline-variant">
                    @foreach ($visits as $visit)
                        <div class="flex items-center justify-between gap-2 py-1.5 text-sm">
                            <span>{{ $visit->attendance_datetime->translatedFormat('D, j M · H:i') }}</span>
                            <span class="text-[11px] text-on-surface-variant">
                                {{ $visit->gps_latitude && $visit->gps_longitude ? 'GPS ±'.($visit->gps_accuracy ?? '?').'m' : 'no GPS' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Location: rendered only when the customer actually has coordinates. --}}
        @if ($hasCoordinates)
            <div class="m-card p-3 scroll-mt-16" id="location">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Location</h2>
                    <a href="{{ $mapLink }}" target="_blank" rel="noopener"
                       class="text-[11px] font-medium text-primary">Open in maps</a>
                </div>
                <p class="mt-1 text-[11px] text-on-surface-variant">
                    Registered location: {{ $customer->gps_latitude }}, {{ $customer->gps_longitude }}
                    @if ($addressLine !== '') · {{ $addressLine }} @endif
                </p>
                @php
                    $locationConfig = [
                        'mode' => 'view',
                        'tileUrl' => config('erp.map.tile_url'),
                        'attribution' => config('erp.map.attribution'),
                        'zoom' => (int) config('erp.map.zoom', 16),
                        'lat' => (float) $customer->gps_latitude,
                        'lng' => (float) $customer->gps_longitude,
                    ];
                @endphp
                <div x-data='customerRegistration(@json($locationConfig))' class="mt-2">
                    <div x-ref="map" class="h-52 w-full overflow-hidden rounded-m bg-surface-variant" role="img"
                         aria-label="Customer registered location"></div>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
