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

/**
 * A new note id: a UUIDv7 (RFC 9562), e.g. 0192f6a0-7c3e-7b1a-9f3e-2b1c4d5e6f70.
 * The first 48 bits are the creation time in milliseconds, so ids sort roughly
 * by creation time, which keeps the database index compact. The server treats
 * ids as opaque strings (see NOTE_ID_PATTERN); older ids stay valid.
 */
export function newId(now = Date.now()) {
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    let t = now;
    for (let i = 5; i >= 0; i--) {
        b[i] = t % 256;
        t = Math.floor(t / 256);
    }
    b[6] = (b[6] & 0x0f) | 0x70; // version 7
    b[8] = (b[8] & 0x3f) | 0x80; // RFC variant
    const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** A unique operation id, used by the server to ignore retried requests. */
export function newOpId() {
    return 'op-' + randomHex(10);
}

/** Note ids accepted by the server. */
export const NOTE_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
