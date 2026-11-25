// Live updates: asks the server's change feed what changed since last time,
// every 10 seconds and whenever the tab comes back into view, and hands each
// change to whoever listens. Changes this device made come back too; merging
// them changes nothing.

import { api } from './api.js';

export class Feed {
  constructor({ onChanges, interval = 10000 }) {
    this.onChanges = onChanges;
    this.interval = interval;
    this.since = null;
    this.timer = null;
    this.busy = false;
  }

  async start() {
    const first = await api.changes(Number.MAX_SAFE_INTEGER).catch(() => null);
    this.since = first?.next ?? 0;          // start from now: what is on screen is already current
    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
    addEventListener('focus', () => this.poll());
    this.#schedule();
  }

  #schedule() {
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.poll(), this.interval);
  }

  /** Fetches and delivers everything new now. */
  async poll() {
    if (this.busy || this.since === null) return;
    this.busy = true;
    try {
      let more = true;
      while (more) {
        const r = await api.changes(this.since);
        this.since = r.next;
        more = r.more;
        if (r.changes.length) this.onChanges(r.changes);
      }
    } catch (e) {
      if (e.code !== 'offline') console.warn('feed:', e.message);
    } finally {
      this.busy = false;
      this.#schedule();
    }
  }
}
