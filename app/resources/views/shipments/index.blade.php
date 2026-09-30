<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>Shipments</h1>
                <p class="text-sm text-on-surface-variant">Dispatch loads — goods issue happens at START</p>
            </div>
            <a href="{{ route('shipments.create') }}" class="m-chip m-chip-active">New shipment</a>
        </div>
    </x-slot>

    <div class="space-y-3">
        @forelse ($shipments as $shipment)
            <a href="{{ route('shipments.show', $shipment) }}"
               class="m-card p-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium">{{ $shipment->shipment_no }}</p>
                    <p class="text-xs text-on-surface-variant truncate">
                        from {{ $shipment->sourceCustomer->business_name ?? $shipment->source_customer_id }}
                        · {{ $shipment->deliveries_count }} delivery(ies)
                        @if ($shipment->vehicle_reference) · {{ $shipment->vehicle_reference }} @endif
                    </p>
                    <p class="text-xs text-on-surface-variant">
                        created {{ $shipment->created_on?->format('d M H:i') }}
                        @if ($shipment->started_on) · started {{ $shipment->started_on->format('d M H:i') }} @endif
                    </p>
                </div>
                <span class="m-chip {{ $shipment->shipment_status->value === 'IN_TRANSIT' ? 'm-chip-active' : '' }}">
                    {{ $shipment->shipment_status->value }}
                </span>
            </a>
        @empty
            <div class="m-card p-6 text-center text-sm text-on-surface-variant">
                No shipments yet. Create one from a stock holder source, then attach ALLOCATED deliveries.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $shipments->links() }}</div>
</x-app-layout>
