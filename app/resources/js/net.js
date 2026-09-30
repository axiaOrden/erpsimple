/**
 * Network/sync state store. States: ONLINE, OFFLINE, SYNCING, PENDING, SYNC_ERROR.
 * Phase 1 tracks connectivity + exposes hooks for the Phase 3+ sync engine.
 */
const STATE = {
    ONLINE: 'ONLINE',
    OFFLINE: 'OFFLINE',
    SYNCING: 'SYNCING',
    PENDING: 'PENDING',
    SYNC_ERROR: 'SYNC_ERROR',
};

function initialOnline() {
    return typeof navigator !== 'undefined' ? navigator.onLine : true;
}

export const netStore = {
    state: initialOnline() ? STATE.ONLINE : STATE.OFFLINE,
    pendingCount: 0,
    banner: false,
    bannerText: '',
    bannerClass: '',

    get isOnline() {
        return this.state !== STATE.OFFLINE;
    },

    label() {
        switch (this.state) {
            case STATE.OFFLINE: return 'OFFLINE';
            case STATE.SYNCING: return 'SYNCING…';
            case STATE.PENDING: return `${this.pendingCount} PENDING`;
            case STATE.SYNC_ERROR: return 'SYNC ERROR';
            default: return 'ONLINE';
        }
    },

    pillClass() {
        switch (this.state) {
            case STATE.OFFLINE: return 'm-chip-error';
            case STATE.PENDING: return 'bg-tertiary-container text-on-tertiary-container';
            case STATE.SYNC_ERROR: return 'm-chip-error';
            case STATE.SYNCING: return 'bg-tertiary-container text-on-tertiary-container';
            default: return 'm-chip-active';
        }
    },

    setState(next, { silent = false } = {}) {
        this.state = next;
        if (!silent) {
            this.showBanner(this.bannerTextFor(next), this.bannerClassFor(next));
        }
        this.persist();
    },

    bannerTextFor(state) {
        switch (state) {
            case STATE.OFFLINE:
                return 'You are offline. You can keep collecting data — it will sync when you reconnect.';
            case STATE.ONLINE:
                return 'Back online. Pending changes will sync automatically.';
            case STATE.SYNC_ERROR:
                return 'Some changes failed to sync. They are saved on this device and will retry.';
            default:
                return '';
        }
    },

    bannerClassFor(state) {
        switch (state) {
            case STATE.OFFLINE:
            case STATE.SYNC_ERROR:
                return 'bg-error-container text-on-error-container';
            default:
                return 'bg-tertiary-container text-on-tertiary-container';
        }
    },

    showBanner(text, cssClass) {
        if (!text) return;
        this.bannerText = text;
        this.bannerClass = cssClass;
        this.banner = true;
        setTimeout(() => { this.banner = false; }, 5000);
    },

    setPendingCount(count) {
        this.pendingCount = count;
        if (count > 0 && this.isOnline) {
            this.state = STATE.PENDING;
        }
        this.persist();
    },

    persist() {
        try {
            sessionStorage.setItem('net.state', this.state);
            sessionStorage.setItem('net.pending', String(this.pendingCount));
        } catch {
            // storage unavailable (private mode) — non-fatal
        }
    },

    init() {
        window.addEventListener('online', () => this.setState(STATE.ONLINE));
        window.addEventListener('offline', () => this.setState(STATE.OFFLINE));
        this.persist();
    },
};

export { STATE };
