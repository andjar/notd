/**
 * Note text to HTML.
 *
 * A note is Markdown plus notd's own syntax:
 *
 *   TODO text            a task (keyword first, then a space)
 *   {key::value}         a property; three or more colons make it internal
 *   [[Page Name]]        a link to a page
 *   !{{note-id}}         the note with that id, embedded
 *   SQL{select ...}      the notes a query selects, listed
 *
 * The result is always sanitized: note text can come from anywhere (pasted
 * text, RSS, mail, the API).
 *
 * @module ui/markdown
 */

import { escapeHtml, pageUrl } from '../utils.js';
import { NOTE_ID_PATTERN } from '../app/ids.js';
import { config } from '../app/page-state.js';

/** Task keywords the interface draws a checkbox for. NLR = no longer required. */
export const TASK_KEYWORDS = [...new Set([...config.taskStates, 'NLR'])];
const CLOSED_FOR_GOOD = new Set(['CANCELLED', 'NLR']);
const TASK_RE = new RegExp(`^(${TASK_KEYWORDS.join('|')}) ([\\s\\S]*)$`);

/** Splits "TODO write report" into its keyword and text; null if the note is not a task. */
export function parseTask(raw) {
    const m = TASK_RE.exec(raw || '');
    return m ? { keyword: m[1], text: m[2] } : null;
}

// Text made only of these needs neither Markdown nor sanitizing: it is
// escaped and wrapped in a paragraph. This is most notes, and it is what
// keeps very long pages fast.
const PLAIN_TEXT = /^[^\s\-+>=#\d*_`[\]{}<>!|~\\&@:][^*_`[\]{}<>!|~\\&@\n]*$/;
const HAS_LINK = /https?:|www\./i;

// Private-use characters mark where notd syntax was, while Markdown runs.
const MARK_OPEN = '';
const MARK_CLOSE = '';
const MARK_RE = /(\d+)/g;

// Code spans and fenced blocks are left exactly as written.
const CODE_RE = /(^|\n)(```|~~~)[^\n]*\n[\s\S]*?\n\2(?=\n|$)|(`+)[^`\n][\s\S]*?\3(?!`)/g;

const SYNTAX_RE = /!\{\{([^}\n]*)\}\}|SQL\{([^}]+)\}|\[\[([^\]\n]+)\]\]|\{([a-zA-Z0-9_.-]+)(:{2,})([^}\n]+)\}/g;

function syntaxHtml(m) {
    const [, blockRef, sql, pageName, key, colons, value] = m;
    if (blockRef !== undefined) {
        const ref = blockRef.trim();
        return NOTE_ID_PATTERN.test(ref)
            ? `<span class="transclusion-placeholder" data-block-ref="${escapeHtml(ref)}">Loading...</span>`
            : `<span class="transclusion-placeholder error">Invalid block reference</span>`;
    }
    if (sql !== undefined) {
        return `<span class="sql-query-placeholder" data-sql-query="${escapeHtml(sql.trim())}">Loading query...</span>`;
    }
    if (pageName !== undefined) {
        const name = pageName.trim();
        return `<span class="page-link-bracket">[[</span><a href="${escapeHtml(pageUrl(name))}" class="page-link">${escapeHtml(name)}</a><span class="page-link-bracket">]]</span>`;
    }
    const internal = colons.length >= 3;
    if (internal && !config.renderInternalProperties) return '';
    const k = key.trim();
    const cls = `property-inline ${internal ? 'property-internal' : 'property-normal'}`;
    if (k.toLowerCase() === 'tag') {
        return `<span class="${cls} property-tag"><span class="property-key">#</span><span class="property-value">${escapeHtml(value.trim())}</span></span>`;
    }
    return `<span class="${cls}"><span class="property-key">${escapeHtml(k)}</span><span class="property-separator">${colons}</span><span class="property-value">${escapeHtml(value.trim())}</span></span>`;
}

/** Replaces notd syntax outside code with markers; returns the text and the HTML each marker stands for. */
function protectSyntax(text) {
    const pieces = [];
    const mark = (segment) => segment.replace(SYNTAX_RE, (...m) => {
        pieces.push(syntaxHtml(m));
        return `${MARK_OPEN}${pieces.length - 1}${MARK_CLOSE}`;
    });
    let out = '';
    let last = 0;
    CODE_RE.lastIndex = 0;
    for (let m; (m = CODE_RE.exec(text));) {
        out += mark(text.slice(last, m.index)) + m[0];
        last = m.index + m[0].length;
    }
    return { text: out + mark(text.slice(last)), pieces };
}

let markedReady = false;
function markdownToHtml(text) {
    if (typeof marked === 'undefined' || !marked.parse) return `<p>${escapeHtml(text).replace(/\n/g, '<br>')}</p>`;
    if (!markedReady) {
        marked.setOptions({ breaks: true, gfm: true });
        markedReady = true;
    }
    try {
        return marked.parse(text);
    } catch (e) {
        console.warn('Markdown could not be parsed:', e);
        return `<p>${escapeHtml(text).replace(/\n/g, '<br>')}</p>`;
    }
}

let purifyReady = false;
/** Removes everything from HTML that could run script. Falls back to plain text if DOMPurify is missing. */
export function sanitizeHtml(html) {
    if (typeof DOMPurify === 'undefined' || !DOMPurify.sanitize) {
        return escapeHtml(html);
    }
    if (!purifyReady) {
        // Set once: passing a configuration on every call makes DOMPurify rebuild its tables each time.
        DOMPurify.setConfig({ ADD_ATTR: ['target'] });
        DOMPurify.addHook('afterSanitizeAttributes', (node) => {
            if (node.tagName === 'A' && /^https?:/i.test(node.getAttribute('href') || '')) {
                node.setAttribute('target', '_blank');
                node.setAttribute('rel', 'noopener noreferrer');
            } else if (node.tagName === 'IMG') {
                node.classList.add('content-image');
                node.setAttribute('loading', 'lazy');
            }
        });
        purifyReady = true;
    }
    return DOMPurify.sanitize(html);
}

function bodyHtml(text) {
    if (PLAIN_TEXT.test(text) && !HAS_LINK.test(text)) {
        return `<p>${escapeHtml(text)}</p>`;
    }
    const { text: marked_, pieces } = protectSyntax(text);
    const html = markdownToHtml(marked_).replace(MARK_RE, (_, i) => pieces[Number(i)] ?? '');
    return sanitizeHtml(html);
}

/**
 * Renders note text as HTML that is safe to insert into the page.
 * @param {string} raw the note's text
 * @returns {string}
 */
export function renderContent(raw) {
    const text = raw || '';
    if (text.trim() === '') return '';

    const task = parseTask(text);
    if (!task) return bodyHtml(text);

    const state = task.keyword.toLowerCase();
    const checked = task.keyword === 'DONE' || CLOSED_FOR_GOOD.has(task.keyword);
    let body = bodyHtml(task.text);
    // A one-paragraph task sits on the checkbox's line.
    const single = /^<p>([\s\S]*)<\/p>\s*$/.exec(body);
    if (single && !single[1].includes('<p>')) body = single[1];
    return `<div class="task-container ${state}">`
        + `<div class="task-checkbox-container"><input type="checkbox" class="task-checkbox" data-marker-type="${task.keyword}"${checked ? ' checked' : ''}${CLOSED_FOR_GOOD.has(task.keyword) ? ' disabled' : ''}></div>`
        + `<div class="task-content${checked ? ` ${state === 'done' ? 'done' : state}-text` : ''}"><span class="task-keyword ${state}">${task.keyword}</span> ${body}</div>`
        + `</div>`;
}
