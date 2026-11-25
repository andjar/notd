import { greeting } from './lib/greeting.js';

export function activate(notd) {
  notd.commands.register({
    id: 'greet', title: 'Say hello', key: 'Alt+Shift+H', slash: true,
    run(ctx) { document.body.dataset.greeted = ctx.id ? 'block' : 'yes'; if (ctx.insert) ctx.insert('hello!'); },
  });
  notd.syntax.register({ name: 'flag', at: 'start', pattern: /^!! /, render: () => '<button type="button" class="flag" data-command="hello.greet">!</button>' });
  notd.syntax.register({ name: 'cite', pattern: /\{\{@([\w-]+)\}\}/, render: (m) => `<cite class="cite">${m[1]}</cite>` });
  notd.panels.register({
    id: 'greeting', slot: 'app.right',
    render(el) { el.innerHTML = `<p class="hello">${greeting(notd.plugin)}</p><button data-command="hello.greet" class="greet">Greet</button>`; },
  });
}
