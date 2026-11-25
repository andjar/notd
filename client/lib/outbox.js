// The outbox: operations waiting to reach the server, in order. Ported from notd 3.
//
// - Operations are sent in the order they were made, one request at a time.
// - The queue is kept in localStorage, so edits survive a reload, a crash or
//   being offline, and go out when the server can be reached again.
// - Every operation has an opId; the server skips ids it has seen, so sending
//   again after a lost response is safe.
// - Edits to the same block are folded together while they wait.
// - Network failures and 5xx answers are retried with a growing pause; nothing
//   is rolled back locally. A 4xx means the batch itself is wrong (a bug): it is
//   dropped and onRejected lets the app reload the page from the server.

const MAX_BATCH = 200;
const EDIT_DELAY_MS = 400;

export class Outbox {
  constructor({ send, onApplied = () => {}, onRejected = () => {}, onStatus = () => {}, storage, key = 'notd.outbox' }) {
    this.send = send;               // async (ops) => response data; throws { status } on failure
    this.onApplied = onApplied;
    this.onRejected = onRejected;
    this.onStatus = onStatus;       // ('saved' | 'pending' | 'offline' | 'error', size)
    this.storage = storage === undefined ? (typeof localStorage === 'undefined' ? null : localStorage) : storage;
    this.key = key;
    this.queue = this.#load();
    this.inFlight = null;
    this.timer = null;
    this.retryDelay = 0;
    this.waiters = [];
    this.status = null;
  }

  #load() {
    try {
      const v = JSON.parse(this.storage?.getItem(this.key) ?? '[]');
      return Array.isArray(v) ? v : [];
    } catch { return []; }
  }

  #persist() {
    try {
      const all = this.pending();
      if (all.length) this.storage?.setItem(this.key, JSON.stringify(all));
      else this.storage?.removeItem(this.key);
    } catch (e) { console.warn('outbox: cannot keep the queue', e); }
  }

  get size() { return this.queue.length + (this.inFlight?.length ?? 0); }
  pending() { return [...(this.inFlight ?? []), ...this.queue]; }

  /** Is an unsent operation of one of these kinds waiting for block id? */
  hasPending(id, kinds) {
    return this.pending().some((op) => op.id === id && (!kinds || kinds.includes(op.op)));
  }

  enqueue(op) {
    if (op.op === 'edit') {
      for (let i = this.queue.length - 1; i >= 0; i--) {
        const q = this.queue[i];
        if (q.id !== op.id) continue;
        if (q.op === 'delete') break;
        if (q.op === 'create' || q.op === 'edit') {
          this.queue[i] = { ...q, text: op.text };
          this.#persist();
          this.#schedule(EDIT_DELAY_MS);
          return;
        }
      }
    }
    this.queue.push(op);
    this.#persist();
    this.#schedule(op.op === 'edit' ? EDIT_DELAY_MS : 0);
  }

  #schedule(delay) {
    this.#setStatus(this.retryDelay ? this.status : 'pending');
    if (this.inFlight || this.retryDelay) return;
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.#run(), delay);
  }

  async #run() {
    this.timer = null;
    if (this.inFlight) return;
    while (this.queue.length) {
      this.inFlight = this.queue.splice(0, MAX_BATCH);
      this.#persist();
      let data;
      try {
        data = await this.send(this.inFlight);
      } catch (e) {
        const s = e?.status ?? 0;
        if (s >= 400 && s < 500 && s !== 408 && s !== 429 && s !== 401) {
          const sent = this.inFlight;
          this.inFlight = null;
          this.#persist();
          try { this.onRejected(e, sent); } catch (x) { console.error(x); }
          continue;
        }
        this.queue.unshift(...this.inFlight);
        this.inFlight = null;
        this.#persist();
        this.#retryLater(s === 0 ? 'offline' : 'error', e);
        return;
      }
      const sent = this.inFlight;
      this.inFlight = null;
      this.retryDelay = 0;
      this.#persist();
      try { this.onApplied(data, sent); } catch (x) { console.error(x); }
    }
    this.#setStatus('saved');
    const w = this.waiters;
    this.waiters = [];
    w.forEach((x) => x.resolve());
  }

  #retryLater(status, error) {
    this.retryDelay = Math.min(this.retryDelay ? this.retryDelay * 2 : 1000, 30000);
    this.#setStatus(status);
    clearTimeout(this.timer);
    this.timer = setTimeout(() => { this.retryDelay = 0; this.#run(); }, this.retryDelay);
    const w = this.waiters;
    this.waiters = [];
    w.forEach((x) => x.reject(error));
  }

  /** Sends waiting operations now instead of after the edit pause (a block was left). */
  flushSoon() {
    if (this.queue.length && !this.retryDelay) this.#schedule(0);
  }

  /** Try again now (the browser came back online). */
  retryNow() {
    if (!this.queue.length || this.inFlight) return;
    clearTimeout(this.timer);
    this.retryDelay = 0;
    this.#run();
  }

  /** Resolves once everything is confirmed; rejects if the server cannot be reached (the queue keeps trying). */
  flush() {
    if (!this.size) return Promise.resolve();
    const p = new Promise((resolve, reject) => this.waiters.push({ resolve, reject }));
    if (!this.inFlight) {
      clearTimeout(this.timer);
      this.retryDelay = 0;
      this.#run();
    }
    return p;
  }

  #setStatus(status) {
    if (status === this.status && status !== 'pending') return;
    this.status = status;
    try { this.onStatus(status, this.size); } catch { /* ignore */ }
  }
}
