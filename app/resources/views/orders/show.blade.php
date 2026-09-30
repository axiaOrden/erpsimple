<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $order->sales_order_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $order->soldToCustomer->business_name ?? $order->sold_to_customer_id }}
                    · via {{ $order->supplyingCustomer->business_name ?? $order->supplying_customer_id }}
                    @if ($order->source_customer_id !== $order->supplying_customer_id)
                        · source {{ $order->sourceCustomer->business_name ?? $order->source_customer_id }}
                    @endif
                </p>
            </div>
            <div class="text-right shrink-0">
                <span class="m-chip {{ $order->order_status->value === 'DRAFT' ? '' : 'm-chip-active' }} {{ str_contains($order->order_status->value, 'REJECTED') ? 'm-chip-error' : '' }}">
                    {{ $order->order_status->value }}
                </span>
            </div>
        </div>
    </x-slot>

    @if (session('conflicts'))
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-4 text-sm">
            <p class="font-semibold mb-1">Cannot confirm — business decision required</p>
            <ul class="list-disc list-inside space-y-1">
                @foreach ((array) session('conflicts') as $conflict)
                    <li>{{ $conflict }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-4 max-w-2xl">
        <div class="m-card p-4">
            <div class="flex justify-between text-sm">
                <span class="text-on-surface-variant">Order date</span>
                <span>{{ $order->order_date?->format('d M Y H:i') }}</span>
            </div>
            <div class="flex justify-between text-sm mt-1">
                <span class="text-on-surface-variant">Pricing date</span>
                <span>{{ $order->pricing_date?->format('d M Y') }}</span>
            </div>
            @if ($order->confirmed_at)
                <div class="flex justify-between text-sm mt-1">
                    <span class="text-on-surface-variant">Confirmed</span>
                    <span>{{ $order->confirmed_at->format('d M Y H:i') }}</span>
                </div>
            @endif
            <p class="text-xs text-on-surface-variant mt-3">
                Sales orders represent demand only — stock is not reserved or deducted at any stage.
            </p>
        </div>

        <div class="m-card divide-y divide-outline-variant">
            @foreach ($order->items->sortBy('item_no') as $item)
                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium truncate">
                                {{ $item->product->product_description ?? $item->product_id }}
                                @if ($item->is_free_item)
                                    <span class="m-chip m-chip-active ml-1">FREE{{ $item->deal_no ? ' · '.$item->deal_no : '' }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-on-surface-variant">
                                {{ $item->order_qty }} {{ $item->order_unit }}
                                @if ($item->parent_item_no) · from line {{ $item->parent_item_no }} @endif
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-semibold">{{ number_format((float) $item->unit_price, 2) }}</p>
                            <p class="text-xs text-on-surface-variant">{{ number_format((float) $item->subtotal_amount, 2) }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-2 text-xs">
                        @if ($item->recommended_price !== null)
                            <span class="m-chip">Rec. {{ number_format((float) $item->recommended_price, 2) }}</span>
                        @endif
                        @if ($item->price_overridden)
                            <span class="m-chip m-chip-error" title="{{ $item->price_override_reason }}">OVERRIDDEN</span>
                            <span class="text-on-surface-variant">{{ $item->price_override_reason }}</span>
                        @endif
                        @if ($item->isRejected())
                            <span class="m-chip m-chip-error">REJECTED — {{ $item->rejection_reason }}</span>
                        @endif
                    </div>

                    @if ($order->order_status->value !== 'DRAFT' && ! $item->is_free_item && ! $item->isRejected())
                        <details class="mt-2">
                            <summary class="text-xs text-error font-medium cursor-pointer">Reject remaining demand…</summary>
                            <form method="POST" action="{{ route('orders.items.reject', [$order, $item->item_no]) }}" class="mt-2 flex gap-2">
                                @csrf
                                <input type="text" name="rejection_reason" required maxlength="255"
                                       placeholder="Reason (required)"
                                       class="flex-1 rounded-m border-outline-variant bg-surface text-sm">
                                <button type="submit" onclick="return confirm('Reject the remaining demand of this line? Quantities already allocated to a delivery are not affected.')"
                                        class="h-10 px-4 rounded-full bg-error-container text-on-error-container text-sm font-medium">
                                    Reject
                                </button>
                            </form>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="m-card p-4 text-sm">
            <div class="flex justify-between"><span class="text-on-surface-variant">Gross</span><span>{{ number_format((float) $order->gross_amount, 2) }}</span></div>
            <div class="flex justify-between mt-1"><span class="text-on-surface-variant">Discount</span><span>{{ number_format((float) $order->discount_amount, 2) }}</span></div>
            <div class="flex justify-between mt-1"><span class="text-on-surface-variant">Tax</span><span>{{ number_format((float) $order->tax_amount, 2) }}</span></div>
            <div class="flex justify-between mt-2 pt-2 border-t border-outline-variant font-semibold">
                <span>Net</span><span>{{ number_format((float) $order->net_amount, 2) }} {{ $order->currency }}</span>
            </div>
        </div>

        @if ($order->order_status->value === 'DRAFT')
            <div class="flex gap-3">
                <a href="{{ route('orders.edit', $order) }}"
                   class="flex-1 h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                    Edit draft
                </a>
                <form method="POST" action="{{ route('orders.confirm', $order) }}" class="flex-1 contents">
                    @csrf
                    <button type="submit"
                            onclick="return confirm('Confirm this order? Confirmed demand is immutable and cannot be changed, only rejected line-by-line.')"
                            class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                        Confirm order
                    </button>
                </form>
            </div>
        @elseif ($order->order_status->isConfirmed())
            <a href="{{ route('deliveries.create', $order) }}"
               class="w-full h-12 inline-flex items-center justify-center rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                Create delivery
            </a>
        @endif
    </div>
</x-app-layout>
