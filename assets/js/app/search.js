/**
 * Finding things: the search box in the sidebar, the page finder
 * (Ctrl+Space) and the note search (Shift+Space).
 *
 * @module app/search
 */

import { searchAPI } from '../api_client.js';
import { pageNames, matchNames } from './page-names.js';
import { debounce, escapeHtml, pageUrl, isDateName, looksEncrypted } from '../utils.js';

const $ = (id) => document.getElementById(id);

/** A search snippet as safe HTML: the server marks matches with <mark>. */
function snippetHtml(result) {
    const text = result.content_snippet ?? result.content ?? '';
    if (looksEncrypted(result.content)) return '<em>Encrypted note</em>';
    return escapeHtml(text).replace(/&lt;(\/?)mark&gt;/g, '<$1mark>');
}

/** Runs `search(query)` for the latest input only; older answers are dropped. */
function latestOnly(search, show) {
    let sequence = 0;
    return async (query) => {
        const mine = ++sequence;
        try {
            const results = query.trim() ? await search(query.trim()) : null;
            if (mine === sequence) show(results, null);
        } catch (error) {
            if (mine === sequence) show(null, error);
        }
    };
}

// ------------------------------------------------------------ sidebar search

function initSidebarSearch() {
    const input = $('global-search-input');
    const box = $('search-results');
    if (!input || !box) return;

    const show = (results, error) => {
        if (error) {
            box.innerHTML = `<div class="search-result-item">${escapeHtml(error.message)}</div>`;
        } else if (results === null) {
            box.innerHTML = '';
        } else if (!results.length) {
            box.innerHTML = '<div class="search-result-item">No results found</div>';
        } else {
            box.innerHTML = results.map((r) => `
                <div class="search-result-item">
                    <a href="${escapeHtml(pageUrl(r.page_name, r.note_id))}" class="search-result-link">
                        <div class="search-result-title">${escapeHtml(r.page_name)}</div>
                        <div class="search-result-snippet">${snippetHtml(r)}</div>
                    </a>
                </div>`).join('');
        }
        box.classList.toggle('has-results', box.innerHTML !== '');
    };
    const run = debounce(latestOnly(async (q) => (await searchAPI.notes(q, { perPage: 20 })).results || [], show), 250);

    input.addEventListener('input', () => run(input.value));
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            input.value = '';
            show(null, null);
            input.blur();
        } else if (e.key === 'Enter') {
            box.querySelector('a')?.click();
        }
    });
}

// ------------------------------------------------------------------ pickers
//
// A picker is a modal with a text field and a list of links: arrows move the
// selection, Enter follows the selected link.

function picker({ modal, input, list, cancel, results }) {
    if (!modal || !input || !list) return { open() {} };
    let selected = -1;

    const select = (index) => {
        const rows = list.children;
        if (!rows.length) {
            selected = -1;
            return;
        }
        selected = (index + rows.length) % rows.length;
        Array.from(rows).forEach((row, i) => row.classList.toggle('selected', i === selected));
        rows[selected].scrollIntoView({ block: 'nearest' });
    };
    const show = (rows, error) => {
        list.replaceChildren();
        if (error) rows = [{ text: error.message }];
        for (const row of rows || []) {
            const li = document.createElement('li');
            if (row.className) li.className = row.className;
            if (row.href) {
                const a = document.createElement('a');
                a.href = row.href;
                a.className = 'page-search-result-link';
                if (row.html) a.innerHTML = row.html; else a.textContent = row.text;
                li.appendChild(a);
            } else {
                li.className = 'no-results-item';
                li.textContent = row.text;
            }
            list.appendChild(li);
        }
        select(0);
    };
    const refresh = results.immediate
        ? latestOnly(results.find, show)
        : debounce(latestOnly(results.find, show), 250);
    const close = () => modal.classList.remove('active');
    const open = () => {
        input.value = '';
        show(null, null);
        modal.classList.add('active');
        input.focus();
        if (results.initial) results.initial().then((rows) => { if (!input.value) show(rows, null); });
    };

    input.addEventListener('input', () => refresh(input.value));
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            select(selected + (e.key === 'ArrowDown' ? 1 : -1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const link = list.children[selected]?.querySelector('a');
            if (link) window.location.href = link.href;
        } else if (e.key === 'Escape') {
            e.preventDefault();
            close();
        }
    });
    cancel?.addEventListener('click', close);
    modal.addEventListener('mousedown', (e) => { if (e.target === modal) close(); });
    return { open, close };
}

const pageRow = (name) => ({ text: name, href: pageUrl(name) });

async function findPages(query) {
    const names = await pageNames();
    // Journal pages are reached through the calendar; show them only when a date is typed.
    const pool = /^\d{4}-/.test(query) ? names : names.filter((n) => !isDateName(n));
    const rows = matchNames(pool, query, 10).map(pageRow);
    if (!names.some((n) => n.toLowerCase() === query.toLowerCase())) {
        rows.push({
            href: pageUrl(query),
            html: `Create page: <span>"${escapeHtml(query)}"</span>`,
            className: 'create-new-option',
        });
    }
    return rows;
}

async function findNotes(query) {
    const { results = [] } = await searchAPI.notes(query, { perPage: 30 });
    if (!results.length) return [{ text: 'No notes found' }];
    return results.map((r) => ({
        href: pageUrl(r.page_name, r.note_id),
        html: `<div class="search-result-title"><span class="search-result-page-name">${escapeHtml(r.page_name)}</span></div>`
            + `<div class="search-result-snippet">${snippetHtml(r)}</div>`,
        className: 'page-search-result-item',
    }));
}

const typingSomewhere = () => {
    const el = document.activeElement;
    return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
};

export function initSearch() {
    initSidebarSearch();

    const pages = picker({
        modal: $('page-search-modal'),
        input: $('page-search-modal-input'),
        list: $('page-search-modal-results'),
        cancel: $('page-search-modal-cancel'),
        results: {
            immediate: true,
            find: findPages,
            initial: async () => (await pageNames()).filter((n) => !isDateName(n)).slice(0, 10).map(pageRow),
        },
    });
    const notes = picker({
        modal: $('note-search-modal'),
        input: $('note-search-modal-input'),
        list: $('note-search-modal-results'),
        cancel: $('note-search-modal-cancel'),
        results: { find: findNotes },
    });

    $('open-page-search-modal-btn')?.addEventListener('click', pages.open);
    document.addEventListener('keydown', (e) => {
        if (e.code !== 'Space') return;
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey) {
            e.preventDefault();
            pages.open();
        } else if (e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey && !typingSomewhere()) {
            e.preventDefault();
            notes.open();
        }
    });
}
