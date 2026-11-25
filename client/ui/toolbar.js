// The editing toolbar for phones and tablets, where there is no Tab key: it
// sits just above the on-screen keyboard while a block is being edited.
// Each button sends the editor the same key a keyboard would.

const BUTTONS = [
  ['outdent', '⇤', 'Outdent', { key: 'Tab', shiftKey: true }],
  ['indent', '⇥', 'Indent', { key: 'Tab' }],
  ['up', '↑', 'Move up', { key: 'ArrowUp', altKey: true, shiftKey: true }],
  ['down', '↓', 'Move down', { key: 'ArrowDown', altKey: true, shiftKey: true }],
  ['link', '[[', 'Link to a page', null],
  ['undo', '↶', 'Undo', { key: 'z', ctrlKey: true }],
  ['redo', '↷', 'Redo', { key: 'z', ctrlKey: true, shiftKey: true }],
  ['done', '✓', 'Done', { key: 'Escape' }],
];

const SMALL = '(max-width: 899px), (pointer: coarse)';

export class Toolbar {
  constructor() {
    this.el = document.createElement('div');
    this.el.className = 'toolbar';
    this.el.setAttribute('role', 'toolbar');
    this.el.setAttribute('aria-label', 'Editing');
    this.el.hidden = true;
    for (const [name, label, title, key] of BUTTONS) {
      const b = document.createElement('button');
      b.dataset.action = name;
      b.textContent = label;
      b.title = title;
      b.setAttribute('aria-label', title);
      // pointerdown, and no default, so the block keeps focus (and the keyboard stays up)
      b.addEventListener('pointerdown', (e) => { e.preventDefault(); this.#press(key); });
      b.addEventListener('click', (e) => { if (e.detail === 0) this.#press(key); }); // keyboard activation
      this.el.append(b);
    }
    document.body.append(this.el);
    document.addEventListener('focusin', () => this.#update());
    document.addEventListener('focusout', () => setTimeout(() => this.#update(), 0));
    const vv = window.visualViewport;
    vv?.addEventListener('resize', () => this.#place());
    vv?.addEventListener('scroll', () => this.#place());
  }

  #editing() {
    const a = document.activeElement;
    return a?.matches?.('.content[contenteditable]') ? a : null;
  }

  #update() {
    this.el.hidden = !(this.#editing() && matchMedia(SMALL).matches);
    document.body.classList.toggle('has-toolbar', !this.el.hidden);
    this.#place();
  }

  /** Just above the on-screen keyboard, which shrinks the visual viewport. */
  #place() {
    const vv = window.visualViewport;
    const below = vv ? Math.max(0, innerHeight - (vv.offsetTop + vv.height)) : 0;
    this.el.style.bottom = `${below}px`;
  }

  #press(key) {
    const content = this.#editing();
    if (!content) return;
    if (key) {
      content.dispatchEvent(new KeyboardEvent('keydown', { ...key, bubbles: true, cancelable: true }));
    } else {
      document.execCommand('insertText', false, '[[');   // the link suggestions take it from here
    }
    this.#update();
  }
}
