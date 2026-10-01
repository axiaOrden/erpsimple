@props([
    'unit' => 'CTN',
    'open' => 0,
    'allocated' => 0,
    'unpaid' => 0,
    'paid' => 0,
    'rejected' => 0,
])

@php
    /**
     * Per ORDER QUANTITY UNIT quantity pipeline (Home):
     *
     *   ORDER (not allocated) | PROCESSING (allocated → invoiced → settled) | REJECTED
     *
     * The track is scaled to the unit's DEMAND, so every segment length IS the
     * quantity it stands for — nothing decorative. The buckets are mutually
     * exclusive and conserve demand exactly once:
     *
     *   open + allocated + unpaid + paid + rejected = demand
     *
     * Rejected means TERMINALLY rejected quantity that was NOT delivered: it
     * never also counts as open. Colour never carries the meaning alone — each
     * bucket is repeated below with its label, its meaning and its number, and
     * the aria-label spells the whole split out for screen readers.
     */
    $openQty = max(0.0, (float) $open);
    $rejectedQty = max(0.0, (float) $rejected);

    $processingSegments = [
        ['key' => 'allocated', 'label' => 'Allocated', 'meaning' => 'allocated / processing', 'hint' => 'allocated, awaiting POD', 'value' => max(0.0, (float) $allocated), 'class' => 'bg-amber-400'],
        ['key' => 'unpaid', 'label' => 'Delivered · unpaid', 'meaning' => 'delivered / unpaid', 'hint' => 'accepted + invoiced, outstanding', 'value' => max(0.0, (float) $unpaid), 'class' => 'bg-sky-600'],
        ['key' => 'paid', 'label' => 'Delivered · paid', 'meaning' => 'delivered / paid', 'hint' => 'accepted + invoiced, settled', 'value' => max(0.0, (float) $paid), 'class' => 'bg-emerald-600'],
    ];

    $processing = array_sum(array_column($processingSegments, 'value'));
    $lifecycle = $openQty + $processing;
    $demand = $lifecycle + $rejectedQty;

    // Share of the unit's demand — the rendered width.
    $share = fn (float $value): float => $demand > 0 ? ($value / $demand) * 100 : 0.0;

    // Inner widths are shares of the processing group; because the group itself
    // is the processing share of the demand, each colour keeps its true
    // proportion of the unit's quantity.
    $innerShare = fn (float $value): float => $processing > 0 ? ($value / $processing) * 100 : 0.0;

    $fmt = fn (float $value): string => number_format($value, 0);
    $pct = fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

    $aria = sprintf(
        '%s: order %s %s open and unallocated; processing %s %s: %s; rejected and not delivered %s %s.',
        $unit,
        $fmt($openQty),
        $unit,
        $fmt($processing),
        $unit,
        collect($processingSegments)->map(fn ($s) => $s['label'].' '.$fmt($s['value']).' '.$unit)->implode(', '),
        $fmt($rejectedQty),
        $unit,
    );
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1']) }}
     data-unit-pipeline="{{ $unit }}"
     data-unit="{{ $unit }}"
     data-order-open="{{ $fmt($openQty) }}"
     data-order-open-share="{{ $pct($share($openQty)) }}"
     data-processing-total="{{ $fmt($processing) }}"
     data-processing-share="{{ $pct($share($processing)) }}"
     data-allocated="{{ $fmt($processingSegments[0]['value']) }}"
     data-allocated-share="{{ $pct($share($processingSegments[0]['value'])) }}"
     data-unpaid="{{ $fmt($processingSegments[1]['value']) }}"
     data-unpaid-share="{{ $pct($share($processingSegments[1]['value'])) }}"
     data-paid="{{ $fmt($processingSegments[2]['value']) }}"
     data-paid-share="{{ $pct($share($processingSegments[2]['value'])) }}"
     data-lifecycle-total="{{ $fmt($lifecycle) }}"
     data-rejected="{{ $fmt($rejectedQty) }}"
     data-rejected-share="{{ $pct($share($rejectedQty)) }}"
     data-demand-total="{{ $fmt($demand) }}">

    {{-- Proportional track: ORDER | PROCESSING | REJECTED (widths are quantities). --}}
    <div class="flex h-3 w-full overflow-hidden rounded-full bg-surface-variant" role="img" aria-label="{{ $aria }}">
        @if ($demand <= 0)
            <span class="w-full opacity-0"></span>
        @else
            <span class="{{ $openQty > 0 ? 'bg-orange-500' : '' }} {{ $openQty > 0 && ($processing > 0 || $rejectedQty > 0) ? 'border-r-2 border-surface' : '' }}"
                  style="width: {{ $pct($share($openQty)) }}%"
                  title="Order · open, not allocated · {{ $fmt($openQty) }} {{ $unit }}"></span>

            <span class="flex" style="width: {{ $pct($share($processing)) }}%">
                @foreach ($processingSegments as $segment)
                    @if ($segment['value'] > 0)
                        <span class="{{ $segment['class'] }}"
                              style="width: {{ $pct($innerShare($segment['value'])) }}%"
                              title="{{ $segment['label'] }} · {{ $fmt($segment['value']) }} {{ $unit }}"></span>
                    @endif
                @endforeach
            </span>

            @if ($rejectedQty > 0)
                <span class="bg-red-600 {{ $processing > 0 ? 'border-l-2 border-surface' : '' }}"
                      style="width: {{ $pct($share($rejectedQty)) }}%"
                      title="Rejected / not delivered · {{ $fmt($rejectedQty) }} {{ $unit }}"></span>
            @endif
        @endif
    </div>

    {{-- The sides, always readable as numbers — never colour alone. --}}
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">Order</p>
            <p class="text-sm font-semibold tabular-nums">
                {{ $fmt($openQty) }} <span class="text-[10px] font-normal text-on-surface-variant">{{ $unit }}</span>
            </p>
            <p class="text-[10px] leading-tight text-on-surface-variant">open · not allocated</p>
        </div>
        <div class="min-w-0 text-right">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">Processing</p>
            <p class="text-sm font-semibold tabular-nums">
                {{ $fmt($processing) }} <span class="text-[10px] font-normal text-on-surface-variant">{{ $unit }}</span>
            </p>
            <p class="text-[10px] leading-tight text-on-surface-variant">allocated → invoiced → settled</p>
            @if ($rejectedQty > 0)
                <p class="text-[10px] leading-tight font-medium tabular-nums text-error">
                    {{ $fmt($rejectedQty) }} {{ $unit }} rejected · not delivered
                </p>
            @endif
        </div>
    </div>

    {{-- A compact legend, not a table: one row per bucket on a phone, and on
         wider screens the label stays next to its own number. --}}
    <dl class="grid grid-cols-1 gap-y-0.5 text-[11px] sm:flex sm:flex-wrap sm:items-center sm:gap-x-5">
        <div class="flex min-w-0 items-center gap-1.5">
            <span class="h-2 w-2 shrink-0 rounded-full bg-orange-500" aria-hidden="true"></span>
            <dt class="text-on-surface-variant" title="open / not allocated">Open · not allocated</dt>
            <dd class="ml-auto shrink-0 font-medium tabular-nums sm:ml-0 {{ $openQty > 0 ? '' : 'text-on-surface-variant/60' }}">
                {{ $fmt($openQty) }}
            </dd>
        </div>
        @foreach ($processingSegments as $segment)
            <div class="flex min-w-0 items-center gap-1.5">
                <span class="h-2 w-2 shrink-0 rounded-full {{ $segment['class'] }}" aria-hidden="true"></span>
                <dt class="text-on-surface-variant" title="{{ $segment['hint'] }}">{{ $segment['label'] }}</dt>
                <dd class="ml-auto shrink-0 font-medium tabular-nums sm:ml-0 {{ $segment['value'] > 0 ? '' : 'text-on-surface-variant/60' }}">
                    {{ $fmt($segment['value']) }}
                </dd>
            </div>
        @endforeach
        <div class="flex min-w-0 items-center gap-1.5">
            <span class="h-2 w-2 shrink-0 rounded-full bg-red-600" aria-hidden="true"></span>
            <dt class="text-on-surface-variant" title="terminally rejected and not delivered">Rejected · not delivered</dt>
            <dd class="ml-auto shrink-0 font-medium tabular-nums sm:ml-0 {{ $rejectedQty > 0 ? '' : 'text-on-surface-variant/60' }}">
                {{ $fmt($rejectedQty) }}
            </dd>
        </div>
    </dl>
</div>
