<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Fixed Journey Plan rotation (business correction to rule 5).
 *
 * `customer_fjp.preferred_week` is NOT week-of-month. It is a position in a
 * continuous 4-week field-sales rotation that runs all year:
 *
 *     Week 1 → 2 → 3 → 4 → 1 → 2 → 3 → 4 …
 *
 * The cycle is anchored to a configured Monday (config `fjp.rotation_anchor`,
 * env `FJP_ROTATION_ANCHOR`) which is defined as Rotation Week 1, Day 1
 * (Monday). Rotation week for any date:
 *
 *     rotation_week = floor(weeks_since_anchor % 4) + 1
 *
 * This stays continuous across month boundaries, year boundaries and
 * five-week months because it never consults the calendar month.
 */
class FjpRotationService
{
    public const WEEKS_IN_ROTATION = 4;

    public function __construct(private readonly Repository $config) {}

    /** The anchor Monday (Rotation Week 1 begins here). */
    public function anchor(): Carbon
    {
        $raw = (string) $this->config->get('fjp.rotation_anchor', '2026-01-05');

        try {
            $anchor = Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("fjp.rotation_anchor must be a valid YYYY-MM-DD date, got '$raw'.");
        }

        if ($anchor === false) {
            throw new InvalidArgumentException("fjp.rotation_anchor must be a valid YYYY-MM-DD date, got '$raw'.");
        }

        if ($anchor->format('l') !== 'Monday') {
            throw new InvalidArgumentException(
                'fjp.rotation_anchor must be a Monday (rotation week 1 starts on Monday), got '
                .$anchor->format('Y-m-d').' ('.$anchor->format('l').').',
            );
        }

        return $anchor;
    }

    /**
     * Rotation week (1–4) for the given date. The anchor's week is week 1.
     */
    public function rotationWeek(Carbon $date): int
    {
        $anchor = $this->anchor();

        // Anchor day itself is week 1; weeks elapsed since anchor week start.
        // Carbon 3: $from->diffInDays($to) = $to - $from, so anchor first.
        $weeksSince = intdiv($anchor->diffInDays($date->startOfDay()), 7);

        // PHP diffInDays can be negative for dates before the anchor; mod on
        // negatives must wrap correctly, hence the double-mod below.
        $position = ((int) $weeksSince % self::WEEKS_IN_ROTATION + self::WEEKS_IN_ROTATION) % self::WEEKS_IN_ROTATION;

        return $position + 1;
    }

    /**
     * Does a plan with the given preferred_week match the given date?
     * NULL preferred_week = every week on the configured day.
     */
    public function matchesWeek(?int $preferredWeek, Carbon $date): bool
    {
        return $preferredWeek === null || $preferredWeek === $this->rotationWeek($date);
    }
}
