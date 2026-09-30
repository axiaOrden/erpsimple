/**
 * Bridge between the Alpine net store and the sync engine. The Alpine store
 * instance is registered by app.js; this module offers a stable import for
 * non-Alpine modules (sync engine) to reflect queue state in the UI.
 */
let store = null;

export const net = {
    bind(alpineStore) {
        store = alpineStore;
    },

    get isOnline() {
        return navigator.onLine;
    },

    syncing() {
        store?.setState('SYNCING', { silent: true });
    },

    doneSyncing(failed = 0) {
        if (!navigator.onLine) {
            store?.setState('OFFLINE');

            return;
        }

        store?.setState(failed > 0 ? 'SYNC_ERROR' : 'ONLINE', { silent: failed === 0 });
    },

    async refreshPending() {
        const count = await outboxCount();

        store?.setPendingCount(count);
    },
};

async function outboxCount() {
    const { outbox } = await import('./pwa/outbox.js');

    return outbox.pendingCount();
}
