<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $shipment->shipment_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    from {{ $shipment->sourceCustomer->business_name ?? $shipment->source_customer_id }}
                    @if ($shipment->vehicle_reference) · {{ $shipment->vehicle_reference }} @endif
                </p>
            </div>
            <span class="m-chip {{ $shipment->shipment_status->value === 'IN_TRANSIT' ? 'm-chip-active' : '' }}">
                {{ $shipment->shipment_status->value }}
            </span>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 bg-primary-container text-on-primary-container rounded-m p-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="space-y-4 max-w-2xl">
        <div class="m-card p-4 text-sm text-on-surface-variant">
            <div class="flex justify-between"><span>Created</span><span>{{ $shipment->created_on?->format('d M Y H:i') }} · {{ $shipment->created_by }}</span></div>
            <div class="flex justify-between mt-1"><span>Ready</span><span>{{ $shipment->ready_on?->format('d M Y H:i') ?? '—' }}</span></div>
            <div class="flex justify-between mt-1"><span>Started</span><span>{{ $shipment->started_on?->format('d M Y H:i') ?? '—' }}</span></div>
            <div class="flex justify-between mt-1"><span>Carrier</span><span>{{ $shipment->carrier->employee_name ?? $shipment->carrier_employee_id ?? '—' }}</span></div>
            @if ($shipment->remarks)
                <p class="mt-2 text-on-surface">{{ $shipment->remarks }}</p>
            @endif
        </div>

        @if ($shipment->shipment_status->value === 'DRAFT' && $eligible->isNotEmpty())
            <div class="m-card p-4 space-y-3">
                <p class="text-sm font-medium">Attach ALLOCATED deliveries from this source</p>
                @foreach ($eligible as $candidate)
                    <div class="flex items-center justify-between gap-3 text-sm border-t border-outline-variant pt-2 first:border-0 first:pt-0">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $candidate->delivery_no }}</p>
                            <p class="text-xs text-on-surface-variant truncate">
                                SO {{ $candidate->sales_order_no }} · {{ $candidate->items->count() }} line(s)
                                @if ($candidate->items->contains(fn ($i) => $i->is_free_item)) · <span class="m-chip m-chip-active">FREE</span> @endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('shipments.attach', $shipment) }}">
                            @csrf
                            <input type="hidden" name="delivery_no" value="{{ $candidate->delivery_no }}">
                            <button class="m-chip m-chip-active">Attach</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="m-card divide-y divide-outline-variant text-sm">
            <p class="p-4 pb-2 font-medium">Attached deliveries</p>
            @forelse ($shipment->deliveries as $delivery)
                <div class="p-4 space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{ $delivery->delivery_no }}
                                <span class="text-xs text-on-surface-variant">{{ $delivery->delivery_status->value }}</span>
                            </p>
                            <p class="text-xs text-on-surface-variant">
                                SO {{ $delivery->sales_order_no }}
                                · to {{ $delivery->salesOrder->soldToCustomer->business_name ?? $delivery->customer_id }}
                            </p>
                        </div>
                        @if ($shipment->shipment_status->value === 'DRAFT')
                            <form method="POST" action="{{ route('shipments.detach', $shipment) }}">
                                @csrf
                                <input type="hidden" name="delivery_no" value="{{ $delivery->delivery_no }}">
                                <button class="m-chip m-chip-error">Detach</button>
                            </form>
                        @endif
                    </div>
                    @foreach ($delivery->items as $item)
                        <p class="text-xs text-on-surface-variant pl-3">
                            {{ $item->product->product_description ?? $item->product_id }}
                            — {{ \App\Services\Decimal::trimZeros((string) $item->allocated_qty) }} {{ $item->delivery_unit }}
                            @if ($item->is_free_item) · FREE @endif
                        </p>
                    @endforeach
                </div>
            @empty
                <p class="p-4 text-on-surface-variant">No deliveries attached yet.</p>
            @endforelse
        </div>

        @if ($issuePreview->isNotEmpty())
            <div class="m-card p-4 text-sm">
                <p class="font-medium mb-2">Goods-issue preview (basic units)</p>
                @foreach ($issuePreview as $line)
                    <div class="flex justify-between">
                        <span>{{ $line['product'] }} @if ($line['has_free_items'])<span class="m-chip">FREE included</span>@endif</span>
                        <span class="tabular-nums">−{{ $line['basic_qty'] }} {{ $line['basic_unit'] }}</span>
                    </div>
                @endforeach
                <p class="text-xs text-on-surface-variant mt-2">START decrements restricted stock by these totals and records one GOODS_ISSUE per delivery item. Irreversible.</p>
            </div>
        @endif

        @if ($shipment->shipment_status->value === 'DRAFT')
            <form method="POST" action="{{ route('shipments.ready', $shipment) }}">
                @csrf
                <button class="w-full h-12 rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                    Mark READY (lock composition)
                </button>
            </form>
        @elseif ($shipment->shipment_status->value === 'READY')
            <form method="POST" action="{{ route('shipments.back-to-draft', $shipment) }}">
                @csrf
                <button class="w-full h-12 rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                    Back to DRAFT (unlock composition)
                </button>
            </form>

            <form method="POST" action="{{ route('shipments.start', $shipment) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">
                <button type="submit"
                        onclick="return confirm('START this shipment? Goods are physically issued NOW: restricted stock is decremented and GOODS_ISSUE is recorded. This cannot be undone through the shipment workflow.')"
                        class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                    START — issue the goods
                </button>
            </form>
        @elseif ($shipment->shipment_status->value === 'IN_TRANSIT')
            <div class="m-card p-4 text-xs text-on-surface-variant">
                Goods issued. POD confirmation and shipment COMPLETED belong to the delivery-confirmation workflow.
            </div>
            <div class="grid gap-2">
                @foreach ($shipment->deliveries->filter(fn ($delivery) => in_array($delivery->delivery_status->value, ['SHIPPED', 'PARTIALLY_CONFIRMED'], true)) as $delivery)
                    <a href="{{ route('pod.show', $delivery) }}"
                       class="h-12 inline-flex items-center justify-center rounded-full bg-primary text-on-primary font-semibold">
                        Record POD · {{ $delivery->delivery_no }}
                    </a>
                @endforeach
            </div>
        @endif

        <a href="{{ route('shipments.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All shipments
        </a>
    </div>
</x-app-layout>
