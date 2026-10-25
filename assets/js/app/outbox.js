/**
 * Ordered, persisted queue of operations waiting to reach the server.
 *
 * - Ops are sent strictly in the order they were made, one request at a time.
 * - The queue lives in localStorage, so edits survive a reload, a crash or
 *   going offline, and are sent when the server is reachable again.
 * - Every op has an op_id; the server skips ids it has already applied, so
 *   resending after a lost response is safe.
 * - Consecutive edits to the same note are coalesced while unsent.
 * - Network failures and 5xx responses are retried with backoff; nothing is
 *   ever rolled back locally. A 4xx means the batch itself is invalid (a bug):
 *   it is dropped and `onRejected` lets the app resync from the server.
 *
 * @module app/outbox
 */

const DEFAULT_KEY = 'notd_outbox_v1';
const MAX_BATCH = 200;
const EDIT_DELAY_MS = 500;

export class Outbox {
    /**
     * @param {object} opts
     * @param {(ops: object[]) => Promise<Response>} opts.send  performs the HTTP request
     * @param {(data: object, ops: object[]) => void} [opts.onApplied]
     * @param {(error: Error, ops: object[]) => void} [opts.onRejected]
     * @param {(status: 'saved'|'pending'|'offline'|'error', info?: object) => void} [opts.onStatus]
     * @param {Storage|null} [opts.storage]
     * @param {string} [opts.storageKey]
     */
    constructor({ send, onApplied = () => {}, onRejected = () => {}, onStatus = () => {}, storage, storageKey = DEFAULT_KEY }) {
        this.send = send;
        this.onApplied = onApplied;
        this.onRejected = onRejected;
        this.onStatus = onStatus;
        this.storage = storage === undefined ? (typeof localStorage !== 'undefined' ? localStorage : null) : storage;
        this.storageKey = storageKey;
        this.queue = this._load();
        this.inFlight = null;     // ops currently being sent
        this.timer = null;
        this.retryDelay = 0;
        this.idleWaiters = [];
        this.status = null;
    }

    _load() {
        try {
            const raw = this.storage?.getItem(this.storageKey);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    }

    _persist() {
        try {
            const all = [...(this.inFlight || []), ...this.queue];
            if (all.length) this.storage?.setItem(this.storageKey, JSON.stringify(all));
            else this.storage?.removeItem(this.storageKey);
        } catch (e) {
            console.warn('[outbox] could not persist queue', e);
        }
    }

    /** Number of ops not yet confirmed by the server. */
    get size() {
        return this.queue.length + (this.inFlight ? this.inFlight.length : 0);
    }

    /** All unconfirmed ops (in flight first), oldest first. */
    pending() {
        return [...(this.inFlight || []), ...this.queue];
    }

    /** True when an unconfirmed op of one of `types` targets note `id`. */
    hasPending(id, types) {
        const sid = String(id);
        return this.pending().some(op => op.id === sid && (!types || types.includes(op.type)));
    }

    enqueue(op) {
        if (op.type === 'edit') {
            // Fold into an unsent create or edit of the same note: edits only
            // replace content, so the latest value is all that matters.
            for (let i = this.queue.length - 1; i >= 0; i--) {
                const q = this.queue[i];
                if (q.id !== op.id) continue;
                if (q.type === 'delete') break;
                if (q.type === 'create' || q.type === 'edit') {
                    this.queue[i] = { ...q, content: op.content };
                    this._persist();
                    this._schedule(EDIT_DELAY_MS);
                    return;
                }
            }
        }
        this.queue.push(op);
        this._persist();
        this._schedule(op.type === 'edit' ? EDIT_DELAY_MS : 0);
    }

    /** Sends everything now (used on blur, navigation, before uploads). */
    flushSoon() {
        this._schedule(0);
    }

    _schedule(delay) {
        this._setStatus(this.retryDelay ? this.status : 'pending');
        if (this.inFlight) return; // the running send loop picks new ops up
        if (this.retryDelay) return; // waiting for the backoff timer
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this._run(), delay);
    }

    async _run() {
        this.timer = null;
        if (this.inFlight) return;
        while (this.queue.length) {
            this.inFlight = this.queue.splice(0, MAX_BATCH);
            this._persist();
            let response;
            try {
                response = await this.send(this.inFlight);
            } catch (e) {
                this._requeueInFlight();
                this._retryLater('offline', e);
                return;
            }
            let body = null;
            try { body = await response.json(); } catch { /* non-JSON */ }

            if (response.ok && body && body.status === 'success') {
                const sent = this.inFlight;
                this.inFlight = null;
                this.retryDelay = 0;
                this._persist();
                try { this.onApplied(body.data, sent); } catch (e) { console.error('[outbox] onApplied failed', e); }
                continue;
            }
            if (response.status >= 400 && response.status < 500 && response.status !== 408 && response.status !== 429) {
                const sent = this.inFlight;
                this.inFlight = null;
                this._persist();
                const err = new Error(body?.message || `Server rejected changes (${response.status})`);
                try { this.onRejected(err, sent); } catch (e) { console.error('[outbox] onRejected failed', e); }
                continue;
            }
            this._requeueInFlight();
            this._retryLater('error', new Error(body?.message || `Server error ${response.status}`));
            return;
        }
        this._setStatus('saved');
        const waiters = this.idleWaiters;
        this.idleWaiters = [];
        waiters.forEach(w => w.resolve());
    }

    _requeueInFlight() {
        if (this.inFlight) this.queue.unshift(...this.inFlight);
        this.inFlight = null;
        this._persist();
    }

    _retryLater(status, error) {
        this.retryDelay = Math.min(this.retryDelay ? this.retryDelay * 2 : 1000, 30000);
        this._setStatus(status, { error, retryIn: this.retryDelay });
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.retryDelay = 0; this._run(); }, this.retryDelay);
        const waiters = this.idleWaiters;
        this.idleWaiters = [];
        waiters.forEach(w => w.reject(error));
    }

    /** Retry immediately (e.g. on the browser's `online` event). */
    retryNow() {
        if (!this.queue.length || this.inFlight) return;
        clearTimeout(this.timer);
        this.retryDelay = 0;
        this._run();
    }

    /**
     * Resolves once every queued op is confirmed; rejects if the server cannot
     * be reached (the ops stay queued and keep retrying).
     */
    flush() {
        if (!this.size) return Promise.resolve();
        const p = new Promise((resolve, reject) => this.idleWaiters.push({ resolve, reject }));
        if (!this.inFlight) {
            clearTimeout(this.timer);
            this.retryDelay = 0;
            this._run();
        }
        return p;
    }

    _setStatus(status, info) {
        if (status === this.status && !info) return;
        this.status = status;
        try { this.onStatus(status, info); } catch { /* ignore */ }
    }
}
