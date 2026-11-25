// The journal: today's page first, then earlier days that have something
// written, loaded a few at a time as you scroll down. Every day is an ordinary
// page, edited in place.

import { api } from '../lib/api.js';
import { today, pageLink } from '../lib/route.js';
import { PageScreen } from './page.js';
import { forgetNodes } from './text.js';

const BATCH = 5;

export class JournalScreen {
  constructor(root) {
    this.root = root;
    this.days = [];
    this.oldest = null;
    this.done = false;
    this.loading = false;
  }

  async show() {
    forgetNodes();
    this.root.replaceChildren();
    const first = today();
    this.oldest = first;
    await this.#day(first, true);
    this.sentinel = document.createElement('div');
    this.sentinel.className = 'journal-more';
    this.root.append(this.sentinel);
    this.observer = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) this.more(); }, { rootMargin: '600px' });
    this.observer.observe(this.sentinel);
  }

  /** Loads the next few earlier days. */
  async more() {
    if (this.loading || this.done) return;
    this.loading = true;
    try {
      const days = await api.journalDays({ before: this.oldest, limit: BATCH });
      for (const d of days) await this.#day(d, false);
      if (days.length) this.oldest = days.at(-1);
      if (days.length < BATCH) {
        this.done = true;
        this.observer?.disconnect();
        this.sentinel.textContent = this.days.length > 1 ? '' : 'Earlier days with notes will appear here.';
      }
    } finally {
      this.loading = false;
    }
  }

  async #day(title, isToday) {
    const section = document.createElement('section');
    section.className = 'journal-day' + (isToday ? ' today' : '');
    section.dataset.day = title;
    if (!isToday) {
      const h = document.createElement('h2');
      const a = document.createElement('a');
      a.href = pageLink(title);
      a.textContent = new Date(title + 'T00:00').toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
      h.append(a);
      section.append(h);
    }
    const body = document.createElement('div');
    section.append(body);
    if (this.sentinel) this.root.insertBefore(section, this.sentinel); else this.root.append(section);
    const screen = new PageScreen(body, { announce: isToday });
    this.days.push(screen);
    await screen.show(title);
  }

  dispose() {
    this.observer?.disconnect();
    for (const d of this.days) d.dispose();
    this.days = [];
  }
}
