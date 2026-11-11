/**
 * The dialog listing every note that links to this page (Ctrl+B).
 *
 * @module app/backlinks
 */

import { searchAPI } from '../api_client.js';
import { page } from './page-state.js';
import { escapeHtml, pageUrl, looksEncrypted } from '../utils.js';

const $ = (id) => document.getElementById(id);

async function open() {
    const modal = $('backlinks-modal');
    const box = $('backlinks-modal-content');
    if (!modal || !box || !page.name) return;
    box.innerHTML = '<p>Loading backlinks...</p>';
    modal.classList.add('active');
    try {
        const { results = [] } = await searchAPI.backlinks(page.name, { perPage: 200 });
        if (!results.length) {
            box.innerHTML = '<p>No other note links to this page yet.</p>';
            return;
        }
        const byPage = new Map();
        for (const r of results) {
            if (!byPage.has(r.page_name)) byPage.set(r.page_name, []);
            byPage.get(r.page_name).push(r);
        }
        box.innerHTML = [...byPage].map(([name, notes]) => `
            <div class="backlink-item">
                <a href="${escapeHtml(pageUrl(name))}" class="page-link">${escapeHtml(name)}</a>
                ${notes.map((n) => `<a href="${escapeHtml(pageUrl(name, n.note_id))}" class="backlink-snippet">${
                    looksEncrypted(n.content) ? '<em>Encrypted note</em>' : escapeHtml(n.content_snippet ?? n.content ?? '')
                }</a>`).join('')}
            </div>`).join('');
    } catch (error) {
        box.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    }
}

const close = () => $('backlinks-modal')?.classList.remove('active');

export function initBacklinks() {
    $('open-backlinks-modal-btn')?.addEventListener('click', open);
    $('backlinks-modal-close')?.addEventListener('click', close);
    $('backlinks-modal')?.addEventListener('mousedown', (e) => { if (e.target === e.currentTarget) close(); });
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key === 'b') {
            e.preventDefault();
            open();
        } else if (e.key === 'Escape') {
            close();
        }
    });
}
