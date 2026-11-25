// The plugins panel: what is installed, switched on or off, and what failed.

import { html, render } from '../lib/html.js';
import { api } from '../lib/api.js';
import { host, SAFE } from '../lib/plugins.js';

export function pluginsPanel() {
  let changed = false;
  let open = false;
  let el = null;
  const draw = () => render(html`
    <details ?open=${open} @toggle=${(e) => { open = e.target.open; }}>
      <summary><h2>Plugins</h2></summary>
      ${SAFE ? html`<p class="hint">Safe mode: no plugins are running. <a href="./">Leave safe mode</a></p>` : ''}
      ${host.list.length ? html`<ul class="plugins">${host.list.map((p) => html`<li>
          <label title=${p.description}><input type="checkbox" .checked=${p.enabled} ?disabled=${SAFE}
            @change=${(e) => toggle(p, e.target.checked)}> ${p.name}</label>
          ${p.problem || host.failed.get(p.name) ? html`<span class="plugin-problem">${p.problem || host.failed.get(p.name)}</span>` : ''}
        </li>`)}</ul>` : html`<p class="hint">No plugins installed.</p>`}
      ${changed ? html`<p class="hint">Reload the page to apply. <button @click=${() => location.reload()}>Reload</button></p>` : ''}
    </details>`, el);
  async function toggle(p, enabled) {
    await api.setPlugin(p.name, enabled);
    p.enabled = enabled;
    changed = true;
    draw();
  }
  return { id: 'plugins', slot: 'app.left', order: 90, render(target) { el = target; draw(); } };
}

/** "Safe mode" in the status line, so it is never forgotten. */
export function safeModePanel() {
  return {
    id: 'safe-mode', slot: 'app.status', order: 0, when: () => SAFE,
    render(el) { render(html`<a class="warn" href="./" title="Leave safe mode">Safe mode: plugins off</a>`, el); },
  };
}
