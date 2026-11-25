// The screen shown before the app: the login form, or why there is none.

import { html, render } from '../lib/html.js';
import { api, ApiError } from '../lib/api.js';

/** Shows the gate until the user is in; resolves when the app may start. */
export function gate(status) {
  const host = document.getElementById('gate');
  host.hidden = false;
  return new Promise((done) => {
    let error = '';
    const draw = () => render(status.passwordSet ? html`
      <form @submit=${submit} aria-label="Log in">
        <h1>notd</h1>
        <input type="password" name="password" autocomplete="current-password" placeholder="Password" aria-label="Password" required autofocus>
        <button type="submit">Log in</button>
        ${error ? html`<p class="error" role="alert">${error}</p>` : ''}
      </form>` : html`
      <form aria-label="No password">
        <h1>notd</h1>
        <p>This notd only answers the computer it runs on until a password is set. On that computer, run
          <code>php tools/password.php</code> in the notd folder, then reload this page.</p>
      </form>`, host);
    async function submit(e) {
      e.preventDefault();
      try {
        await api.login(new FormData(e.target).get('password'));
        host.hidden = true;
        render('', host);
        done();
      } catch (err) {
        error = err instanceof ApiError && err.code === 'wrong_password' ? 'Wrong password.' : err.message;
        draw();
      }
    }
    draw();
  });
}
