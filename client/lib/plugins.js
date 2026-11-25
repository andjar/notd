// Loads the browser side of plugins. Each enabled plugin's module is imported
// and its activate(notd) is called with the plugin API (version 1). A plugin
// that fails to load or throws is left out and reported; the rest carry on.
// Safe mode (?safe=1 in the address) loads none.

import { api } from './api.js';

export const SAFE = new URLSearchParams(location.search).get('safe') === '1';

export const host = {
  list: [],         // from GET plugins
  failed: new Map(),// name -> message
  active: new Set(),
};

export async function loadPlugins(notd) {
  if (SAFE) return host;
  let data;
  try { data = await api.plugins(); } catch (e) { console.warn('plugins:', e.message); return host; }
  host.list = data.plugins;
  for (const p of data.plugins) {
    if (!p.enabled || p.problem) continue;
    for (const href of p.styles) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.dataset.plugin = p.name;
      document.head.append(link);
    }
    if (!p.browser) { host.active.add(p.name); continue; }
    try {
      const mod = await import(new URL(p.browser, document.baseURI).href);
      if (typeof mod.activate === 'function') await mod.activate(notd.forPlugin(p.name));
      host.active.add(p.name);
    } catch (e) {
      host.failed.set(p.name, e.message);
      console.error(`plugin ${p.name}:`, e);
    }
  }
  return host;
}
