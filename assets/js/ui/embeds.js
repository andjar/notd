/**
 * Embedded content inside notes: transclusions (!{{note-id}}) and query
 * results (SQL{...}). markdown.js leaves a placeholder for each; hydrate()
 * replaces placeholders with the real content.
 *
 * Embedded notes are shown read-only, each with a link to where it lives.
 *
 * @module ui/embeds
 */

import { notesAPI, queryAPI } from '../api_client.js';
import { escapeHtml, pageUrl, decrypt, looksEncrypted } from '../utils.js';
import { page } from '../app/page-state.js';
import { renderContent } from './markdown.js';

const MAX_DEPTH = 3;

function displayText(content) {
    if (!looksEncrypted(content)) return content || '';
    if (page.password) {
        try { return decrypt(page.password, content) ?? '[encrypted]'; } catch { /* another page's password */ }
    }
    return '[encrypted]';
}

/** A read-only tree of a note and its children. */
function noteTree(note) {
    const li = document.createElement('li');
    li.className = 'embedded-note';
    const body = document.createElement('div');
    body.className = 'embedded-note-content';
    body.innerHTML = renderContent(displayText(note.content));
    li.appendChild(body);
    if (note.children?.length) {
        const ul = document.createElement('ul');
        ul.className = 'embedded-note-children';
        for (const child of note.children) ul.appendChild(noteTree(child));
        li.appendChild(ul);
    }
    return li;
}

function frame(className, note, label) {
    const box = document.createElement('div');
    box.className = className;
    const header = document.createElement('div');
    header.className = 'transclusion-header';
    header.innerHTML = note.page_name
        ? `<a href="${escapeHtml(pageUrl(note.page_name, note.id))}" class="transclusion-link" title="Go to the original note">${escapeHtml(label ?? note.page_name)}</a>`
        : '';
    const body = document.createElement('div');
    body.className = 'transclusion-body';
    const ul = document.createElement('ul');
    ul.className = 'embedded-note-tree';
    ul.appendChild(noteTree(note));
    body.appendChild(ul);
    box.append(header, body);
    return box;
}

/** Replaces `placeholder`; when it is alone in its paragraph the paragraph goes too. */
function replacePlaceholder(placeholder, element) {
    const p = placeholder.parentElement;
    const alone = p && p.tagName === 'P' && p.childNodes.length === 1;
    (alone ? p : placeholder).replaceWith(element);
}

function fail(placeholder, message) {
    placeholder.textContent = message;
    placeholder.classList.add('error');
    placeholder.removeAttribute('data-block-ref');
    placeholder.removeAttribute('data-sql-query');
}

async function hydrateTransclusion(placeholder, depth, seen) {
    const id = placeholder.dataset.blockRef;
    placeholder.removeAttribute('data-block-ref'); // never processed twice
    if (seen.has(id)) return fail(placeholder, 'This note embeds itself.');
    if (depth >= MAX_DEPTH) return fail(placeholder, `Embedded more than ${MAX_DEPTH} levels deep.`);
    let note;
    try {
        note = await notesAPI.byId(id, { children: true });
    } catch (error) {
        return fail(placeholder, error.status === 404 ? 'The embedded note no longer exists.' : 'The embedded note could not be loaded.');
    }
    const box = frame('transcluded-content', note);
    replacePlaceholder(placeholder, box);
    await hydrate(box, depth + 1, new Set(seen).add(id));
}

async function hydrateQuery(placeholder, depth, seen) {
    const sql = placeholder.dataset.sqlQuery;
    placeholder.removeAttribute('data-sql-query');
    let result;
    try {
        result = await queryAPI.notes(sql, { perPage: 50 });
    } catch (error) {
        return fail(placeholder, `Query error: ${error.message}`);
    }
    const box = document.createElement('div');
    box.className = 'sql-query-results';
    box.title = sql;
    if (!result.data.length) {
        box.innerHTML = '<p class="sql-query-empty">No notes match this query.</p>';
    }
    for (const note of result.data) {
        box.appendChild(frame('sql-query-result', note));
    }
    const total = result.pagination?.total_items ?? result.data.length;
    if (total > result.data.length) {
        const more = document.createElement('p');
        more.className = 'sql-query-more';
        more.textContent = `Showing ${result.data.length} of ${total} notes.`;
        box.appendChild(more);
    }
    replacePlaceholder(placeholder, box);
    await hydrate(box, depth + 1, seen);
}

/**
 * Fills in every transclusion and query placeholder under `root`.
 * Safe to call repeatedly: a placeholder is processed once.
 */
export async function hydrate(root = document.getElementById('notes-container'), depth = 0, seen = new Set()) {
    if (!root) return;
    const jobs = [];
    for (const el of root.querySelectorAll('.transclusion-placeholder[data-block-ref]')) {
        jobs.push(hydrateTransclusion(el, depth, seen));
    }
    for (const el of root.querySelectorAll('.sql-query-placeholder[data-sql-query]')) {
        jobs.push(hydrateQuery(el, depth, seen));
    }
    await Promise.all(jobs);
}
