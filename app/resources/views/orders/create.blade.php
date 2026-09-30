<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>New order</h1>
                <p class="text-sm text-on-surface-variant">Demand capture — availability is informational only</p>
            </div>
            <a href="{{ route('orders.index') }}" class="m-chip">Cancel</a>
        </div>
    </x-slot>

    <form id="order-form" method="POST" action="{{ route('orders.store') }}"
          x-data="orderCapture()"
          class="space-y-4 max-w-2xl">
        @csrf

        <div class="m-card p-4 space-y-3">
            <h2 class="text-base font-semibold">1 · Customer & supply</h2>

            <div>
                <x-input-label for="sold_to" value="Customer (demand owner)" />
                <select id="sold_to" x-model="soldTo" @change="onCustomerChange()"
                        class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                    <option value="">Choose customer…</option>
                    @foreach ($assignedCustomers as $c)
                        <option value="{{ $c->customer_id }}">{{ $c->business_name }} ({{ $c->customer_type->value }})</option>
                    @endforeach
                </select>
                <p class="text-xs text-on-surface-variant mt-1">
                    The supplying Primary is chosen per order — a Secondary is not bound to one.
                </p>
            </div>

            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <x-input-label for="supplying" value="Supplying Primary" />
                    <select id="supplying" x-model="supplying" @change="onSupplyingChange()"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                        <option value="">Choose primary…</option>
                        @foreach ($primaries as $p)
                            <option value="{{ $p->customer_id }}">{{ $p->business_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="source" value="Stock source" />
                    <select id="source" x-model="source"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                        <option value="">—</option>
                        <template x-for="loc in sourceOptions" :key="loc.id">
                            <option :value="loc.id" x-text="loc.label"></option>
                        </template>
                    </select>
                    <p class="text-xs text-on-surface-variant mt-1">The Primary itself or one of its SHIP_TO locations.</p>
                </div>
            </div>
        </div>

        <div class="m-card p-4 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">2 · Products & quantities</h2>
                <button type="button" @click="addLine()" :disabled="!supplying"
                        class="h-9 px-3 rounded-full bg-secondary-container text-on-secondary-container text-sm font-medium disabled:opacity-40">
                    + Add product
                </button>
            </div>

            <p x-show="priceAmbiguous.length > 0" class="text-sm bg-error-container text-on-error-container rounded-m p-3">
                Pricing is ambiguous for some products — multiple conditions apply. A business
                decision on precedence is required before confirmation.
            </p>

            <template x-for="(line, index) in lines" :key="index">
                <div class="border border-outline-variant rounded-m p-3 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-on-surface-variant" x-text="'Line ' + (index + 1)"></span>
                        <button type="button" @click="removeLine(index)"
                                class="h-8 w-8 grid place-items-center rounded-full bg-error-container text-on-error-container"
                                aria-label="Remove line">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <select x-model="line.product_id" @change="onProductChange(index)"
                            class="w-full rounded-m border-outline-variant bg-surface" required>
                        <option value="">Choose product…</option>
                        <template x-for="p in products" :key="p.id">
                            <option :value="p.id" x-text="p.label + ' (' + p.sku + ')'"></option>
                        </template>
                    </select>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-on-surface-variant mb-1">Quantity</label>
                            <input type="number" step="0.001" min="0.001" x-model.number="line.qty"
                                   @input="onQtyChange()"
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

                    <p class="text-xs" :class="line.availableText.includes('insufficient') ? 'text-error' : 'text-on-surface-variant'"
                       x-text="line.availableText" x-show="line.availableText"></p>

                    <div class="border-t border-outline-variant pt-2">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-on-surface-variant">
                                Recommended: <strong x-text="line.recommendedPrice ?? '—'"></strong>
                            </span>
                            <label class="inline-flex items-center gap-1 text-xs">
                                <input type="checkbox" x-model="line.override" class="rounded border-outline text-primary">
                                Override price
                            </label>
                        </div>

                        <template x-if="line.override">
                            <div class="mt-2 space-y-2">
                                <input type="number" step="0.01" min="0" x-model.number="line.unitPrice"
                                       placeholder="Actual unit price"
                                       class="w-full rounded-m border-outline-variant bg-surface" required>
                                <input type="text" x-model="line.overrideReason" maxlength="255"
                                       placeholder="Override reason (required)"
                                       class="w-full rounded-m border-outline-variant bg-surface" required>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <p x-show="lines.length === 0" class="text-sm text-on-surface-variant">
                Choose a supplying Primary first, then add products.
            </p>
        </div>

        <div class="m-card p-4" x-show="dealRewards.length > 0">
            <h2 class="text-base font-semibold">Trade deals</h2>
            <p class="text-xs text-on-surface-variant mb-2">
                Free items are proposed server-side and finalized at confirmation.
            </p>
            <ul class="space-y-1 text-sm">
                <template x-for="(r, i) in dealRewards" :key="i">
                    <li class="flex items-center gap-2">
                        <span class="m-chip m-chip-active">FREE</span>
                        <span x-text="r.qty + ' ' + r.unit + ' — ' + r.label"></span>
                        <span class="text-xs text-on-surface-variant" x-text="'via ' + r.deal"></span>
                    </li>
                </template>
            </ul>
            <p x-show="dealsAmbiguous" class="mt-2 text-sm bg-error-container text-on-error-container rounded-m p-3">
                Multiple deals qualify and no stacking rule is defined — a business
                decision is required before confirming.
            </p>
        </div>

        {{-- hidden fields mirroring Alpine state for plain POST --}}
        <div class="hidden">
            <input type="hidden" name="supplying_customer_id" :value="supplying">
            <input type="hidden" name="source_customer_id" :value="source">
            <input type="hidden" name="sold_to_customer_id" :value="soldTo">
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
