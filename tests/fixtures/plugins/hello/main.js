import { greeting } from './lib/greeting.js';

export function activate(notd) {
  notd.panels.register({ id: 'greeting', slot: 'app.right', render(el) { el.innerHTML = `<p class="hello">${greeting(notd.plugin)}</p>`; } });
}
