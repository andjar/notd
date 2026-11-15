/**
 * "Scheduled and due" below the outline of a journal page: notes with
 * {scheduled::YYYY-MM-DD} or {deadline::YYYY-MM-DD} for that day and, on
 * today's page, open tasks whose date has passed.
 *
 * Notes on the page itself are left out (they are right above). Ticking a
 * task's checkbox marks it DONE where it lives.
 *
 * @module app/agenda
 */

import { searchAPI, notesAPI } from '../api_client.js';
import { page } from './page-state.js';
import { renderContent, parseTask } from '../ui/markdown.js';
import { hydrate } from '../ui/embeds.js';
import { toast } from '../ui/dialogs.js';
import { escapeHtml, pageUrl, localDate, looksEncrypted } from '../utils.js';

const DATE_PROPERTY = /\s*\{(scheduled|deadline):{2,}[^}\n]*\}/g;
const channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('notd-sync') : null;
let reloadTimer = null;

const section = () => document.getElementById('agenda');

/** "Mon 16 Nov" for a YYYY-MM-DD in the browser's language. */
function shortDate(day) {
    const [y, m, d] = day.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}

function daysBetween(from, to) {
    const [a, b] = [from, to].map((s) => { const [y, m, d] = s.split('-').map(Number); return Date.UTC(y, m - 1, d); });
    return Math.round((b - a) / 86400000);
}

/** What to say about an item's date: "Deadline", "Scheduled 13 Nov · 2 days overdue", ... */
function describe(item, day) {
    const parts = [];
    for (const kind of ['deadline', 'scheduled']) {
        const value = item[kind];
        if (!value) continue;
        const date = value.slice(0, 10);
        const time = value.slice(11).trim();
        const label = kind === 'deadline' ? 'Deadline' : 'Scheduled';
        parts.push(`${label} ${date === day ? (day === localDate() ? 'today' : 'this day') : shortDate(date)}${time ? ' ' + time : ''}`);
    }
    if (item.overdue) {
        const late = daysBetween(item.date, day);
        parts.push(`${late} day${late === 1 ? '' : 's'} overdue`);
    }
    return parts.join(' · ');
}

function itemHtml(item, day) {
    const text = looksEncrypted(item.content) ? '' : String(item.content || '').replace(DATE_PROPERTY, '');
    const body = text ? renderContent(text) : '<p><em>Encrypted note</em></p>';
    return `<li class="agenda-item${item.overdue ? ' overdue' : ''}" data-note-id="${escapeHtml(item.note_id)}" data-page-id="${Number(item.page_id)}">
        <div class="agenda-meta">
            <span class="agenda-when">${escapeHtml(describe(item, day))}</span>
            <a class="agenda-source page-link" href="${escapeHtml(pageUrl(item.page_name, item.note_id))}">${escapeHtml(item.page_name)}</a>
        </div>
        <div class="agenda-content">${body}</div>
    </li>`;
}

/** Shows the agenda of the open page, if it is a journal page. */
export async function loadAgenda() {
    const box = section();
    if (!box) return;
    const day = /^\d{4}-\d{2}-\d{2}$/.test(page.name || '') ? page.name : null;
    if (!day || page.content?.includes('{encrypt::')) {
        box.hidden = true;
        return;
    }
    let results;
    try {
        ({ results = [] } = await searchAPI.agenda(day, { overdue: day === localDate() }));
    } catch (error) {
        console.warn('[agenda] could not load', error);
        box.hidden = true;
        return;
    }
    const items = results.filter((r) => Number(r.page_id) !== Number(page.id));
    const list = box.querySelector('.agenda-list');
    list.innerHTML = items.map((item) => itemHtml(item, day)).join('');
    box.hidden = items.length === 0;
    if (items.length) hydrate(list);
}

/** Marks a task DONE in its own page. */
async function complete(checkbox) {
    const item = checkbox.closest('.agenda-item');
    if (!item) return;
    checkbox.disabled = true;
    try {
        const note = await notesAPI.byId(item.dataset.noteId);
        const task = parseTask(note.content);
        if (!task) throw new Error('This note is no longer a task.');
        const content = `DONE ${task.text}`;
        await notesAPI.applyOps([{ op_id: 'agenda-' + Date.now().toString(36) + Math.random().toString(36).slice(2), type: 'edit', id: note.id, content }]);
        channel?.postMessage({ type: 'changed', pageIds: [Number(note.page_id)] });
        item.remove();
        if (!section().querySelector('.agenda-item')) section().hidden = true;
        toast('Marked as done');
    } catch (error) {
        checkbox.checked = false;
        checkbox.disabled = false;
        toast(`Could not update the task: ${error.message}`, { error: true });
    }
}

export function initAgenda() {
    section()?.addEventListener('click', (event) => {
        const checkbox = event.target.closest('.task-checkbox');
        if (checkbox) complete(checkbox);
    });
    // Another tab changed some notes: the agenda may have changed too.
    if (channel) {
        channel.addEventListener('message', (e) => {
            if (e.data?.type !== 'changed') return;
            clearTimeout(reloadTimer);
            reloadTimer = setTimeout(loadAgenda, 500);
        });
    }
}
