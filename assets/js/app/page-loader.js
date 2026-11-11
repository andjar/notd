/**
 * Loads the page named in the URL into the outline.
 *
 * @module app/page-loader
 */

import * as outline from './outline.js';
import * as view from '../ui/note-elements.js';
import { page, setPage, pageProperty, config } from './page-state.js';
import { pagesAPI, notesAPI } from '../api_client.js';
import { decrypt, encrypt, escapeHtml } from '../utils.js';
import { renderContent } from '../ui/markdown.js';
import { askPagePassword, toast } from '../ui/dialogs.js';

const container = () => document.getElementById('notes-container');

function showMessage(text, action = null) {
    const c = container();
    if (!c) return;
    const p = document.createElement('p');
    p.className = 'page-message';
    p.textContent = text;
    c.replaceChildren(p);
    if (action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.textContent = action.label;
        button.addEventListener('click', action.run);
        c.appendChild(button);
    }
}

// -------------------------------------------------------------- encryption
//
// A locked page carries {encrypt::<verifier>}. The verifier lets the browser
// tell a wrong password from a right one without the server ever seeing
// either. Today it is a small encrypted token; pages locked by older versions
// carry a SHA-256 hash of the password instead, which still unlocks.

const TOKEN = 'notd';
const LEGACY_HASH = /^[0-9a-f]{64}$/;

export function makeVerifier(password) {
    return btoa(encrypt(password, TOKEN));
}

export function passwordMatches(password, verifier) {
    if (!password || !verifier) return false;
    try {
        if (LEGACY_HASH.test(verifier)) {
            return sjcl.codec.hex.fromBits(sjcl.hash.sha256.hash(password)) === verifier;
        }
        return decrypt(password, atob(verifier)) === TOKEN;
    } catch {
        return false;
    }
}

/** Asks for the password of a locked page until it is right. False if the user gives up. */
async function unlock() {
    const verifier = pageProperty('encrypt');
    if (!verifier) return true;
    for (;;) {
        const password = await askPagePassword();
        if (password === null) return false;
        if (passwordMatches(password, verifier)) {
            page.password = password;
            return true;
        }
        toast('Wrong password', { error: true });
    }
}

// ------------------------------------------------------------------ anchor

/** Scrolls to and highlights the note named in the URL (#note-<id>). */
export function showAnchoredNote() {
    const m = /^#note-([A-Za-z0-9_-]+)$/.exec(window.location.hash);
    if (!m || !outline.model.has(m[1])) return;
    const id = m[1];
    // Open what hides it.
    for (let n = outline.model.get(id); n && n.parent_note_id !== null; n = outline.model.get(n.parent_note_id)) {
        if (outline.model.get(n.parent_note_id)?.collapsed) outline.setCollapsed(n.parent_note_id, false);
    }
    const el = view.getNoteElement(id);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    el.classList.add('anchor-highlight');
    setTimeout(() => el.classList.remove('anchor-highlight'), 3000);
}

// -------------------------------------------------------------------- load

function showPageContent() {
    const el = document.getElementById('page-content');
    if (!el) return;
    // Properties are shown as pills in the header; the rest is free text.
    const text = page.content.replace(/\{[a-zA-Z0-9_.-]+:{2,}[^}\n]*\}/g, '').trim();
    el.innerHTML = text ? renderContent(text) : '';
    el.hidden = !text;
}

async function loadNotes() {
    const notes = await notesAPI.forPage(page.id);
    outline.loadPage(page.id, notes.map((n) => ({ ...n, content: outline.decodeContent(n.content) })));
    view.renderAll();
    if (outline.model.children(null).length === 0) {
        // A new page starts with an empty note to type into.
        outline.createAtEnd({ focus: window.location.hash ? null : 'start' });
    }
    showAnchoredNote();
}

/** Loads the page this document was opened for. */
export async function loadCurrentPage() {
    const name = config.pageName || new URLSearchParams(window.location.search).get('page') || '';
    try {
        await outline.settle(); // let edits queued on the previous page reach the server first
        setPage(await pagesAPI.byName(name));
        showPageContent();
        if (!(await unlock())) {
            showMessage('This page is locked.', { label: 'Unlock', run: () => window.location.reload() });
            return;
        }
        await loadNotes();
    } catch (error) {
        console.error('[page] could not load', name, error);
        showMessage(`This page could not be loaded: ${error.message}`, { label: 'Try again', run: () => window.location.reload() });
    }
}

window.addEventListener('hashchange', showAnchoredNote);
