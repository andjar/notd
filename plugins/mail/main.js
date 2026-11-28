// The mail plugin, browser side. An e-mail file dropped onto notd, pasted, or
// picked with /file or "Attach a file" is added as an e-mail note on today's
// journal (instead of being stored as a file); in a block, a reference to the
// note goes where the file was put. That is an .eml (Gmail's "Download
// message", new Outlook, Thunderbird, Apple Mail) or an Outlook .msg (what
// Outlook for Windows gives when a message is dragged out of it). A raw message
// pasted into a block (Gmail's "Show original", then "Copy to clipboard") is
// added the same way. "Add an e-mail" in the command palette picks files.

import { looksLikeMail } from './lib/raw.js';

const MAIL_FILE = /\.(eml|msg)$/i;
const MAIL_TYPES = ['message/rfc822', 'application/vnd.ms-outlook'];

export function activate(notd) {
  const add = async (file, { block } = {}) => {
    const form = new FormData();
    form.append('file', file);
    const r = await notd.api.post('mail.add', form);
    const where = r.added ? `Added the e-mail “${r.subject}” to ${r.page}` : `The e-mail “${r.subject}” is in notd already`;
    notd.notice(r.warnings.length ? `${where}; not stored: ${r.warnings.join('; ')}` : where, { error: r.warnings.length > 0 });
    return block ? `((${r.id}))` : '';
  };
  notd.files.register({
    accept: (f) => MAIL_FILE.test(f.name) || MAIL_TYPES.includes(f.type),
    handle: add,
    acceptText: looksLikeMail,
    handleText: (text, ctx) => add(new File([text], 'pasted message.eml', { type: 'message/rfc822' }), ctx),
  });
  notd.commands.register({
    id: 'add', title: 'Add an e-mail (.eml or .msg file)',
    run() {
      const input = Object.assign(document.createElement('input'), { type: 'file', multiple: true, accept: `.eml,.msg,${MAIL_TYPES.join(',')}` });
      input.addEventListener('change', async () => {
        for (const f of input.files) await add(f).catch((e) => notd.notice(`${f.name}: ${e.message}`, { error: true }));
        window.notd?.feed?.poll();
      }, { once: true });
      input.click();
    },
  });
}
