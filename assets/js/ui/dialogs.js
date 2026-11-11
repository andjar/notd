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

function passwordField(id, label) {
    const wrap = document.createElement('label');
    wrap.className = 'dialog-field';
    wrap.textContent = label;
    const input = document.createElement('input');
    input.type = 'password';
    input.id = id;
    input.className = 'generic-modal-input-field';
    input.autocomplete = 'off';
    wrap.appendChild(input);
    return [wrap, input];
}

/** Asks for the password of a locked page. Resolves with it, or null if cancelled. */
export function askPagePassword() {
    return openModal((content, close) => {
        content.parentElement.id = 'password-modal';
        const h = document.createElement('h2');
        h.className = 'generic-modal-title';
        h.textContent = 'This page is locked';
        const [field, input] = passwordField('password-input', 'Password');
        input.setAttribute('autofocus', '');
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); close(input.value); }
        });
        const actions = document.createElement('div');
        actions.className = 'generic-modal-actions';
        const ok = button('Unlock', 'primary-button', () => close(input.value));
        ok.id = 'password-submit';
        actions.append(ok, button('Cancel', 'button-secondary', () => close(null)));
        content.append(h, field, actions);
        setTimeout(() => input.focus(), 0);
    });
}

/** Asks for a new password, twice. Resolves with it, or null if cancelled. */
export function askNewPassword() {
    return openModal((content, close) => {
        content.parentElement.id = 'encryption-password-modal';
        const h = document.createElement('h2');
        h.className = 'generic-modal-title';
        h.textContent = 'Lock this page';
        const note = document.createElement('p');
        note.textContent = 'The notes are encrypted in your browser. Nobody, including you, can read them without the password, and a forgotten password cannot be recovered.';
        const [f1, first] = passwordField('new-encryption-password', 'Password');
        const [f2, second] = passwordField('confirm-encryption-password', 'Repeat the password');
        const error = document.createElement('p');
        error.className = 'dialog-error';
        error.hidden = true;
        const submit = () => {
            const problem = !first.value.trim() ? 'The password cannot be empty.'
                : first.value !== second.value ? 'The passwords do not match.' : null;
            if (!problem) return close(first.value);
            error.textContent = problem;
            error.hidden = false;
        };
        for (const input of [first, second]) {
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        }
        const actions = document.createElement('div');
        actions.className = 'generic-modal-actions';
        const ok = button('Lock page', 'primary-button', submit);
        ok.id = 'confirm-encryption-btn';
        actions.append(ok, button('Cancel', 'button-secondary', () => close(null)));
        content.append(h, note, f1, f2, error, actions);
        setTimeout(() => first.focus(), 0);
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
