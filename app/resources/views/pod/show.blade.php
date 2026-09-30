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

        @foreach ($delivery->items as $item)
            <div class="m-card p-4 space-y-3">
                @php
                    // Composite-keyed relation is not loadable — query directly.
                    $confirmation = \App\Models\DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)
                        ->where('delivery_item_no', $item->item_no)->first();
                @endphp
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">
                            {{ $item->product->product_description ?? $item->product_id }}
                            @if ($item->is_free_item)<span class="m-chip m-chip-active ml-1">FREE</span>@endif
                        </p>
                        <p class="text-xs text-on-surface-variant">shipped {{ $item->allocated_qty }} {{ $item->delivery_unit }}</p>
                    </div>
                    @if ($confirmation)
                        <span class="m-chip {{ $confirmation->confirmation_status->value === 'CONFIRMED' ? 'm-chip-active' : 'm-chip-error' }}">
                            {{ $confirmation->confirmation_status->value }} · {{ $confirmation->confirmed_qty }} {{ $confirmation->confirmed_unit }}
                            @if ($confirmation->difference_qty !== '0.000')
                                · diff {{ $confirmation->difference_qty }} {{ $confirmation->difference_unit }} ({{ $confirmation->difference_reason->value }})
                            @endif
                        </span>
                    @endif
                </div>

                @if (! $confirmation)
                    <form method="POST" action="{{ route('pod.confirm', $delivery) }}" class="space-y-2">
                        @csrf
                        <input type="hidden" name="delivery_item_no" value="{{ $item->item_no }}">
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

                        <div class="flex gap-2 items-center">
                            <input type="number" name="confirmed_qty" step="0.001" min="0"
                                   value="{{ rtrim(rtrim((string) $item->allocated_qty, '0'), '.') }}"
                                   max="{{ rtrim(rtrim((string) $item->allocated_qty, '0'), '.') }}"
                                   class="w-32 rounded-m border-outline-variant bg-surface text-sm" required>
                            <select name="confirmed_unit" class="rounded-m border-outline-variant bg-surface text-sm">
                                @foreach (['PCS', 'CTN', 'KG', 'TON'] as $u)
                                    <option value="{{ $u }}" @selected($u === $item->delivery_unit)>{{ $u }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex gap-2 items-center">
                            <select name="difference_reason" class="rounded-m border-outline-variant bg-surface text-xs">
                                <option value="">— reason if not everything arrived —</option>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="remarks" maxlength="255" placeholder="Remarks (optional)"
                                   class="flex-1 rounded-m border-outline-variant bg-surface text-xs">
                        </div>

                        @php
                            $dispositions = [
                                'WITH_EMPLOYEE' => 'with me (intact)',
                                'DAMAGED' => 'with me (damaged)',
                                'AT_SOURCE' => 'handed back at source',
                                'UNKNOWN' => 'unknown whereabouts',
                            ];
                        @endphp
                        <select name="difference_disposition" class="w-full rounded-m border-outline-variant bg-surface text-xs">
                            <option value="">— where are the difference goods? (required for a difference) —</option>
                            @foreach ($dispositions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        <button type="submit"
                                onclick="return confirm('Confirm receipt for this item? The first confirmation is authoritative and cannot be corrected in this phase.')"
                                class="w-full h-10 rounded-full bg-primary text-on-primary font-semibold text-sm">
                            Confirm item
                        </button>
                    </form>
                @endif
            </div>
        @endforeach

        <a href="{{ route('pod.index') }}"
           class="w-full h-12 inline-flex items-center justify-center rounded-full bg-secondary-container text-on-secondary-container font-semibold">
            All confirmations
        </a>
    </div>
</x-app-layout>
