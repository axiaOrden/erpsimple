<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Phone normalization (field customer registration).
 *
 * Ruling: the country dial code is FIXED (config `erp.dial_code`, Nigeria
 * +234) and is not editable in the field UI. The local subscriber number is
 * captured WITHOUT a leading zero. Equivalent inputs therefore collapse to a
 * single canonical form:
 *
 *   08012345678      → +2348012345678
 *   8012345678       → +2348012345678
 *   +2348012345678   → +2348012345678
 *   2348012345678    → +2348012345678
 *   234 801 234 5678 → +2348012345678
 *
 * Normalization is SERVER-AUTHORITATIVE: the browser only assists capture.
 */
class PhoneNumberService
{
    public function __construct(private readonly Repository $config) {}

    public function dialCode(): string
    {
        $dial = (string) $this->config->get('erp.dial_code', '+234');

        return str_starts_with($dial, '+') ? $dial : '+'.$dial;
    }

    public function countryName(): string
    {
        return (string) $this->config->get('erp.country_name', 'Nigeria');
    }

    /**
     * Canonical form of any accepted spelling, or null when the input cannot
     * be interpreted as a local number of this country.
     */
    public function canonical(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Keep digits only (spaces, dashes, parentheses, dots are formatting).
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        $dialDigits = ltrim($this->dialCode(), '+');

        if (str_starts_with($digits, $dialDigits)) {
            $digits = substr($digits, strlen($dialDigits));
        } elseif (str_starts_with($digits, '0')) {
            // National trunk prefix (0801…) — never part of the canonical form.
            $digits = ltrim($digits, '0');
        }

        if (! $this->isValidLocal($digits)) {
            return null;
        }

        return $this->dialCode().$digits;
    }

    /** The subscriber part of a canonical number (8012345678). */
    public function localPart(?string $canonical): ?string
    {
        if ($canonical === null) {
            return null;
        }

        return substr($canonical, strlen($this->dialCode()));
    }

    /** Human spacing for display: +234 801 234 5678. */
    public function format(?string $canonical): ?string
    {
        $local = $this->localPart($canonical);

        if ($local === null || $local === '') {
            return $canonical;
        }

        return $this->dialCode().' '.trim(chunk_split($local, 3, ' '));
    }

    /**
     * Is this canonical number an acceptable one?
     *
     * Nigerian subscriber numbers are 10 digits after the dial code (0801…);
     * the expected length is configuration so a country-profile change does
     * not silently accept nonsense.
     */
    public function isValidCanonical(?string $canonical): bool
    {
        return $this->isValidLocal($this->localPart($canonical));
    }

    /** National significant digits: exact length, no trunk leading zero. */
    public function isValidLocal(?string $digits): bool
    {
        return $digits !== null
            && strlen($digits) === $this->localLength()
            && preg_match('/^[1-9]\d*$/', $digits) === 1;
    }

    /** Expected national significant digits (config `erp.phone_local_digits`). */
    public function localLength(): int
    {
        $length = (int) $this->config->get('erp.phone_local_digits', 10);

        return $length > 0 ? $length : 10;
    }

    public function maybeCanonical(?string $raw): ?string
    {
        return $this->canonical($raw);
    }
}
