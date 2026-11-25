// Linked references under the page: every block elsewhere that links to this
// page or tags it (also through its aliases), grouped by the page it is on.

import { api } from '../lib/api.js';
import { pageLink } from '../lib/route.js';
import { renderText } from '../ui/text.js';

export function backlinksPanel() {
  let asked = null;
  return {
    id: 'backlinks', slot: 'page.after', order: 10,
    when: (ctx) => !!ctx.page && !ctx.route?.journal,
    async render(el, ctx) {
      const page = ctx.page;
      asked = page.id;
      let groups;
      try { groups = await api.backlinks(page.id); } catch { groups = []; }
      if (asked !== page.id) return;
      const count = groups.reduce((n, g) => n + g.blocks.length, 0);
      el.replaceChildren();
      const h = document.createElement('h2');
      h.textContent = count ? `${count} linked reference${count === 1 ? '' : 's'}` : 'No linked references';
      el.append(h);
      for (const g of groups) {
        const section = document.createElement('div');
        section.className = 'ref-group';
        const a = document.createElement('a');
        a.className = 'ref-page';
        a.href = pageLink(g.page.title);
        a.textContent = g.page.title;
        section.append(a);
        for (const b of g.blocks) {
          const item = document.createElement('div');
          item.className = 'ref-block';
          const open = document.createElement('a');
          open.className = 'bullet';
          open.href = pageLink(g.page.title, b.id);
          open.setAttribute('aria-label', 'Open this block');
          const text = document.createElement('div');
          text.className = 'content';
          renderText(text, b.text, b);
          item.append(open, text);
          section.append(item);
        }
        el.append(section);
      }
    },
  };
}
