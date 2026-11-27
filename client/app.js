// Starts the browser app: checks that the server lets us in, shows the frame,
// and follows the address (#/page/<title>, or today's journal).

import { api } from './lib/api.js';
import { panels } from './lib/panels.js';
import { gate } from './ui/gate.js';
import { route, pageLink } from './lib/route.js';
import { searchPanel } from './panels/search.js';
import { PageScreen, liveScreens } from './ui/page.js';
import { Feed } from './lib/feed.js';
import { Toolbar } from './ui/toolbar.js';
import { loadPlugins } from './lib/plugins.js';
import { commands, installKeys } from './lib/commands.js';
import { Palette, BlockMenu } from './ui/menus.js';
import { registerCoreCommands } from './core-commands.js';
import { addSyntax } from './lib/markdown.js';
import { pluginsPanel, safeModePanel } from './panels/plugins.js';
import { JournalScreen } from './ui/journal.js';
import { backlinksPanel } from './panels/backlinks.js';
import { forgetPages } from './ui/autocomplete.js';
import { forgetCached } from './lib/cache.js';
import { remote } from './lib/remote.js';
import { notice } from './lib/notice.js';
import { addFileHandler } from './ui/attach.js';
import { favouritesPanel, recentPanel, calendarPanel, statusPanel, headerPanel } from './panels/sidebar.js';

export const notd = {
  api,
  panels,
  /** The plugin API (version 1) as one plugin sees it. */
  forPlugin(name) {
    return Object.freeze({
      version: 1,
      plugin: name,
      api,
      panels: { register: (p) => panels.register({ ...p, id: `${name}:${p.id}` }), refresh: (slot) => panels.refresh(panels.context, slot) },
      go: (title, block) => notd.go(title, block),
      syntax: { register: (spec) => addSyntax({ ...spec, name: `${name}_${spec.name}` }) },
      commands: {
        register: (c) => commands.register({ ...c, id: c.id.startsWith(name + '.') ? c.id : `${name}.${c.id}` }),
        run: (id, extra) => commands.run(id, extra),
      },
      on: (event, fn) => document.addEventListener('notd:' + event, (e) => fn(e.detail)),
      notice: (text, options) => notice(text, options),
      files: { register: (handler) => addFileHandler(handler) },
    });
  },
};
window.notd = notd;

const app = document.getElementById('app');

function setupFrame() {
  for (const button of app.querySelectorAll('[data-toggle]')) {
    button.addEventListener('click', () => {
      const side = button.dataset.toggle;
      if (matchMedia('(max-width: 899px)').matches) {
        app.classList.toggle('show-' + side);
        app.classList.remove('show-' + (side === 'left' ? 'right' : 'left'));
      } else {
        app.classList.toggle('no-' + side);
      }
    });
  }
  app.querySelector('.main').addEventListener('click', () => app.classList.remove('show-left', 'show-right'));
  try {
    const theme = localStorage.getItem('notd.theme');
    if (theme) document.documentElement.dataset.theme = theme;
  } catch { /* storage may be unavailable; the system theme applies */ }
}

async function show() {
  const r = route();
  app.classList.remove('show-left', 'show-right');
  document.title = `${r.title} · notd`;
  panels.refresh({ ...panels.context, route: r, page: null });
  document.dispatchEvent(new CustomEvent('notd:route', { detail: r }));
}

/** Opens a page (and a block on it). */
export function go(title, block = null) {
  location.hash = pageLink(title, block);
}
notd.go = go;

async function start() {
  const status = await api.ping();
  if (!status.authenticated) await gate(status);
  setupFrame();
  app.hidden = false;
  for (const p of [searchPanel({ go }), headerPanel(), backlinksPanel(), favouritesPanel(), recentPanel(), calendarPanel(), statusPanel(), pluginsPanel(), safeModePanel()]) panels.register(p);
  document.addEventListener('notd:route', (e) => {
    forgetPages();
    notd.screen?.dispose();
    const root = document.getElementById('page');
    root.replaceChildren();
    notd.screen = e.detail.journal ? new JournalScreen(root) : new PageScreen(root);
    notd.screen.show(e.detail.title, e.detail.block);
  });
  document.addEventListener('notd:page', (e) => panels.refresh({ ...panels.context, page: e.detail.page }));
  document.addEventListener('notd:renamed', (e) => { forgetPages(); forgetCached(); go(e.detail.to); });
  notd.feed = new Feed({ onChanges: (changes) => {
    forgetCached();                         // cached reads (favourites, calendar days) may be stale now
    document.dispatchEvent(new CustomEvent('notd:changes', { detail: { changes } }));   // queries on screen run again
    for (const screen of liveScreens()) {
      const event = screen.fromFeed(changes);
      if (event?.renamed && screen.announce) go(event.renamed);
      if (event?.deleted && screen.announce) screen.reload();
    }
    panels.refresh(panels.context, 'page.after');
    panels.refresh(panels.context, 'app.left');
  } });
  notd.feed.start();
  notd.toolbar = new Toolbar();
  notd.commands = commands;
  commands.setContext((id) => {
    const screens = liveScreens();
    const own = id ? screens.find((x) => x.outline.model.has(id)) : screens.find((x) => x.editor?.editing);
    if (id && !own && remote.model.has(id)) return { id, outline: remote, editor: null, page: null };   // in a query's results
    const s = own ?? screens.find((x) => x.announce) ?? screens[0];
    return { id: id ?? s?.editor?.editing ?? null, outline: s?.outline, editor: s?.editor, page: s?.page };
  });
  registerCoreCommands({ palette: new Palette(), go });
  new BlockMenu();
  installKeys();
  await loadPlugins(notd);
  panels.refresh(panels.context, 'app.left');
  addEventListener('hashchange', show);
  document.addEventListener('notd:favourites', () => panels.refresh(panels.context, 'app.left'));
  await show();
}

start().catch((e) => {
  console.error(e);
  // A 404 that is not notd's own (which has a code) means the web server never passed the request to notd.
  const hint = e.code === 'http_404' ? ' The web server must send every request that is not a file to index.php;'
    + ' in phpdesktop, set "404_handler": "/index.php" under "web_server" in settings.json.' : '';
  const message = /[.!?]$/.test(e.message) ? e.message : `${e.message}.`;
  document.body.textContent = `notd could not start: ${message}${hint}`;
});
