// The git plugin in the browser: a History section under each page, with every
// saved version, a look at any of them, and a way back to it.

import { html, render } from 'lit-html';

export function activate(notd) {
  notd.panels.register({
    id: 'history', slot: 'page.after', order: 50,
    when: (ctx) => !!ctx.page && !ctx.route?.journal,
    render: (el, ctx) => history(notd, el, ctx.page),
  });
  notd.commands.register({ id: 'commit', title: 'Save a version now (git)', run: () => notd.api.post('git.commit') });
}

function history(notd, el, page) {
  let versions = null;
  let shown = null;   // { commit, markdown }
  let error = '';
  let open = false;
  const draw = () => render(html`
    <details ?open=${open} @toggle=${(e) => { open = e.target.open; if (open && versions === null) load(); }}>
      <summary><h2>History</h2></summary>
      ${error ? html`<p class="git-error">${error}</p>` : ''}
      ${versions === null ? (open ? html`<p class="hint">Loading…</p>` : '') : versions.length === 0
        ? html`<p class="hint">No saved versions yet. Versions are saved a minute after you stop typing.</p>`
        : html`<ol class="git-versions">${versions.map((v) => html`<li>
            <button class=${shown?.commit === v.commit ? 'active' : ''} @click=${() => look(v.commit)}>
              ${new Date(v.time).toLocaleString()}</button></li>`)}</ol>`}
      ${shown ? html`<div class="git-version">
          <pre>${shown.markdown}</pre>
          <button class="git-restore" @click=${restore}>Restore this version</button>
        </div>` : ''}
    </details>`, el);
  async function load() {
    try { versions = await notd.api.get('git.history', { page: page.title }); } catch (e) { error = e.message; versions = []; }
    draw();
  }
  async function look(commit) {
    try { shown = { commit, ...(await notd.api.get('git.version', { page: page.title, commit })) }; } catch (e) { error = e.message; }
    draw();
  }
  async function restore() {
    if (!shown) return;
    try {
      await notd.api.post('git.restore', { page: page.title, commit: shown.commit });
      notd.go(page.title);
      location.reload();
    } catch (e) { error = e.message; draw(); }
  }
  draw();
}
