<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Field-sales country profile
    |--------------------------------------------------------------------------
    |
    | Customer phone numbers are captured with a FIXED country dial code and
    | a local subscriber number (no leading zero). The dial code is not
    | editable in the field UI: normalization is server-authoritative.
    |
    */

    'country_code' => env('ERP_COUNTRY_CODE', 'NG'),
    'dial_code' => env('ERP_DIAL_CODE', '+234'),
    'country_name' => env('ERP_COUNTRY_NAME', 'Nigeria'),
    // National significant digits after the dial code (Nigeria: 8012345678).
    'phone_local_digits' => (int) env('ERP_PHONE_LOCAL_DIGITS', 10),

    /*
    |--------------------------------------------------------------------------
    | Reverse geocoding (address suggestion only)
    |--------------------------------------------------------------------------
    |
    | Used to PRE-FILL address/city/state/postal code during customer
    | registration. Coordinates are never derived from it, and a failure is
    | non-fatal — the employee may correct the text fields by hand.
    |
    */

    'map' => [
        // Raster basemap tiles (CARTO light) — no API key, no vendor SDK lock-in.
        'tile_url' => env('ERP_MAP_TILE_URL', 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png'),
        'attribution' => env('ERP_MAP_ATTRIBUTION', '© OpenStreetMap contributors © CARTO'),
        'zoom' => (int) env('ERP_MAP_ZOOM', 16),
    ],

    'geocode' => [
        'url' => env('ERP_GEOCODE_URL', 'https://nominatim.openstreetmap.org/reverse'),
        'user_agent' => env('ERP_GEOCODE_UA', 'SimpleERP/1.0 (field sales)'),
        'timeout' => (int) env('ERP_GEOCODE_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer invoice image (JPG for phone sharing)
    |--------------------------------------------------------------------------
    */

    'invoice_image' => [
        'width' => 1240,
        'quality' => 88,
        'font_candidates' => array_values(array_filter([
            env('ERP_INVOICE_FONT'),
            resource_path('fonts/DejaVuSans.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
        ])),
        'font_bold_candidates' => array_values(array_filter([
            env('ERP_INVOICE_FONT_BOLD'),
            resource_path('fonts/DejaVuSans-Bold.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ])),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment proof evidence
    |--------------------------------------------------------------------------
    */

    'payment_evidence' => [
        'disk' => env('ERP_PAYMENT_EVIDENCE_DISK', 'local'),
        'directory' => 'payment-evidence',
        'max_kilobytes' => 8192,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
    ],

];
