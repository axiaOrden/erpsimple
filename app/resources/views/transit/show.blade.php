<x-app-layout>
    <x-slot name="header">
        <div>
            <h1>Transit {{ $transit->transit_id }}</h1>
            <p class="text-sm text-on-surface-variant">{{ $transit->product->product_description ?? $transit->product_id }}</p>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="m-card p-3 mb-3 text-sm border-l-4 border-l-primary">{{ session('status') }}</div>
    @endif

    <div class="m-card p-4 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-on-surface-variant">Quantity (open / original)</span>
            <span class="tabular-nums font-semibold">{{ rtrim(rtrim((string) $transit->quantity, '0'), '.') }} /
                {{ rtrim(rtrim((string) $transit->original_quantity, '0'), '.') }} {{ $transit->basic_unit }}</span></div>
        <div class="flex justify-between"><span class="text-on-surface-variant">Status</span>
            <span class="font-semibold">{{ $transit->transit_status->value }}</span></div>
        @if ($transit->liability_party->value !== 'NONE')
            <div class="flex justify-between"><span class="text-on-surface-variant">Liability</span>
                <span>{{ $transit->liability_party->value }}</span></div>
        @endif
        <div class="flex justify-between"><span class="text-on-surface-variant">Held by</span>
            <span>{{ $transit->holding_employee_id }}</span></div>
        <div class="flex justify-between"><span class="text-on-surface-variant">Source</span>
            <span>{{ $transit->source_customer_id }}</span></div>
        <div class="flex justify-between"><span class="text-on-surface-variant">Origin</span>
            <span class="text-right">{{ $transit->origin_shipment_no }} · {{ $transit->origin_delivery_no }} · item {{ $transit->origin_delivery_item_no }}</span></div>
        @if ($transit->parent_transit_id)
            <div class="flex justify-between"><span class="text-on-surface-variant">Split from</span>
                <span>#{{ $transit->parent_transit_id }}</span></div>
        @endif
        @if ($transit->resolved_to_delivery_no)
            <div class="flex justify-between"><span class="text-on-surface-variant">Reallocated to</span>
                <span>{{ $transit->resolved_to_delivery_no }}</span></div>
        @endif
        @if ($transit->claimed_by_employee_id)
            <div class="flex justify-between"><span class="text-on-surface-variant">Return claimed by</span>
                <span>{{ $transit->claimed_by_employee_id }}</span></div>
        @endif
        @if ($transit->verified_by_employee_id)
            <div class="flex justify-between"><span class="text-on-surface-variant">Verified by</span>
                <span>{{ $transit->verified_by_employee_id }}</span></div>
        @endif
        @if ($transit->remarks)
            <p class="text-xs text-on-surface-variant pt-1 border-t border-outline-variant">{{ $transit->remarks }}</p>
        @endif
    </div>

    @if ($transit->transit_status->value === 'REUSABLE' && $canAct)
        <form method="POST" action="{{ route('transit.return', $transit) }}"
              onsubmit="return confirm('Claim this quantity was handed back at the source? A company admin must verify before source stock is restored.')"
              class="mt-4">
            @csrf
            <input type="text" name="remarks" placeholder="Remarks (optional)" maxlength="255"
                   class="w-full rounded-m border-outline-variant bg-surface text-sm mb-2">
            <button type="submit" class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                Initiate return to source
            </button>
        </form>
    @endif

    @if ($transit->transit_status->value === 'PENDING_SOURCE_RECEIPT' && $isAdmin)
        @if (auth()->user()->employee_id && auth()->user()->employee_id === $transit->claimed_by_employee_id)
            <div class="m-card p-3 mt-4 text-xs text-error">
                The employee who claimed the return cannot verify their own receipt.
            </div>
        @else
            <form method="POST" action="{{ route('transit.verify-receipt', $transit) }}"
                  onsubmit="return confirm('Verify the goods physically arrived at {{ $transit->source_customer_id }}? This writes a VAN_RETURN movement and restores source availability.')"
                  class="mt-4">
                @csrf
                <button type="submit" class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                    Verify source receipt
                </button>
            </form>
        @endif
    @endif

    @if ($transit->transit_status->value === 'DISCREPANCY')
        <div class="m-card p-4 mt-4 space-y-2">
            <p class="text-sm font-medium">Resolve discrepancy</p>
            @if ($canAct || $isAdmin)
                <form method="POST" action="{{ route('transit.resolve-found', $transit) }}"
                      onsubmit="return confirm('Mark as found intact (becomes REUSABLE)?')">
                    @csrf
                    <button type="submit" class="w-full h-11 rounded-full border border-outline-variant text-sm font-medium">Found intact</button>
                </form>
                <form method="POST" action="{{ route('transit.resolve-damaged', $transit) }}"
                      onsubmit="return confirm('Mark as damaged?')">
                    @csrf
                    <select name="liability_party" class="w-full rounded-m border-outline-variant bg-surface text-sm mb-2">
                        <option value="EMPLOYEE">Employee liability</option>
                        <option value="DISTRIBUTOR">Primary/distributor liability</option>
                    </select>
                    <button type="submit" class="w-full h-11 rounded-full border border-outline-variant text-sm font-medium">Confirmed damaged</button>
                </form>
            @endif
            @if ($isAdmin)
                <form method="POST" action="{{ route('transit.resolve-lost', $transit) }}"
                      onsubmit="return confirm('Mark as lost/short? Liability accounting is deferred.')">
                    @csrf
                    <button type="submit" class="w-full h-11 rounded-full border border-outline-variant text-sm font-medium text-error">Confirmed lost (admin)</button>
                </form>
            @endif
        </div>
    @endif

    @if ($transit->transit_status->value === 'DAMAGED' && $isAdmin)
        <form method="POST" action="{{ route('transit.write-off', $transit) }}"
              onsubmit="return confirm('Write off this damaged stock? Terminal action.')"
              class="mt-4">
            @csrf
            <input type="text" name="remarks" placeholder="Write-off remarks (optional)" maxlength="255"
                   class="w-full rounded-m border-outline-variant bg-surface text-sm mb-2">
            <button type="submit" class="w-full h-12 rounded-full bg-error text-on-error font-semibold shadow-m1">
                Write off
            </button>
        </form>
    @endif
</x-app-layout>
