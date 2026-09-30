<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>Edit draft {{ $order->sales_order_no }}</h1>
                <p class="text-sm text-on-surface-variant">Draft — not yet a binding order</p>
            </div>
            <a href="{{ route('orders.show', $order) }}" class="m-chip">Back</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('orders.update', $order) }}" x-data="orderCapture({
            soldTo: '{{ $order->sold_to_customer_id }}',
            supplying: '{{ $order->supplying_customer_id }}',
            source: '{{ $order->source_customer_id }}',
            lines: @js($order->items->where('line_source', 'MANUAL')->map(fn ($i) => [
                'product_id' => $i->product_id,
                'qty' => (float) $i->order_qty,
                'unit' => $i->order_unit,
                'recommendedPrice' => $i->recommended_price !== null ? (float) $i->recommended_price : null,
                'override' => $i->price_overridden,
                'unitPrice' => $i->price_overridden ? (float) $i->unit_price : null,
                'overrideReason' => $i->price_override_reason ?? '',
                'availableText' => '',
            ])->values())
        })"
          class="space-y-4 max-w-2xl">
        @csrf
        @method('patch')

        <div class="m-card p-4 text-sm">
            <div class="flex justify-between"><span class="text-on-surface-variant">Customer</span><span class="font-medium">{{ $order->soldToCustomer->business_name }}</span></div>
            <div class="flex justify-between mt-1"><span class="text-on-surface-variant">Supplying Primary</span><span class="font-medium">{{ $order->supplyingCustomer->business_name }}</span></div>
            @if ($order->source_customer_id !== $order->supplying_customer_id)
                <div class="flex justify-between mt-1"><span class="text-on-surface-variant">Source</span><span class="font-medium">{{ $order->sourceCustomer->business_name ?? $order->source_customer_id }}</span></div>
            @endif
        </div>

        <div class="m-card p-4 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Products & quantities</h2>
                <button type="button" @click="addLine()"
                        class="h-9 px-3 rounded-full bg-secondary-container text-on-secondary-container text-sm font-medium">
                    + Add product
                </button>
            </div>

            <template x-for="(line, index) in lines" :key="index">
                <div class="border border-outline-variant rounded-m p-3 space-y-2">
                    <div class="flex items-center justify-between">
                        <select x-model="line.product_id" @change="onProductChange(index)"
                                class="flex-1 rounded-m border-outline-variant bg-surface" required>
                            <option value="">Choose product…</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="p.label + ' (' + p.sku + ')'"></option>
                            </template>
                        </select>
                        <button type="button" @click="removeLine(index)"
                                class="ml-2 h-8 w-8 grid place-items-center rounded-full bg-error-container text-on-error-container"
                                aria-label="Remove line">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-on-surface-variant mb-1">Quantity</label>
                            <input type="number" step="0.001" min="0.001" x-model.number="line.qty" @change="onQtyChange()"
                                   class="w-full rounded-m border-outline-variant bg-surface" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-on-surface-variant mb-1">Unit</label>
                            <select x-model="line.unit" @change="onQtyChange()"
                                    class="w-full rounded-m border-outline-variant bg-surface">
                                <template x-for="u in unitsFor(line)" :key="u">
                                    <option :value="u" x-text="u"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <span class="text-on-surface-variant">
                            Recommended: <strong x-text="line.recommendedPrice ?? '—'"></strong>
                        </span>
                        <label class="inline-flex items-center gap-1 text-xs">
                            <input type="checkbox" x-model="line.override" class="rounded border-outline text-primary">
                            Override
                        </label>
                    </div>

                    <template x-if="line.override">
                        <div class="space-y-2">
                            <input type="number" step="0.01" min="0" x-model.number="line.unitPrice"
                                   placeholder="Actual unit price" required
                                   class="w-full rounded-m border-outline-variant bg-surface">
                            <input type="text" x-model="line.overrideReason" maxlength="255"
                                   placeholder="Override reason (required)" required
                                   class="w-full rounded-m border-outline-variant bg-surface">
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="hidden">
            <input type="hidden" name="action" value="draft">
            <template x-for="(line, index) in lines" :key="'h'+index">
                <span>
                    <input type="hidden" :name="'lines['+index+'][product_id]'" :value="line.product_id">
                    <input type="hidden" :name="'lines['+index+'][qty]'" :value="line.qty">
                    <input type="hidden" :name="'lines['+index+'][unit]'" :value="line.unit">
                    <input type="hidden" :name="'lines['+index+'][unit_price]'" :value="line.override ? line.unitPrice : ''">
                    <input type="hidden" :name="'lines['+index+'][price_override_reason]'" :value="line.override ? line.overrideReason : ''">
                </span>
            </template>
        </div>

        <div class="flex gap-3 pb-6">
            <button type="submit" name="action" value="draft"
                    class="flex-1 h-12 rounded-full bg-secondary-container text-on-secondary-container font-semibold">
                Save draft
            </button>
            <button type="submit" name="action" value="confirm"
                    class="flex-1 h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                Review & confirm
            </button>
        </div>
    </form>
</x-app-layout>
