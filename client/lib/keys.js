// Fractional sort keys and ids for the browser (and for Node in tests).
//
// Sort keys are base-62 strings ordered by plain byte comparison; a key can
// always be made strictly between two others, so moving a block rewrites only
// its own key. After David Greenspan's "Implementing Fractional Indexing" and
// the MIT-licensed fractional-indexing package. server/sortkeys.php is the same
// algorithm in PHP; tests/fixtures/sort_keys.json checks that both agree.

export const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
const ZERO = DIGITS[0];
const SMALLEST_INTEGER = 'A' + ZERO.repeat(26);

function midpoint(a, b) {
    if (b !== null && a >= b) throw new Error(`midpoint: ${a} >= ${b}`);
    if (a.slice(-1) === ZERO || (b !== null && b.slice(-1) === ZERO)) {
        throw new Error('midpoint: trailing zero');
    }
    if (b !== null) {
        let n = 0;
        while ((a[n] || ZERO) === b[n]) n++;
        if (n > 0) return b.slice(0, n) + midpoint(a.slice(n), b.slice(n));
    }
    const digitA = a ? DIGITS.indexOf(a[0]) : 0;
    const digitB = b !== null ? DIGITS.indexOf(b[0]) : DIGITS.length;
    if (digitB - digitA > 1) {
        return DIGITS[Math.round(0.5 * (digitA + digitB))];
    }
    if (b !== null && b.length > 1) return b.slice(0, 1);
    return DIGITS[digitA] + midpoint(a.slice(1), null);
}

function integerLength(head) {
    if (head >= 'a' && head <= 'z') return head.charCodeAt(0) - 97 + 2;
    if (head >= 'A' && head <= 'Z') return 90 - head.charCodeAt(0) + 2;
    throw new Error(`invalid order key head: ${head}`);
}

function integerPart(key) {
    const len = integerLength(key[0]);
    if (len > key.length) throw new Error(`invalid order key: ${key}`);
    return key.slice(0, len);
}

export function isValidKey(key) {
    if (typeof key !== 'string' || key.length === 0) return false;
    try {
        if (key === SMALLEST_INTEGER) return false;
        for (const ch of key) if (!DIGITS.includes(ch)) return false;
        const i = integerPart(key);
        const f = key.slice(i.length);
        return f.slice(-1) !== ZERO;
    } catch {
        return false;
    }
}

function validate(key) {
    if (!isValidKey(key)) throw new Error(`invalid order key: ${key}`);
}

function incrementInteger(x) {
    const head = x[0];
    const digs = x.slice(1).split('');
    let carry = true;
    for (let i = digs.length - 1; carry && i >= 0; i--) {
        const d = DIGITS.indexOf(digs[i]) + 1;
        if (d === DIGITS.length) {
            digs[i] = ZERO;
        } else {
            digs[i] = DIGITS[d];
            carry = false;
        }
    }
    if (!carry) return head + digs.join('');
    if (head === 'Z') return 'a' + ZERO;
    if (head === 'z') return null;
    const h = String.fromCharCode(head.charCodeAt(0) + 1);
    if (h > 'a') digs.push(ZERO); else digs.pop();
    return h + digs.join('');
}

function decrementInteger(x) {
    const head = x[0];
    const digs = x.slice(1).split('');
    let borrow = true;
    for (let i = digs.length - 1; borrow && i >= 0; i--) {
        const d = DIGITS.indexOf(digs[i]) - 1;
        if (d === -1) {
            digs[i] = DIGITS.slice(-1);
        } else {
            digs[i] = DIGITS[d];
            borrow = false;
        }
    }
    if (!borrow) return head + digs.join('');
    if (head === 'a') return 'Z' + DIGITS.slice(-1);
    if (head === 'A') return null;
    const h = String.fromCharCode(head.charCodeAt(0) - 1);
    if (h < 'Z') digs.push(DIGITS.slice(-1)); else digs.pop();
    return h + digs.join('');
}

/**
 * A key strictly between `a` and `b` (either may be null = open end).
 * @param {string|null} a
 * @param {string|null} b
 * @returns {string}
 */
export function keyBetween(a, b) {
    if (a !== null) validate(a);
    if (b !== null) validate(b);
    if (a !== null && b !== null && a >= b) throw new Error(`keyBetween: ${a} >= ${b}`);
    if (a === null) {
        if (b === null) return 'a' + ZERO;
        const ib = integerPart(b);
        const fb = b.slice(ib.length);
        if (ib === SMALLEST_INTEGER) return ib + midpoint('', fb);
        if (ib < b) return ib;
        const res = decrementInteger(ib);
        if (res === null) throw new Error('keyBetween: cannot decrement any more');
        return res;
    }
    if (b === null) {
        const ia = integerPart(a);
        const fa = a.slice(ia.length);
        const i = incrementInteger(ia);
        return i === null ? ia + midpoint(fa, null) : i;
    }
    const ia = integerPart(a);
    const fa = a.slice(ia.length);
    const ib = integerPart(b);
    const fb = b.slice(ib.length);
    if (ia === ib) return ia + midpoint(fa, fb);
    const i = incrementInteger(ia);
    if (i === null) throw new Error('keyBetween: cannot increment any more');
    if (i < b) return i;
    return ia + midpoint(fa, null);
}

/**
 * `n` ascending keys strictly between `a` and `b`.
 */
export function keysBetween(a, b, n) {
    if (n === 0) return [];
    if (n === 1) return [keyBetween(a, b)];
    if (b === null) {
        let c = keyBetween(a, b);
        const out = [c];
        for (let i = 0; i < n - 1; i++) { c = keyBetween(c, b); out.push(c); }
        return out;
    }
    if (a === null) {
        let c = keyBetween(a, b);
        const out = [c];
        for (let i = 0; i < n - 1; i++) { c = keyBetween(a, c); out.push(c); }
        return out.reverse();
    }
    const mid = Math.floor(n / 2);
    const c = keyBetween(a, b);
    return [...keysBetween(a, c, mid), c, ...keysBetween(c, b, n - mid - 1)];
}

/** Byte-order comparison used everywhere notes are sorted (matches SQLite BINARY). */
export function compareKeys(a, b) {
    return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * A new node id: a UUIDv7, e.g. 0192f6a0-7c3e-7b1a-9f3e-2b1c4d5e6f70. The first
 * 48 bits are milliseconds, so ids sort by creation time. Clients make ids
 * themselves, so a node can be referred to before the server has seen it.
 * crypto.getRandomValues also works on plain http, unlike crypto.randomUUID.
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
    b[8] = (b[8] & 0x3f) | 0x80; // variant 10
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export const ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
