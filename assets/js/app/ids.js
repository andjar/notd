/**
 * Client-side ids. Notes get their permanent id at creation time, so they are
 * addressable (children, edits, moves, attachments) before the server has
 * seen them. crypto.getRandomValues works in insecure contexts too (plain
 * http on a LAN), unlike crypto.randomUUID.
 * @module app/ids
 */

function randomHex(bytes) {
    const buf = new Uint8Array(bytes);
    crypto.getRandomValues(buf);
    return Array.from(buf, b => b.toString(16).padStart(2, '0')).join('');
}

/** A new note id: 16 lowercase hex characters (same format as the server's). */
export function newId() {
    return randomHex(8);
}

/** A unique operation id, used by the server to ignore retried requests. */
export function newOpId() {
    return 'op-' + randomHex(10);
}

/** Note ids accepted by the server. */
export const NOTE_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
