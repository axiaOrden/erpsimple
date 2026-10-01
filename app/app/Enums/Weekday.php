<?php

namespace App\Enums;

/**
 * Weekday as stored in customer_fjp.preferred_day.
 *
 * The column is a compact numeric weekday index — the same convention as
 * JavaScript's Date#getDay() and PHP's Carbon dayOfWeek:
 *
 *     0 = Sunday … 6 = Saturday
 *
 * The database NEVER stores a combined string such as `W1-Mon`: the rotation
 * week lives in preferred_week (nullable = every week) and the weekday here.
 * `W1-Mon` chips are presentation only.
 */
enum Weekday: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;

    /** Full English label ("Monday"). */
    public function label(): string
    {
        return $this->name;
    }

    /** Three-letter label for compact chips ("Mon"). */
    public function short(): string
    {
        return mb_substr($this->name, 0, 3);
    }

    /** Monday-first ordering used by pickers and lists. */
    public static function ordered(): array
    {
        return [self::Monday, self::Tuesday, self::Wednesday, self::Thursday, self::Friday, self::Saturday, self::Sunday];
    }

    /** Enum name ("MONDAY") or numeric index → case, or null when unknown. */
    public static function parse(int|string|null $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return self::tryFrom((int) $value);
        }

        return self::tryFromName((string) $value);
    }

    /** Legacy enum-style weekday name ("monday", "MONDAY") → case. */
    public static function tryFromName(string $name): ?self
    {
        $target = strtoupper(trim($name));

        foreach (self::cases() as $case) {
            if (strtoupper($case->name) === $target) {
                return $case;
            }
        }

        return null;
    }
}
