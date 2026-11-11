/**
 * Things done to the page as a whole: its properties and text, favourite,
 * rename, delete, lock, print and export.
 *
 * @module app/page-actions
 */

import * as outline from './outline.js';
import { page, pageProperty } from './page-state.js';
import { makeVerifier } from './page-loader.js';
import { pagesAPI } from '../api_client.js';
import { pageUrl, renderIcons } from '../utils.js';
import { toast, confirmDialog, askNewPassword } from '../ui/dialogs.js';

const $ = (id) => document.getElementById(id);

const propertyRe = (name) => new RegExp(`\\s*\\{${name}:{2,}[^}\\n]*\\}`, 'g');

/** Page content with property `name` set to `value` (null removes it). */
function withProperty(content, name, value) {
    const rest = (content || '').replace(propertyRe(name), '').trimEnd();
    if (value === null) return rest;
    return (rest ? rest + '\n' : '') + `{${name}::${value}}`;
}

async function saveContent(content) {
    const updated = await pagesAPI.update(page.id, { content });
    page.content = updated.content || '';
    page.properties = updated.properties && !Array.isArray(updated.properties) ? updated.properties : {};
}

// ---------------------------------------------------------------- favourite

const isFavorite = () => String(pageProperty('favorite')).toLowerCase() === 'true';

function drawFavorite() {
    const star = $('favorite-toggle');
    if (!star) return;
    const on = isFavorite();
    star.textContent = on ? '★' : '☆';
    star.classList.toggle('is-favorite', on);
    star.setAttribute('aria-pressed', on ? 'true' : 'false');
    star.title = on ? 'Remove from favorites' : 'Add to favorites';
}

function drawFavoritesList() {
    const box = $('favorites-container');
    if (!box) return;
    let list = box.querySelector('ul');
    const mine = list && Array.from(list.querySelectorAll('a')).find((a) => a.dataset.pageName === page.name);
    if (isFavorite() && !mine) {
        if (!list) {
            list = document.createElement('ul');
            list.className = 'recent-pages-list';
            box.replaceChildren(list);
        }
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = pageUrl(page.name);
        a.className = 'recent-page-link active';
        a.dataset.pageName = page.name;
        const icon = document.createElement('i');
        icon.dataset.feather = 'star';
        icon.className = 'recent-page-icon';
        const label = document.createElement('span');
        label.className = 'recent-page-name';
        label.textContent = page.name;
        a.append(icon, label);
        li.appendChild(a);
        list.prepend(li);
        renderIcons(li);
    } else if (!isFavorite() && mine) {
        mine.closest('li').remove();
        if (!list.children.length) {
            const empty = document.createElement('div');
            empty.className = 'no-items-message';
            empty.textContent = 'No favorite pages yet.';
            box.replaceChildren(empty);
        }
    }
}

async function toggleFavorite() {
    if (page.id === null) return;
    try {
        await saveContent(withProperty(page.content, 'favorite', isFavorite() ? null : 'true'));
        drawFavorite();
        drawFavoritesList();
    } catch (error) {
        toast(`Could not update favorites: ${error.message}`, { error: true });
    }
}

// ------------------------------------------------------------- page dialog

function closeDialog() {
    $('page-properties-modal')?.classList.remove('active');
}

function openDialog() {
    if (page.id === null) return;
    $('page-name-input').value = page.name;
    $('page-content-input').value = page.content;
    const lock = $('page-encryption-button');
    const locked = !!pageProperty('encrypt');
    lock.textContent = locked ? 'Remove lock' : 'Lock with password';
    lock.disabled = locked && !page.password;
    $('page-export-link').href = pagesAPI.exportUrl(page.name);
    $('page-properties-modal').classList.add('active');
    $('page-content-input').focus();
}

async function save() {
    const name = $('page-name-input').value.trim();
    const content = $('page-content-input').value;
    if (!name) {
        toast('A page needs a name', { error: true });
        return;
    }
    const changes = {};
    if (name !== page.name) changes.name = name;
    if (content !== page.content) changes.content = content;
    if (!Object.keys(changes).length) return closeDialog();
    try {
        await outline.settle();
        const updated = await pagesAPI.update(page.id, changes);
        // The header and sidebars are drawn by the server: show the page afresh.
        window.location.href = pageUrl(updated.name);
    } catch (error) {
        toast(error.status === 409 ? `There already is a page named "${name}".` : `Could not save: ${error.message}`, { error: true });
    }
}

async function remove() {
    const count = outline.model.list().length;
    const ok = await confirmDialog(
        `Delete the page "${page.name}"${count ? ` and its ${count} note${count > 1 ? 's' : ''}` : ''}? This cannot be undone.`,
        { confirmLabel: 'Delete page', danger: true },
    );
    if (!ok) return;
    try {
        await outline.settle();
        await pagesAPI.remove(page.id);
        window.location.href = './';
    } catch (error) {
        toast(`Could not delete the page: ${error.message}`, { error: true });
    }
}

/**
 * Locks the page: from now on its notes are encrypted in the browser and the
 * server stores only ciphertext. Removing the lock stores them readable again.
 */
async function toggleLock() {
    const locked = !!pageProperty('encrypt');
    try {
        if (locked) {
            const ok = await confirmDialog('Remove the lock? The notes on this page will be stored unencrypted again.', { confirmLabel: 'Remove lock' });
            if (!ok) return;
            await outline.settle();
            await saveContent(withProperty(page.content, 'encrypt', null));
            page.password = null;
            await outline.reencryptAllNotes();
            toast('The lock was removed');
        } else {
            const password = await askNewPassword();
            if (!password) return;
            await outline.settle();
            await saveContent(withProperty(page.content, 'encrypt', makeVerifier(password)));
            page.password = password;
            await outline.reencryptAllNotes();
            toast('The page is locked. There is no way to recover a forgotten password.', { ms: 6000 });
        }
        closeDialog();
    } catch (error) {
        toast(`Could not change the lock: ${error.message}`, { error: true });
    }
}

export function initPageActions() {
    drawFavorite();
    $('favorite-toggle')?.addEventListener('click', toggleFavorite);
    $('page-properties-gear')?.addEventListener('click', openDialog);
    $('page-properties-modal-close')?.addEventListener('click', closeDialog);
    $('page-properties-modal')?.addEventListener('mousedown', (e) => {
        if (e.target === e.currentTarget) closeDialog();
    });
    $('page-properties-modal')?.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDialog();
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) save();
    });
    $('save-page-content-btn')?.addEventListener('click', save);
    $('page-delete-button')?.addEventListener('click', remove);
    $('page-encryption-button')?.addEventListener('click', toggleLock);
    $('page-print-button')?.addEventListener('click', () => {
        closeDialog();
        window.print();
    });
}

/** Call once the page has loaded (the star needs the page's properties). */
export function pageLoaded() {
    drawFavorite();
}
