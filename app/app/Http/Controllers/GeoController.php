<?php

namespace App\Http\Controllers;

use App\Services\ReverseGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side reverse-geocoding proxy for customer registration.
 *
 * Proxied (not called from the browser) so the provider, user agent and
 * timeout are under application control, and so an outage can only ever
 * degrade the ADDRESS SUGGESTION — never the captured coordinates.
 */
class GeoController extends Controller
{
    public function reverse(Request $request, ReverseGeocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            $geocoder->lookup((float) $validated['latitude'], (float) $validated['longitude']),
        );
    }
}
