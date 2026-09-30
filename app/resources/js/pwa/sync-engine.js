/**
 * Sync engine: drains the outbox to idempotent server endpoints.
 * Each operation keeps its client UUID as the idempotency key, so a retry
 * after a lost response can never create a duplicate on the server.
 */
import { outbox, STATE } from './outbox.js';
import { net } from '../net-store.js';

const ENDPOINTS = {
    ATTENDANCE: '/sync/attendance',
    ORDER_DRAFT: '/sync/order-drafts',
};

let syncing = false;

async function postOperation(op) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const response = await fetch(ENDPOINTS[op.type] ?? op.endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Idempotency-Key': op.uuid,
            Accept: 'application/json',
        },
        body: JSON.stringify(op.payload),
    });

    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, data };
}

/** Drain all pending operations. Safe to call repeatedly/concurrently. */
export async function syncNow() {
    if (syncing) return { processed: 0, failed: 0 };
    if (!navigator.onLine) return { processed: 0, failed: 0, offline: true };

    syncing = true;
    net.syncing();

    let processed = 0;
    let failed = 0;

    try {
        const pending = await outbox.pending();

        for (const op of pending) {
            if (op.state === STATE.SYNCING && op.retries >= 3) {
                continue; // another window is on it
            }

            await outbox.update(op.uuid, {
                state: STATE.SYNCING,
                retries: op.retries + 1,
            });

            const result = await postOperation(op);

            if (result.ok) {
                await outbox.update(op.uuid, {
                    state: STATE.SYNCED,
                    last_error: null,
                    server_ref: result.data?.attendance_id ?? null,
                });
                processed += 1;
            } else if (result.status === 403 || result.status === 422) {
                // Permanent rejection — keep for inspection, mark CONFLICT.
                await outbox.update(op.uuid, {
                    state: STATE.CONFLICT,
                    last_error: result.data?.error ?? result.data?.message ?? `Rejected (${result.status})`,
                });
                failed += 1;
            } else {
                // Transient (network/5xx) — back to PENDING for the next drain.
                await outbox.update(op.uuid, {
                    state: STATE.PENDING_SYNC,
                    last_error: result.data?.error ?? `HTTP ${result.status}`,
                });
                failed += 1;
            }
        }

        await outbox.pruneSynced();
    } finally {
        syncing = false;
        await net.refreshPending();
        net.doneSyncing(failed);
    }

    return { processed, failed };
}

/** Queue an attendance operation and try to sync immediately when online. */
export async function queueAttendance({ customerId, kind, gps, remarks, deviceTimestamp }) {
    const record = await outbox.enqueue('ATTENDANCE', {
        customer_id: customerId,
        remarks: [kind === 'CHECK_OUT' ? 'check-out' : null, remarks].filter(Boolean).join(' — ') || null,
        device_timestamp: deviceTimestamp,
        gps_latitude: gps?.latitude ?? null,
        gps_longitude: gps?.longitude ?? null,
        gps_accuracy: gps?.accuracy ?? null,
    });

    await net.refreshPending();

    if (navigator.onLine) {
        syncNow().catch(() => {});
    }

    return record;
}

/** Fetch current GPS position (best effort, no continuous tracking). */
export function getPosition() {
    return new Promise((resolve) => {
        if (!('geolocation' in navigator)) {
            resolve(null);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => resolve({
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
            }),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 },
        );
    });
}
