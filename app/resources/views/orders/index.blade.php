<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Sales orders</h1>
                <p class="text-sm text-on-surface-variant">{{ $orders->total() }} orders — demand only, inventory unaffected</p>
            </div>
            <a href="{{ route('orders.create') }}"
               class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                + New order
            </a>
        </div>
    </x-slot>

    @if ($orders->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No orders yet</p>
            <p class="text-sm text-on-surface-variant mt-1">Create the first order for one of your customers.</p>
            <a href="{{ route('orders.create') }}" class="inline-block mt-4 text-primary font-medium">Create order</a>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($orders as $order)
                <a href="{{ route('orders.show', $order) }}" class="m-list-item">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium truncate">{{ $order->soldToCustomer->business_name ?? $order->sold_to_customer_id }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            {{ $order->sales_order_no }}
                            · via {{ $order->supplyingCustomer->business_name ?? $order->supplying_customer_id }}
                            · {{ $order->order_date?->format('d M Y') }}
                        </p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-semibold">{{ number_format((float) $order->net_amount, 2) }}</p>
                        <span class="m-chip {{ in_array($order->order_status->value, ['CONFIRMED', 'OPEN_DELIVERY']) ? 'm-chip-active' : '' }} {{ str_contains($order->order_status->value, 'REJECTED') ? 'm-chip-error' : '' }}">
                            {{ $order->order_status->value }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</x-app-layout>
