// Pages inside pages. A title with slashes, such as "Projects/Alpha/Notes",
// puts the page under "Projects/Alpha", which is under "Projects". Above the
// title, the pages it is under lead up; under the page, the pages directly
// under it are listed (with a page that only has pages under it listed too,
// so every level can be reached). Journal days and notd's own pages are not
// counted.

import { html, render } from '../lib/html.js';
import { api } from '../lib/api.js';
import { cached } from '../lib/cache.js';
import { pageLink } from '../lib/route.js';
import { childrenOf } from '../lib/namespace.js';

const title = (ctx) => ctx.page?.title ?? ctx.route?.title ?? '';

/** Above the title of "A/B/C": links to A and A/B. */
export function parentsPanel() {
  return {
    id: 'parents', slot: 'page.header', order: 5,
    when: (ctx) => title(ctx).includes('/') && !title(ctx).toLowerCase().startsWith('notd/'),
    render(el, ctx) {
      const parts = title(ctx).split('/').slice(0, -1);
      const links = parts.map((p, i) => html`<a href=${pageLink(parts.slice(0, i + 1).join('/'))}>${p}</a>`);
      render(html`<nav class="parents" aria-label="Pages this page is under">${links.map((a, i) => html`${i ? ' / ' : ''}${a}`)} /</nav>`, el);
    },
  };
}

/** Under the page: the pages directly under it. */
export function childrenPanel() {
  return {
    id: 'children', slot: 'page.after', order: 5,
    when: (ctx) => !!title(ctx) && !ctx.route?.journal,
    async render(el, ctx) {
      const t = title(ctx);
      const pages = await cached('pages', () => api.pages()).catch(() => []);
      const list = childrenOf(t, pages.map((p) => p.title));
      if (!list.length) { render('', el); el.hidden = true; return; }
      el.hidden = false;
      render(html`<h2>${list.length === 1 ? '1 page' : `${list.length} pages`} under ${t.split('/').pop()}</h2>
        <ul class="child-pages">${list.map((c) => html`<li><a href=${pageLink(c.title)}>${c.title.slice(t.length + 1)}</a>${
          c.below ? html` <span class="hint">${c.below} under it</span>` : ''}</li>`)}</ul>`, el);
    },
  };
}
