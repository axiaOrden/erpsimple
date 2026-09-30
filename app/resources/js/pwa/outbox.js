/**
 * IndexedDB outbox for offline-capable operations (rule 22).
 *
 * Every queued operation: { uuid, type, payload, local_created_at, state,
 * retries, last_error, server_ref }. States: LOCAL_ONLY, PENDING_SYNC,
 * SYNCING, SYNCED, CONFLICT, FAILED.
 */
const DB_NAME = 'erpsimple-outbox';
const DB_VERSION = 1;
const STORE = 'operations';

const STATE = {
    LOCAL_ONLY: 'LOCAL_ONLY',
    PENDING_SYNC: 'PENDING_SYNC',
    SYNCING: 'SYNCING',
    SYNCED: 'SYNCED',
    CONFLICT: 'CONFLICT',
    FAILED: 'FAILED',
};

function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'uuid' });
                store.createIndex('state', 'state');
                store.createIndex('type', 'type');
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function withStore(mode, fn) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const store = tx.objectStore(STORE);
        const result = fn(store);

        tx.oncomplete = () => resolve(result?.result !== undefined ? result.result : result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();

    return 'op-'+Date.now()+'-'+Math.random().toString(16).slice(2);
}

export const outbox = {
    /** Queue a new operation locally. Returns the queued record. */
    async enqueue(type, payload, { endpoint = null } = {}) {
        const record = {
            uuid: uuid(),
            type,
            endpoint,
            payload,
            local_created_at: new Date().toISOString(),
            state: STATE.PENDING_SYNC,
            retries: 0,
            last_error: null,
            server_ref: null,
        };

        await withStore('readwrite', (store) => store.add(record));

        return record;
    },

    /** All operations in queue order (oldest first). */
    async all() {
        return withStore('readonly', (store) => store.getAll());
    },

    async pending() {
        const all = await this.all();

        return all.filter((op) => [STATE.PENDING_SYNC, STATE.SYNCING, STATE.FAILED].includes(op.state));
    },

    async pendingCount() {
        return (await this.pending()).length;
    },

    async update(uuid, changes) {
        const all = await withStore('readonly', (store) => store.getAll());
        const record = all.find((op) => op.uuid === uuid);

        if (!record) return null;

        const updated = { ...record, ...changes };
        await withStore('readwrite', (store) => store.put(updated));

        return updated;
    },

    async remove(uuid) {
        await withStore('readwrite', (store) => store.delete(uuid));
    },

    /** Clear synced operations (housekeeping). */
    async pruneSynced() {
        const all = await this.all();

        for (const op of all.filter((o) => o.state === STATE.SYNCED)) {
            await this.remove(op.uuid);
        }
    },

    STATE,
};

export { STATE };
