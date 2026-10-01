@props([
    'week' => null,
    'day' => null,
])

@php
    /**
     * Compact preferred-visit chip. The database stores preferred_week (numeric
     * rotation position, null = every week) and preferred_day (0 = Sunday …
     * 6 = Saturday) separately; the combined `W1-Mon` form is PRESENTATION
     * ONLY, rendered here, never stored.
     *
     * Colour is never the only carrier: the chip shows the text `W1-Mon` and
     * spells the schedule out in its accessible label / tooltip.
     */
    $weekday = \App\Enums\Weekday::parse($day);
    $hasWeek = $week !== null && $week !== '';
    $chip = $weekday === null ? '—' : ($hasWeek ? 'W'.$week.'-'.$weekday->short() : 'Every '.$weekday->short());
    $full = $weekday === null
        ? 'Weekday not set'
        : ($hasWeek ? 'Week '.$week.' · '.$weekday->label() : 'Every week · '.$weekday->label());
@endphp

<span {{ $attributes->merge(['class' => 'm-chip tabular-nums']) }}
      aria-label="{{ $full }}" title="{{ $full }}">{{ $chip }}</span>
