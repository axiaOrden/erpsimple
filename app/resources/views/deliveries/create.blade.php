<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>Create delivery</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $order->sales_order_no }} → {{ $order->soldToCustomer->business_name ?? $order->sold_to_customer_id }}
                    · from {{ $order->sourceCustomer->business_name ?? $order->source_customer_id }}
                </p>
            </div>
            <a href="{{ route('orders.show', $order) }}" class="m-chip">Back</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('deliveries.store', $order) }}" class="space-y-4 max-w-2xl">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

        <div class="m-card p-3 text-xs text-on-surface-variant">
            Allocation is a hard stock commitment: unrestricted → restricted at the order's source customer.
            Insufficient unrestricted stock BLOCKS the line — quantities are never silently reduced.
        </div>

        <div class="m-card divide-y divide-outline-variant">
            @foreach ($lines as $line)
                @php
                    $maxBasic = min((float) $line['remaining_basic'], (float) $line['unrestricted_qty']);
                    // FREE deal lines are physical stock like any other line:
                    // their own product, own quantity, own allocation.
                    $allocatable = $line['rejection_status'] === 'NONE' && $maxBasic > 0;
                @endphp
                <div class="p-4 space-y-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium truncate">
                                {{ $line['product'] }}
                                @if ($line['is_free_item'])<span class="m-chip m-chip-active ml-1">FREE</span>@endif
                                @if ($line['rejection_status'] !== 'NONE')<span class="m-chip m-chip-error ml-1">{{ $line['rejection_status'] }}</span>@endif
                            </p>
                            <p class="text-xs text-on-surface-variant">
                                ordered {{ rtrim(rtrim($line['order_qty'], '0'), '.') }} {{ $line['order_unit'] }}
                                ({{ rtrim(rtrim($line['ordered_basic'], '0'), '.') }} {{ $line['basic_unit'] }})
                                · allocated {{ rtrim(rtrim($line['allocated_basic'], '0'), '.') }}
                                · remaining {{ rtrim(rtrim($line['remaining_basic'], '0'), '.') }} {{ $line['basic_unit'] }}
                            </p>
                        </div>
                        <div class="text-right text-xs shrink-0 tabular-nums">
                            <p>unrestricted <strong>{{ number_format((float) $line['unrestricted_qty'], 3) }}</strong></p>
                            <p class="text-on-surface-variant">restricted {{ number_format((float) $line['restricted_qty'], 3) }}</p>
                        </div>
                    </div>

                    @if ($allocatable)
                        @php
                            $transitBalance = (float) ($transitBalances[$line['product_id']] ?? 0);
                        @endphp
                        <div class="flex gap-2 items-center">
                            <input type="number" name="lines[{{ $loop->index }}][sales_order_item_no]" value="{{ $line['item_no'] }}" hidden>
                            <input type="number" name="lines[{{ $loop->index }}][qty]" step="0.001" min="0"
                                   max="{{ rtrim(rtrim((string) $maxBasic, '0'), '.') }}"
                                   placeholder="Deliver qty"
                                   class="w-32 rounded-m border-outline-variant bg-surface text-sm">
                            <select name="lines[{{ $loop->index }}][unit]" class="rounded-m border-outline-variant bg-surface text-sm">
                                @foreach (['PCS', 'CTN', 'KG', 'TON'] as $u)
                                    <option value="{{ $u }}" @selected($u === $line['order_unit'])>{{ $u }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($transitBalance > 0)
                            <div class="flex gap-2 items-center mt-1">
                                <input type="number" name="lines[{{ $loop->index }}][transit_qty]" step="0.001" min="0"
                                       max="{{ rtrim(rtrim((string) $transitBalance, '0'), '.') }}"
                                       value="0" placeholder="0"
                                       class="w-32 rounded-m border-outline-variant bg-surface text-sm">
                                <span class="text-xs text-on-surface-variant">from transit
                                    (reusable: {{ rtrim(rtrim((string) $transitBalance, '0'), '.') }} basic)</span>
                            </div>
                        @endif
                    @else
                        <p class="text-xs {{ $line['rejection_status'] !== 'NONE' ? 'text-error' : 'text-on-surface-variant' }}">
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
</x-app-layout>
