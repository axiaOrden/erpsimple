<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>{{ $delivery->delivery_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    SO {{ $delivery->sales_order_no }}
                    · from {{ $delivery->sourceCustomer->business_name ?? $delivery->source_customer_id }}
                    · to {{ $delivery->salesOrder->soldToCustomer->business_name ?? $delivery->customer_id }}
                </p>
            </div>
            <span class="m-chip {{ $delivery->delivery_status->value === 'ALLOCATED' ? 'm-chip-active' : '' }}">
                {{ $delivery->delivery_status->value }}
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
            <div class="flex justify-between"><span>Created</span><span>{{ $delivery->created_at?->format('d M Y H:i') }}</span></div>
            <div class="flex justify-between mt-1"><span>Allocated</span><span>{{ $delivery->allocated_at?->format('d M Y H:i') ?? '—' }}</span></div>
            <div class="flex justify-between mt-1"><span>Created by</span><span>{{ $delivery->created_by }}</span></div>
        </div>

        @if ($delivery->delivery_status->value === 'ALLOCATED')
            <div class="m-card divide-y divide-outline-variant text-sm">
                @foreach ($delivery->items as $item)
                    <div class="p-4 flex items-center justify-between">
                        <div>
                            <p class="font-medium">{{ $item->product->product_description ?? $item->product_id }}</p>
                            <p class="text-xs text-on-surface-variant">
                                SO line {{ $item->sales_order_item_no }}
                                @if ($item->is_free_item) · FREE @endif
                            </p>
                        </div>
                        <p class="font-semibold tabular-nums">{{ $item->allocated_qty }} {{ $item->delivery_unit }}</p>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('deliveries.release', $delivery) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">
                <button type="submit"
                        onclick="return confirm('Release this allocation? Restricted stock returns to unrestricted and the delivery becomes editable DRAFT.')"
                        class="w-full h-12 rounded-full bg-tertiary-container text-on-tertiary-container font-semibold">
                    Release allocation
                </button>
            </form>
        @elseif ($delivery->delivery_status->value === 'SHIPPED')
            <a href="{{ route('pod.show', $delivery) }}"
               class="w-full h-12 inline-flex items-center justify-center rounded-full bg-primary text-on-primary font-semibold">
                Record POD (confirm receipt)
            </a>
        @endif

        @if ($delivery->delivery_status->value === 'DRAFT')
            <div class="m-card p-3 text-xs text-on-surface-variant">
                This delivery is DRAFT (not allocated). Edit the quantities and allocate —
                unrestricted stock is re-checked and transferred to restricted at allocation time.
            </div>

            <form method="POST" action="{{ route('deliveries.reallocate', $delivery) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

                <div class="m-card divide-y divide-outline-variant">
                    @foreach ($context as $line)
                        @php
                            $previous = $draftItems->get($line['item_no']);
                            $maxBasic = min((float) $line['remaining_basic'], (float) $line['unrestricted_qty']);
                            $freeItem = (bool) $line['is_free_item'];
                        @endphp
                        <div class="p-4 space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium truncate">
                                        {{ $line['product'] }}
                                        @if ($freeItem)<span class="m-chip m-chip-active ml-1">FREE</span>@endif
                                        @if ($line['rejection_status'] !== 'NONE')<span class="m-chip m-chip-error ml-1">{{ $line['rejection_status'] }}</span>@endif
                                    </p>
                                    <p class="text-xs text-on-surface-variant">
                                        ordered {{ rtrim(rtrim($line['order_qty'], '0'), '.') }} {{ $line['order_unit'] }}
                                        · allocated {{ rtrim(rtrim($line['allocated_basic'], '0'), '.') }}
                                        · remaining {{ rtrim(rtrim($line['remaining_basic'], '0'), '.') }} {{ $line['basic_unit'] }}
                                        @if ($previous) · was {{ rtrim(rtrim((string) $previous->allocated_qty, '0'), '.') }} {{ $previous->delivery_unit }} @endif
                                    </p>
                                </div>
                                <div class="text-right text-xs shrink-0 tabular-nums">
                                    <p>unrestricted <strong>{{ number_format((float) $line['unrestricted_qty'], 3) }}</strong></p>
                                    <p class="text-on-surface-variant">restricted {{ number_format((float) $line['restricted_qty'], 3) }}</p>
                                </div>
                            </div>

                            @if ($line['rejection_status'] === 'NONE' && $maxBasic > 0)
                                <div class="flex gap-2 items-center">
                                    <input type="number" name="lines[{{ $loop->index }}][sales_order_item_no]" value="{{ $line['item_no'] }}" hidden>
                                    <input type="number" name="lines[{{ $loop->index }}][qty]" step="0.001" min="0"
                                           value="{{ $previous ? rtrim(rtrim((string) $previous->allocated_qty, '0'), '.') : '' }}"
                                           max="{{ rtrim(rtrim((string) $maxBasic, '0'), '.') }}"
                                           placeholder="Deliver qty"
                                           class="w-32 rounded-m border-outline-variant bg-surface text-sm">
                                    <select name="lines[{{ $loop->index }}][unit]" class="rounded-m border-outline-variant bg-surface text-sm">
                                        @foreach (['PCS', 'CTN', 'KG', 'TON'] as $u)
                                            <option value="{{ $u }}" @selected($u === ($previous->delivery_unit ?? $line['order_unit']))>{{ $u }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <p class="text-xs text-on-surface-variant">
                                    @if ($line['rejection_status'] !== 'NONE')
                                        Rejected demand — no new allocation.
                                    @else
                                        Nothing allocatable (remaining {{ rtrim(rtrim($line['remaining_basic'], '0'), '.') }}, unrestricted {{ rtrim(rtrim($line['unrestricted_qty'], '0'), '.') }}).
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <button type="submit"
                        onclick="return confirm('Allocate stock now? Unrestricted decreases, restricted increases, on-hand stays unchanged.')"
                        class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                    Allocate delivery
                </button>
            </form>
        @endif

        <a href="{{ route('orders.show', $delivery->sales_order_no) }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            Back to order
        </a>
    </div>
</x-app-layout>
