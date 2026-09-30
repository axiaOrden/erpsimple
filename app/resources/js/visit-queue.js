/**
 * Alpine component powering the visit screens: captures GPS, queues
 * attendance operations in the outbox and shows local vs synced state.
 */
import { queueAttendance, getPosition } from './pwa/sync-engine.js';
import { outbox, STATE } from './pwa/outbox.js';
import { net } from './net-store.js';

export function visitQueue() {
    return {
        gpsNote: '',
        lastResult: '',
        pendingOps: [],
        pendingCount: 0,

        async init() {
            await this.refreshPending();

            window.addEventListener('online', () => this.refreshPending());
            document.addEventListener('outbox:changed', () => this.refreshPending());
        },

        async refreshPending() {
            const pending = await outbox.pending();

            this.pendingOps = pending.map((op) => ({
                uuid: op.uuid,
                type: op.type,
                customerId: op.payload?.customer_id ?? '?',
                state: op.state,
                error: op.last_error,
            }));
            this.pendingCount = pending.length;
        },

        async capture(kind, customerId) {
            if (!customerId) {
                this.gpsNote = 'Choose a customer first.';
                return;
            }

            this.gpsNote = 'Capturing location…';

            const gps = await getPosition();

            if (gps === null) {
                this.gpsNote = 'Location unavailable — the visit is still recorded without GPS.';
            } else {
                this.gpsNote = `Location captured (±${Math.round(gps.accuracy)}m).`;
            }

            const record = await queueAttendance({
                customerId,
                kind,
                gps,
                remarks: this.$refs?.remarks?.value ?? null,
                deviceTimestamp: new Date().toISOString(),
            });

            this.lastResult = navigator.onLine
                ? 'Visit recorded.'
                : 'Saved on this device — will sync when back online.';

            document.dispatchEvent(new Event('outbox:changed'));

            return record;
        },

        async checkIn(customerId) {
            await this.capture('CHECK_IN', customerId);
        },

        async checkOut(customerId) {
            await this.capture('CHECK_OUT', customerId);
        },

        async quickCheckIn(customerId) {
            await this.checkIn(customerId);
        },

        async syncNow() {
            const { syncNow } = await import('./pwa/sync-engine.js');
            await syncNow();
            await this.refreshPending();
        },
    };
}
