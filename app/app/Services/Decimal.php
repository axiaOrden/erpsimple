<?php

namespace App\Services;

/**
 * Fixed-point decimal arithmetic on strings (bcmath is not guaranteed on the
 * target runtime). All monetary math in services goes through this helper so
 * float error never leaks into invoices.
 */
final class Decimal
{
    public static function add(string $a, string $b, int $scale = 2): string
    {
        return self::format((float) $a + (float) $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = 2): string
    {
        return self::format((float) $a - (float) $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = 2): string
    {
        return self::format((float) $a * (float) $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = 2): string
    {
        if ((float) $b === 0.0) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return self::format((float) $a / (float) $b, $scale);
    }

    /** -1, 0, 1 */
    public static function compare(string $a, string $b, int $scale = 2): int
    {
        $left = round((float) $a, $scale);
        $right = round((float) $b, $scale);

        return $left <=> $right;
    }

    public static function format(float $value, int $scale = 2): string
    {
        return number_format(round($value, $scale), $scale, '.', '');
    }

    /** Human-facing quantity: drop redundant trailing zeros (120.000 → 120). */
    public static function trimZeros(string $value): string
    {
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
