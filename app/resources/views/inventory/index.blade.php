<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Inventory</h1>
                <p class="text-sm text-on-surface-variant">Stock at customers — on hand = unrestricted + restricted</p>
            </div>
            @if (! auth()->user()->isSalesEmployee())
                <form method="POST" action="{{ route('inventory.adjust') }}" class="m-card p-4 space-y-2 max-w-xs">
                    @csrf
                    <p class="text-sm font-semibold">Physical adjustment</p>
                    @php
                        $adjustableHolders = $stockHolders;
                    @endphp
                    <select name="customer_id" required class="block w-full rounded-m border-outline-variant bg-surface text-sm">
                        <option value="">Stock holder…</option>
                        @foreach ($adjustableHolders as $h)
                            <option value="{{ $h->customer_id }}">{{ $h->business_name }} ({{ $h->customer_type->value }})</option>
                        @endforeach
                    </select>
                    <select name="product_id" required class="block w-full rounded-m border-outline-variant bg-surface text-sm">
                        <option value="">Product…</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->product_id }}">{{ $p->product_description }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <input type="number" name="qty" step="0.001" required placeholder="Qty"
                               class="flex-1 rounded-m border-outline-variant bg-surface text-sm">
                        <input type="text" name="unit" value="PCS" required maxlength="20"
                               class="w-20 rounded-m border-outline-variant bg-surface text-sm">
                    </div>
                    <select name="movement_type" class="block w-full rounded-m border-outline-variant bg-surface text-sm">
                        <option value="GOODS_RECEIPT">Goods receipt (+)</option>
                        <option value="ADJUSTMENT">Adjustment (±)</option>
                        <option value="DAMAGE">Damage (−)</option>
                    </select>
                    <input type="text" name="remarks" maxlength="255" placeholder="Remarks (optional)"
                           class="block w-full rounded-m border-outline-variant bg-surface text-sm">
                    <button type="submit" class="w-full h-10 rounded-full bg-primary text-on-primary text-sm font-semibold">
                        Post movement
                    </button>
                </form>
            @endif
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 bg-primary-container text-on-primary-container rounded-m p-3 text-sm">{{ session('status') }}</div>
    @endif

    <form method="GET" action="{{ route('inventory.index') }}" class="m-card p-3 flex flex-wrap gap-2 mb-4 text-sm">
        <select name="customer_id" class="rounded-m border-outline-variant bg-surface">
            <option value="">All stock holders</option>
            @foreach ($stockHolders as $h)
                <option value="{{ $h->customer_id }}" @selected(($filters['customer_id'] ?? '') === $h->customer_id)>
                    {{ $h->business_name }} ({{ $h->customer_type->value }})
                </option>
            @endforeach
        </select>
        <select name="product_id" class="rounded-m border-outline-variant bg-surface">
            <option value="">All products</option>
            @foreach ($products as $p)
                <option value="{{ $p->product_id }}" @selected(($filters['product_id'] ?? '') === $p->product_id)>{{ $p->product_description }}</option>
            @endforeach
        </select>
        <select name="customer_type" class="rounded-m border-outline-variant bg-surface">
            <option value="">All holder types</option>
            @foreach (['PRIMARY', 'SHIP_TO', 'VAN'] as $t)
                <option value="{{ $t }}" @selected(($filters['customer_type'] ?? '') === $t)>{{ $t }}</option>
            @endforeach
        </select>
        <button type="submit" class="h-10 px-4 rounded-full bg-secondary-container text-on-secondary-container font-medium">Filter</button>
        <a href="{{ route('inventory.counts.create') }}" class="h-10 px-4 inline-flex items-center rounded-full bg-secondary-container text-on-secondary-container font-medium">New stock count</a>
    </form>

    @if ($rows->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium">No inventory rows visible</p>
            <p class="text-sm text-on-surface-variant mt-1">Post a goods receipt or run an authoritative primary count to establish stock.</p>
        </div>
    @else
        <div class="m-card divide-y divide-outline-variant">
            @foreach ($rows as $row)
                <div class="p-4 flex items-center justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium truncate">{{ $row->product->product_description ?? $row->product_id }}</p>
                        <p class="text-xs text-on-surface-variant truncate">
                            {{ $row->customer->business_name ?? $row->customer_id }}
                            ({{ $row->customer?->customer_type->value ?? '?' }})
                        </p>
                    </div>
                    <div class="text-right shrink-0 tabular-nums">
                        <p class="font-semibold">{{ number_format((float) $row->onHandQty(), 3) }} {{ $row->basic_unit }} <span class="text-xs text-on-surface-variant font-normal">on hand</span></p>
                        <p class="text-xs text-on-surface-variant">
                            unrestricted {{ number_format((float) $row->unrestricted_qty, 3) }}
                            · restricted {{ number_format((float) $row->restricted_qty, 3) }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
