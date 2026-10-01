<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Orders</h1>
                <p class="text-xs text-on-surface-variant">
                    Ongoing = still has work for you (demand, delivery, POD or payment)
                </p>
            </div>
            <a href="{{ route('orders.create') }}"
               class="inline-flex h-10 items-center gap-2 rounded-full bg-primary px-4 text-sm font-semibold text-on-primary shadow-m1">
                + New order
            </a>
        </div>
    </x-slot>

    <form method="GET" action="{{ route('orders.index') }}" class="m-card mb-3 space-y-3 p-3">
        <input type="hidden" name="tab" value="{{ $tab }}">

        <div class="flex gap-2">
            <div class="flex-1">
                <label for="from" class="block text-[11px] text-on-surface-variant">From</label>
                <input id="from" type="date" name="from" value="{{ $from->toDateString() }}"
                       class="mt-1 w-full rounded-m border-outline-variant bg-surface text-sm">
            </div>
            <div class="flex-1">
                <label for="to" class="block text-[11px] text-on-surface-variant">To</label>
                <input id="to" type="date" name="to" value="{{ $to->toDateString() }}"
                       class="mt-1 w-full rounded-m border-outline-variant bg-surface text-sm">
            </div>
        </div>

        <div class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search order no or customer…"
                   class="w-full rounded-full border-outline-variant bg-surface px-4 text-sm">
            <button type="submit" class="h-11 shrink-0 rounded-full bg-secondary-container px-4 text-sm font-semibold text-on-secondary-container">
                Apply
            </button>
        </div>
    </form>

    <div class="mb-3 grid grid-cols-2 gap-1 rounded-full bg-surface-container p-1">
        <a href="{{ route('orders.index', array_merge(request()->query(), ['tab' => 'ONGOING', 'page' => 1])) }}"
           class="inline-flex h-10 items-center justify-center rounded-full text-sm font-semibold {{ $tab === 'ONGOING' ? 'bg-primary text-on-primary' : 'text-on-surface-variant' }}">
            Ongoing · {{ $counts['ONGOING'] }}
        </a>
        <a href="{{ route('orders.index', array_merge(request()->query(), ['tab' => 'COMPLETED', 'page' => 1])) }}"
           class="inline-flex h-10 items-center justify-center rounded-full text-sm font-semibold {{ $tab === 'COMPLETED' ? 'bg-primary text-on-primary' : 'text-on-surface-variant' }}">
            Completed · {{ $counts['COMPLETED'] }}
        </a>
    </div>

    @if ($rows->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium">No {{ strtolower($tab) }} orders in this window</p>
            <p class="mt-1 text-sm text-on-surface-variant">
                The default window is today. Widen the dates to look further back.
            </p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($rows as $row)
                @php
                    $order = $row['order'];
                    $analysis = $row['analysis'];
                    $invoice = $order->invoice;
                @endphp
                <a href="{{ route('orders.show', $order) }}" class="m-card block p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $order->soldToCustomer->business_name ?? $order->sold_to_customer_id }}</p>
                            <p class="text-[11px] text-on-surface-variant">
                                {{ $order->sales_order_no }}
                                · {{ $order->order_date?->translatedFormat('d M Y') }}
                                · via {{ $order->supplyingCustomer->business_name ?? $order->supplying_customer_id }}
                            </p>
                        </div>
                        <span class="m-chip shrink-0 {{ ($analysis['state'] ?? '') === 'ONGOING' ? 'm-chip-active' : '' }}">
                            {{ $analysis['state'] ?? '—' }}
                        </span>
                    </div>

                    <dl class="mt-2 grid grid-cols-3 gap-2 text-[11px]">
                        <div>
                            <dt class="text-on-surface-variant">Order value</dt>
                            <dd class="font-semibold tabular-nums">{{ number_format((float) $order->net_amount, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-variant">Order status</dt>
                            <dd class="font-semibold">{{ $order->order_status->value }}</dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-variant">Payment status</dt>
                            <dd class="font-semibold">
                                {{ $invoice ? str_replace('_', ' ', $invoice->payment_status->value) : 'no invoice yet' }}
                                @if ($invoice && (float) $invoice->outstandingAmount() > 0)
                                    <span class="block text-on-surface-variant">{{ number_format((float) $invoice->outstandingAmount(), 2) }} outstanding</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($analysis && $analysis['reasons'] !== [])
                        <p class="mt-2 text-[11px] text-on-surface-variant">
                            Next: {{ implode(' · ', $analysis['reasons']) }}
                        </p>
                    @elseif ($analysis)
                        <p class="mt-2 text-[11px] text-on-surface-variant">Nothing left to do on this order.</p>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="mt-4 flex items-center justify-between gap-3">
            <p class="text-xs text-on-surface-variant">
                Showing {{ $rows->count() }} of {{ $paginator->total() }}
            </p>
            @if ($paginator->hasMorePages())
                <a href="{{ route('orders.index', array_merge(request()->query(), ['per_page' => $nextPerPage, 'page' => 1])) }}"
                   class="inline-flex h-11 items-center justify-center rounded-full bg-primary px-5 text-sm font-semibold text-on-primary">
                    Load more
                </a>
            @endif
        </div>
    @endif
</x-app-layout>
