// The mail plugin, browser side. An .eml file dropped onto notd, pasted, or
// picked with /file or "Attach a file" is added as an e-mail note on today's
// journal (instead of being stored as a file); in a block, a reference to the
// note goes where the file was put. "Add an e-mail" in the command palette
// does the same from the file picker.

export function activate(notd) {
  const add = async (file, { block } = {}) => {
    const form = new FormData();
    form.append('file', file);
    const r = await notd.api.post('mail.add', form);
    const where = r.added ? `Added the e-mail “${r.subject}” to ${r.page}` : `The e-mail “${r.subject}” is in notd already`;
    notd.notice(r.warnings.length ? `${where}; not stored: ${r.warnings.join('; ')}` : where, { error: r.warnings.length > 0 });
    return block ? `((${r.id}))` : '';
  };
  notd.files.register({ accept: (f) => /\.eml$/i.test(f.name) || f.type === 'message/rfc822', handle: add });
  notd.commands.register({
    id: 'add', title: 'Add an e-mail (.eml file)',
    run() {
      const input = Object.assign(document.createElement('input'), { type: 'file', multiple: true, accept: '.eml,message/rfc822' });
      input.addEventListener('change', async () => {
        for (const f of input.files) await add(f).catch((e) => notd.notice(`${f.name}: ${e.message}`, { error: true }));
      }, { once: true });
      input.click();
    },
  });
}
