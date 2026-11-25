// Starts the browser app: checks that the server lets us in, shows the frame,
// and follows the address (#/page/<title>, or today's journal).

import { api } from './lib/api.js';
import { panels } from './lib/panels.js';
import { gate } from './ui/gate.js';

export const notd = { api, panels };
window.notd = notd;

const app = document.getElementById('app');

/** Today as YYYY-MM-DD in local time: the title of today's journal page. */
export function today(d = new Date()) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** The route in the address: { title } of the page to show. */
export function route() {
  const m = location.hash.match(/^#\/page\/(.+)$/);
  return { title: m ? decodeURIComponent(m[1]) : today() };
}

export function pageLink(title) {
  return '#/page/' + encodeURIComponent(title);
}

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
  const { title } = route();
  app.classList.remove('show-left', 'show-right');
  document.title = `${title} · notd`;
  panels.refresh({ ...panels.context, route: { title } });
  document.dispatchEvent(new CustomEvent('notd:route', { detail: { title } }));
}

async function start() {
  const status = await api.ping();
  if (!status.authenticated) await gate(status);
  setupFrame();
  app.hidden = false;
  panels.register({
    id: 'connection', slot: 'app.status', order: 100,
    render(el) { el.textContent = 'Connected'; el.className = 'status-item'; },
  });
  addEventListener('hashchange', show);
  await show();
}

start().catch((e) => {
  console.error(e);
  document.body.textContent = 'notd could not start: ' + e.message;
});
