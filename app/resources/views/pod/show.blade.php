<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>POD — {{ $delivery->delivery_no }}</h1>
                <p class="text-sm text-on-surface-variant">
                    from {{ $delivery->sourceCustomer->business_name ?? $delivery->source_customer_id }}
                    · to {{ $delivery->salesOrder->soldToCustomer->business_name ?? $delivery->customer_id }}
                </p>
            </div>
            <span class="m-chip">{{ $delivery->delivery_status->value }}</span>
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
        <div class="m-card p-3 text-xs text-on-surface-variant">
            Confirm what the customer actually received. Differences NEVER change stock or invoices —
            the goods issue already recorded what left the source.
        </div>

        @php
            $dispositions = [
                'WITH_EMPLOYEE' => 'with me (intact)',
                'DAMAGED' => 'with me (damaged)',
                'AT_SOURCE' => 'handed back at source',
                'UNKNOWN' => 'unknown whereabouts',
            ];
        @endphp

        {{-- One card per Delivery Item. Every field stacks vertically at mobile
             width (Reason for Difference and Other Remarks are NEVER side by
             side); a readable two-column layout is used from `sm` up only. --}}
        @foreach ($delivery->items as $item)
            @php
                // Composite-keyed relation is not loadable — query directly.
                $confirmation = \App\Models\DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)
                    ->where('delivery_item_no', $item->item_no)->first();
                $shipped = rtrim(rtrim((string) $item->allocated_qty, '0'), '.');
            @endphp
            <div class="m-card p-4"
                 @unless ($confirmation)
                     x-data="{
                         shipped: {{ (float) $item->allocated_qty }},
                         confirmed: {{ (float) $item->allocated_qty }},
                         // A difference exists only when LESS arrived than shipped:
                         // an equal (or over-) quantity is a full confirmation, which
                         // needs no reason and no custody position.
                         get differs() { return Number(this.confirmed) < Number(this.shipped) },
                     }"
                 @endunless
                 data-pod-item="{{ $item->item_no }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ $item->product->product_description ?? $item->product_id }}
                            @if ($item->is_free_item)<span class="m-chip m-chip-active ml-1">FREE</span>@endif
                        </p>
                        <p class="text-xs text-on-surface-variant">Shipped: {{ $shipped }} {{ $item->delivery_unit }}</p>
                    </div>
                    @if ($confirmation)
                        <span class="m-chip shrink-0 {{ $confirmation->confirmation_status->value === 'CONFIRMED' ? 'm-chip-active' : 'm-chip-error' }}">
                            {{ $confirmation->confirmation_status->value }} · {{ \App\Services\Decimal::trimZeros((string) $confirmation->confirmed_qty) }} {{ $confirmation->confirmed_unit }}
                            @if ($confirmation->difference_qty !== '0.000')
                                · diff {{ \App\Services\Decimal::trimZeros((string) $confirmation->difference_qty) }} {{ $confirmation->difference_unit }} ({{ $confirmation->difference_reason->value }})
                            @endif
                        </span>
                    @endif
                </div>

                @if (! $confirmation)
                    <form method="POST" action="{{ route('pod.confirm', $delivery) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="delivery_item_no" value="{{ $item->item_no }}">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

                        {{-- Confirmed qty: the shipped unit is INHERITED and read-only
                             from the delivery line; only the quantity is typed. --}}
                        <div class="space-y-1">
                            <input type="hidden" name="confirmed_unit" value="{{ $item->delivery_unit }}">
                            <label class="block text-[11px] text-on-surface-variant" for="confirmed-qty-{{ $item->item_no }}">Confirmed Qty</label>
                            <div class="flex items-center gap-2">
                                <input id="confirmed-qty-{{ $item->item_no }}" type="number" name="confirmed_qty" step="0.001" min="0"
                                       x-model="confirmed"
                                       value="{{ $shipped }}"
                                       max="{{ $shipped }}"
                                       inputmode="decimal"
                                       class="w-full min-w-0 flex-1 rounded-m border-outline-variant bg-surface text-sm" required>
                                <span class="inline-grid h-[2.375rem] min-w-14 shrink-0 place-items-center rounded-m bg-surface-variant px-2 text-sm font-medium"
                                      aria-label="Shipped unit (read-only): {{ $item->delivery_unit }}">{{ $item->delivery_unit }}</span>
                            </div>
                        </div>

                        {{-- Reason + custody: required ONLY for a short receipt. --}}
                        <div class="space-y-1">
                            <label class="block text-[11px] text-on-surface-variant" for="difference-reason-{{ $item->item_no }}">Reason for Difference</label>
                            <select id="difference-reason-{{ $item->item_no }}" name="difference_reason"
                                    :required="differs"
                                    class="w-full rounded-m border-outline-variant bg-surface text-sm">
                                <option value="">NONE — everything arrived</option>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-[11px] text-on-surface-variant" x-show="differs" x-cloak>Required: something is missing from this item.</p>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-[11px] text-on-surface-variant" for="difference-disposition-{{ $item->item_no }}">Stock Custody Position</label>
                            <select id="difference-disposition-{{ $item->item_no }}" name="difference_disposition"
                                    :required="differs"
                                    :disabled="! differs"
                                    class="w-full rounded-m border-outline-variant bg-surface text-sm disabled:opacity-50">
                                <option value="">— where are the difference goods? —</option>
                                @foreach ($dispositions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-[11px] text-on-surface-variant">Only needed when the received quantity is lower than shipped.</p>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-[11px] text-on-surface-variant" for="remarks-{{ $item->item_no }}">Other Remarks</label>
                            <input id="remarks-{{ $item->item_no }}" type="text" name="remarks" maxlength="255"
                                   placeholder="Optional"
                                   class="w-full rounded-m border-outline-variant bg-surface text-sm">
                        </div>

                        <button type="submit"
                                onclick="return confirm('Confirm receipt for this item? The first confirmation is authoritative and cannot be corrected in this phase.')"
                                class="w-full h-11 rounded-full bg-primary text-on-primary font-semibold text-sm">
                            Confirm Item
                        </button>
                    </form>
                @endif
            </div>
        @endforeach

        @if ($invoice)
            <div class="m-card p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-primary">POD confirmed</p>

                <div class="mt-2 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('finance.invoices.show', $invoice) }}" class="block truncate font-semibold text-primary">
                            Invoice {{ $invoice->invoice_no }}
                        </a>
                        <p class="text-xs text-on-surface-variant">
                            Only the accepted quantity is invoiced. {{ $invoice->items->count() }} line(s).
                        </p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-semibold tabular-nums">{{ number_format((float) $invoice->invoice_amount, 2) }} {{ $invoice->currency }}</p>
                        <span class="m-chip {{ $invoice->payment_status->value === 'PAID' ? 'm-chip-active' : '' }}">
                            {{ str_replace('_', ' ', $invoice->payment_status->value) }}
                        </span>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($invoice->public_token)
                        <a href="{{ route('invoice.public', $invoice->public_token) }}" target="_blank" rel="noopener"
                           class="inline-flex h-10 items-center rounded-full bg-surface-variant px-4 text-xs font-medium text-on-surface-variant">
                            Show QR
                        </a>
                        <a href="{{ route('invoice.public.image', ['token' => $invoice->public_token, 'download' => 1]) }}"
                           class="inline-flex h-10 items-center rounded-full bg-surface-variant px-4 text-xs font-medium text-on-surface-variant">
                            Share invoice
                        </a>
                    @endif
                    @if ((float) $invoice->outstandingAmount() > 0)
                        <a href="{{ route('payments.record.create', $invoice) }}"
                           class="inline-flex h-10 items-center rounded-full bg-primary px-4 text-xs font-semibold text-on-primary">
                            Record payment
                        </a>
                    @endif
                </div>
            </div>
        @elseif ($delivery->delivery_status->value === 'SHIPPED')
            <div class="m-card p-3 text-xs text-on-surface-variant">
                The invoice is generated when the order reaches its billing boundary — every line finally
                dispositioned (POD-confirmed, POD-rejected or demand rejected). Only accepted quantity is ever invoiced.
            </div>
        @endif

        <a href="{{ route('pod.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All confirmations
        </a>
    </div>
</x-app-layout>
