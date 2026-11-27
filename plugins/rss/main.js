// The RSS plugin, browser side. Feeds are read on the server, every hour; these
// commands read them now and say what came in.

export function activate(notd) {
  const read = async (id) => {
    let r;
    try {
      r = await notd.api.post('rss.refresh', id ? { id } : {});
    } catch (e) {
      notd.notice(`Feeds: ${e.message}`, { error: true });
      return;
    }
    const feeds = r.feeds;
    if (!feeds.length) {
      notd.notice(id ? 'This block has no feed address.' : 'No feeds yet: write {feed:: https://…} in a block.', { error: true });
      return;
    }
    const added = feeds.reduce((n, f) => n + f.added, 0);
    const failed = feeds.filter((f) => f.error).map((f) => `${f.title || f.url}: ${f.error}`);
    const name = feeds.length === 1 ? (feeds[0].title || 'The feed') : `${feeds.length} feeds`;
    const what = `${name}: ${added === 1 ? '1 new item' : `${added || 'no'} new items`}`;
    notd.notice(failed.length ? (feeds.length === 1 ? failed[0] : `${what}; ${failed.join('; ')}`) : what, { error: failed.length > 0 });
    if (added) window.notd?.feed?.poll();
  };
  notd.commands.register({ id: 'refresh', title: 'Read all feeds now', run: () => read(null) });
  notd.commands.register({
    id: 'read', title: 'Read this feed now', menu: 'block',
    when: (ctx) => /\{feed::/i.test(ctx.outline?.model.get(ctx.id)?.text ?? ''),
    run: (ctx) => read(ctx.id),
  });
  notd.commands.register({ id: 'new', title: 'RSS feed', slash: true, slashOnly: true, run: (ctx) => ctx.insert('{feed:: }', 8) });
}
