<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Deliveries</h1>
                <p class="text-sm text-on-surface-variant">{{ $deliveries->total() }} deliveries — allocation, not yet physical issue</p>
            </div>
        </div>
    </x-slot>

    @if ($deliveries->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No deliveries yet</p>
            <p class="text-sm text-on-surface-variant mt-1">Create a delivery from a confirmed sales order.</p>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($deliveries as $delivery)
                <a href="{{ route('deliveries.show', $delivery->delivery_no) }}" class="m-list-item">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">{{ $delivery->delivery_no }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            SO {{ $delivery->sales_order_no }}
                            · from {{ $delivery->sourceCustomer->business_name ?? $delivery->source_customer_id }}
                            · {{ $delivery->created_at?->format('d M Y') }}
                        </p>
                    </div>
                    <span class="m-chip {{ $delivery->delivery_status->value === 'ALLOCATED' ? 'm-chip-active' : '' }}">
                        {{ $delivery->delivery_status->value }}
                    </span>
                </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $deliveries->links() }}</div>
    @endif
</x-app-layout>
