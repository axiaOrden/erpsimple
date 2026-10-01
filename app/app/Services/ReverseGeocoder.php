<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;

/**
 * Reverse geocoding for ADDRESS SUGGESTION only (customer registration).
 *
 * Rulings: coordinates always come from the device capture and are never
 * derived from, or replaced by, a geocoder response; the address text is a
 * convenience the employee may correct. A geocoder failure is therefore
 * non-fatal — the response simply reports `ok: false` and the form keeps the
 * captured coordinates.
 *
 * Called server-side so the provider, timeout and user agent are controlled
 * (and can be faked in tests / disabled in production).
 */
class ReverseGeocoder
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array{
     *     ok: bool, address: ?string, city: ?string, state: ?string,
     *     postal_code: ?string, country: ?string, display_name: ?string
     * }
     */
    public function lookup(float $latitude, float $longitude): array
    {
        $empty = [
            'ok' => false,
            'address' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'display_name' => null,
        ];

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $empty;
        }

        $url = (string) $this->config->get('erp.geocode.url');

        if ($url === '') {
            return $empty;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $this->config->get('erp.geocode.user_agent', 'SimpleERP'),
                'Accept' => 'application/json',
            ])
                ->timeout((int) $this->config->get('erp.geocode.timeout', 8))
                ->get($url, [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable) {
            return $empty;
        }

        if (! $response->successful()) {
            return $empty;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return $empty;
        }

        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

        $street = trim(implode(' ', array_filter([
            $address['house_number'] ?? null,
            $address['road'] ?? null,
            $address['neighbourhood'] ?? null,
        ])));

        return [
            'ok' => true,
            'address' => $street !== '' ? $street : ($address['suburb'] ?? $address['village'] ?? null),
            'city' => $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? null,
            'state' => $address['state'] ?? $address['state_district'] ?? $address['region'] ?? null,
            'postal_code' => $address['postcode'] ?? null,
            'country' => $address['country'] ?? null,
            'display_name' => $payload['display_name'] ?? null,
        ];
    }
}
