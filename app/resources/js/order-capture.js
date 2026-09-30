/**
 * Order capture component (Phase 4). Server-computed advisory context:
 * products (scope-filtered), recommended prices, deal evaluation.
 * Draft can be saved locally when offline (IndexedDB outbox).
 */
import { outbox } from './pwa/outbox.js';
import { net } from './net-store.js';

export function orderCaptureComponent(existing = null) {
    return {
        soldTo: existing?.soldTo ?? '',
        supplying: existing?.supplying ?? '',
        source: existing?.source ?? '',
        confirmNow: false,

        products: [],
        priceAmbiguous: [],
        dealRewards: [],
        dealsAmbiguous: false,
        sourcesCache: {},

        // Read-only purchase guidance for the selected customer. Never used to
        // create lines or constrain quantities.
        history: [],

        lines: existing?.lines ?? [],

        async init() {
            if (this.soldTo) {
                await this.loadHistory();
            }

            if (this.supplying && this.soldTo) {
                await this.loadProducts();
            }
        },

        get sourceOptions() {
            return this.sourcesCache[this.supplying] ?? [{ id: this.supplying, label: this.supplyingName || this.supplying }];
        },

        get supplyingName() {
            const el = document.querySelector(`#supplying option[value="${this.supplying}"]`);

            return el ? el.textContent.trim() : this.supplying;
        },

        unitsFor(line) {
            const p = this.products.find((x) => x.id === line.product_id);

            if (!p) return ['PCS'];

            return p.units ?? [p.basic_unit];
        },

        async onCustomerChange() {
            // Supplying choice is independent of the sold-to customer; nothing
            // to filter — the employee may use any Primary they can supply from.
            // The customer's recent purchases are guidance only.
            await this.loadHistory();
        },

        fmtQty(qty) {
            const n = Number(qty);

            if (!Number.isFinite(n)) return String(qty ?? '');

            return (Math.round(n * 1000) / 1000).toString();
        },

        async loadHistory() {
            if (!this.soldTo) {
                this.history = [];

                return;
            }

            const params = new URLSearchParams({ sold_to_customer_id: this.soldTo });

            try {
                const response = await fetch('/orders/customer-history?' + params.toString(), {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    this.history = [];

                    return;
                }

                const data = await response.json();
                this.history = data.history ?? [];
            } catch (_) {
                this.history = [];
            }
        },

        async onSupplyingChange() {
            if (this.supplying) {
                this.source = this.supplying; // default: the Primary itself
                await this.loadProducts();
                await this.evaluateDeals();
            }
        },

        addLine() {
            this.lines.push({
                product_id: '',
                qty: 1,
                unit: '',
                recommendedPrice: null,
                override: false,
                unitPrice: null,
                overrideReason: '',
                availableText: '',
            });
        },

        removeLine(index) {
            this.lines.splice(index, 1);
            this.evaluateDeals();
        },

        async onProductChange(index) {
            const line = this.lines[index];
            const p = this.products.find((x) => x.id === line.product_id);

            if (p) {
                line.unit = p.basic_unit;
                line.recommendedPrice = p.recommended_price;
            }

            await this.evaluateDeals();
        },

        async onQtyChange() {
            await this.evaluateDeals();
        },

        async loadProducts() {
            if (!this.supplying || !this.soldTo) return;

            const params = new URLSearchParams({
                supplying_customer_id: this.supplying,
                sold_to_customer_id: this.soldTo,
            });

            const response = await fetch('/orders/context?'+params.toString(), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return;

            const data = await response.json();

            this.products = data.products.map((p) => ({ ...p, units: [p.basic_unit, ...(p.alt_units ?? [])] }));
            this.priceAmbiguous = data.price_ambiguous ?? [];
        },

        async evaluateDeals() {
            if (!this.supplying || !this.soldTo) return;

            const payload = {
                supplying_customer_id: this.supplying,
                sold_to_customer_id: this.soldTo,
                lines: this.lines
                    .filter((l) => l.product_id && l.qty > 0)
                    .map((l) => ({ product_id: l.product_id, qty: String(l.qty), unit: l.unit })),
            };

            const response = await fetch('/orders/context', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) return;

            const data = await response.json();

            this.dealRewards = (data.deals?.rewards ?? []).map((r) => ({
                label: this.products.find((p) => p.id === r.product_id)?.label ?? r.product_id,
                qty: r.reward_qty,
                unit: r.reward_unit,
                deal: r.deal_no,
            }));
            this.dealsAmbiguous = data.deals?.ambiguous ?? false;
        },

        /** Queue the draft offline (LOCAL DRAFT → PENDING SYNC). */
        async queueOfflineDraft(cachedPrices) {
            const record = await outbox.enqueue('ORDER_DRAFT', {
                supplying_customer_id: this.supplying,
                source_customer_id: this.source,
                sold_to_customer_id: this.soldTo,
                lines: this.lines.map((l) => ({
                    product_id: l.product_id,
                    qty: String(l.qty),
                    unit: l.unit,
                    unit_price: l.override ? String(l.unitPrice ?? '') : null,
                    price_override_reason: l.override ? l.overrideReason : null,
                })),
                cached_prices: Object.fromEntries(cachedPrices),
                request_confirm: this.confirmNow,
            });

            await net.refreshPending();

            if (navigator.onLine) {
                const { syncNow } = await import('./pwa/sync-engine.js');
                syncNow().catch(() => {});
            }

            return record;
        },
    };
}
