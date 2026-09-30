<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent ingestion for offline-sync capable operations (rule 24).
 *
 * The client generates a UUID per logical operation. If the HTTP response is
 * lost and the operation is retried, the stored response is replayed instead
 * of executing the operation twice. Keys are scoped per endpoint.
 */
class SyncService
{
    public function __construct(
        private readonly Factory $cache,
    ) {}

    /**
     * Process an idempotent operation.
     *
     * @param  string  $endpoint  logical endpoint name (scopes the key)
     * @param  Request  $request  incoming request (key from header or payload)
     * @param  callable(): mixed  $handler  executes the operation, returns the response payload
     * @return array{payload: mixed, replayed: bool, idempotency_key: ?string}
     *
     * @throws ValidationException when no key is present
     */
    public function process(string $endpoint, Request $request, callable $handler): array
    {
        $key = (string) ($request->header('X-Idempotency-Key')
            ?? $request->input('idempotency_key')
            ?? '');

        if ($key === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A client-generated idempotency key is required for synchronization.',
            ]);
        }

        $userId = $request->user()?->getAuthIdentifier();

        // Keys are scoped per endpoint AND per user: the same client UUID from
        // a different account is a different logical operation.
        // The composite must ALWAYS fit the 64-char idempotency_keys column,
        // so the endpoint|user scope prefix is preserved and only the client
        // portion is truncated to the remaining space — otherwise storage
        // overflows with a fatal 500 before the operation can validate.
        $clientMax = 64 - strlen($endpoint) - strlen((string) $userId) - 2;

        $key = $endpoint.'|'.$userId.'|'.substr($key, 0, max(0, $clientMax));

        // Fast path: already processed — replay the stored response.
        $existing = DB::table('idempotency_keys')
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            return [
                'payload' => json_decode($existing->response_body, true),
                'replayed' => true,
                'idempotency_key' => $key,
            ];
        }

        // Reserve the key atomically; the loser of the race is a concurrent
        // retry and must wait for the winner's stored response.
        $inserted = DB::table('idempotency_keys')->insert([
            'idempotency_key' => $key,
            'endpoint' => $endpoint,
            'user_id' => $userId,
            'response_type' => 'json',
            'response_status' => 200,
            'response_body' => json_encode(['_processing' => true]),
            'created_at' => now(),
        ]);

        if (! $inserted) {
            // Another request holds this key: return the current state.
            $existing = DB::table('idempotency_keys')->where('idempotency_key', $key)->first();

            return [
                'payload' => $existing !== null ? json_decode($existing->response_body, true) : null,
                'replayed' => true,
                'idempotency_key' => $key,
            ];
        }

        try {
            $payload = $handler();

            DB::table('idempotency_keys')
                ->where('idempotency_key', $key)
                ->update([
                    'response_body' => json_encode($payload),
                    'response_status' => 200,
                ]);

            return [
                'payload' => $payload,
                'replayed' => false,
                'idempotency_key' => $key,
            ];
        } catch (\Throwable $e) {
            // Validation/business failures release the key so a corrected
            // retry (or the client surfacing the error) can try again.
            DB::table('idempotency_keys')->where('idempotency_key', $key)->delete();

            throw $e;
        }
    }
}
