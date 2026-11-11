/**
 * Small dialogs: a toast, confirm, prompt, the password dialogs and the image
 * viewer. All return promises; none blocks the page the way alert() does.
 *
 * @module ui/dialogs
 */

import { renderIcons } from '../utils.js';

/** Shows a short message at the bottom of the screen. */
export function toast(message, { error = false, ms = 2500 } = {}) {
    const el = document.createElement('div');
    el.className = 'copy-feedback' + (error ? ' copy-feedback-error' : '');
    el.setAttribute('role', error ? 'alert' : 'status');
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), error ? Math.max(ms, 5000) : ms);
}

/** Opens a modal built from `build(content, close)`. Resolves with what `close` is called with (null on Escape or backdrop). */
function openModal(build, { className = '' } = {}) {
    return new Promise((resolve) => {
        const modal = document.createElement('div');
        modal.className = `generic-modal active notd-dialog ${className}`;
        const content = document.createElement('div');
        content.className = 'generic-modal-content';
        content.setAttribute('role', 'dialog');
        content.setAttribute('aria-modal', 'true');
        modal.appendChild(content);
        const previousFocus = document.activeElement;

        const close = (value) => {
            document.removeEventListener('keydown', onKey, true);
            modal.remove();
            if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus({ preventScroll: true });
            resolve(value);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                close(null);
            }
        };
        document.addEventListener('keydown', onKey, true);
        modal.addEventListener('mousedown', (e) => { if (e.target === modal) close(null); });

        build(content, close);
        document.body.appendChild(modal);
        renderIcons(modal);
        (content.querySelector('[autofocus]') || content.querySelector('button'))?.focus();
    });
}

function button(label, className, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `button ${className}`;
    b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
}

/** Asks a yes/no question. Resolves true only if the user confirms. */
export function confirmDialog(message, { confirmLabel = 'OK', cancelLabel = 'Cancel', danger = false } = {}) {
    return openModal((content, close) => {
        const p = document.createElement('p');
        p.textContent = message;
        const actions = document.createElement('div');
        actions.className = 'generic-modal-actions';
        const ok = button(confirmLabel, danger ? 'button-danger' : 'primary-button', () => close(true));
        ok.setAttribute('autofocus', '');
        actions.append(ok, button(cancelLabel, 'button-secondary', () => close(false)));
        content.append(p, actions);
    }).then(v => v === true);
}

/** Asks for one line of text. Resolves with the text, or null if cancelled. */
export function promptDialog(title, { value = '', confirmLabel = 'OK', placeholder = '' } = {}) {
    return openModal((content, close) => {
        const h = document.createElement('h2');
        h.className = 'generic-modal-title';
        h.textContent = title;
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'generic-modal-input-field';
        input.value = value;
        input.placeholder = placeholder;
        input.setAttribute('autofocus', '');
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                close(input.value);
            }
        });
        const actions = document.createElement('div');
        actions.className = 'generic-modal-actions';
        actions.append(button(confirmLabel, 'primary-button', () => close(input.value)), button('Cancel', 'button-secondary', () => close(null)));
        content.append(h, input, actions);
        setTimeout(() => input.select(), 0);
    });
}

/** Wires a modal that is part of page.php. Resolves with `read()` on submit, null on cancel. */
function useStaticModal(modal, { submit, cancel, inputs, read, validate }) {
    return new Promise((resolve) => {
        const done = (value) => {
            modal.classList.remove('active');
            controller.abort();
            resolve(value);
        };
        const controller = new AbortController();
        const on = (el, type, fn) => el?.addEventListener(type, fn, { signal: controller.signal });
        const trySubmit = () => {
            const problem = validate ? validate() : null;
            if (!problem) done(read());
        };
        on(submit, 'click', trySubmit);
        for (const el of cancel) on(el, 'click', () => done(null));
        for (const input of inputs) {
            input.value = '';
            on(input, 'keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); trySubmit(); }
                else if (e.key === 'Escape') { e.preventDefault(); done(null); }
            });
        }
        on(modal, 'mousedown', (e) => { if (e.target === modal) done(null); });
        modal.classList.add('active');
        inputs[0]?.focus();
    });
}

/** Asks for the password of an encrypted page. Resolves with it, or null if cancelled. */
export function askPagePassword() {
    const $ = (id) => document.getElementById(id);
    const input = $('password-input');
    return useStaticModal($('password-modal'), {
        submit: $('password-submit'),
        cancel: [$('password-cancel'), $('password-modal-close')],
        inputs: [input],
        read: () => input.value,
    });
}

/** Asks for a new password, twice. Resolves with it, or null if cancelled. */
export function askNewPassword() {
    const $ = (id) => document.getElementById(id);
    const first = $('new-encryption-password');
    const second = $('confirm-encryption-password');
    const error = $('encryption-password-error');
    error.style.display = 'none';
    return useStaticModal($('encryption-password-modal'), {
        submit: $('confirm-encryption-btn'),
        cancel: [$('cancel-encryption-btn'), $('encryption-modal-close')],
        inputs: [first, second],
        read: () => first.value,
        validate: () => {
            const problem = !first.value.trim() ? 'Password cannot be empty.'
                : first.value !== second.value ? 'Passwords do not match. Please try again.' : null;
            error.textContent = problem || '';
            error.style.display = problem ? 'block' : 'none';
            return problem;
        },
    });
}

/** Shows an image at full size; a click or Escape closes it. */
export function showImage(url) {
    if (!url) return Promise.resolve();
    return openModal((content, close) => {
        const img = document.createElement('img');
        img.src = url;
        img.alt = '';
        content.appendChild(img);
        content.parentElement.addEventListener('click', () => close(null));
    }, { className: 'image-viewer' });
}
