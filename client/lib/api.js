// Talking to the server: one function per kind of request. Errors come back as
// ApiError with the protocol's code, status and message.

export class ApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

const BASE = new URL('api/v1/', document.baseURI);
const SAFE = new URLSearchParams(location.search).get('safe') === '1'; // no plugin code runs on the server either

async function call(method, name, { query, body } = {}) {
  const url = new URL(name, BASE);
  for (const [k, v] of Object.entries(query ?? {})) if (v !== undefined && v !== null) url.searchParams.set(k, v);
  let res;
  try {
    res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { ...(body !== undefined && !(body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}), ...(SAFE ? { 'X-Notd-Safe': '1' } : {}) },
      body: body instanceof FormData ? body : body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new ApiError(0, 'offline', 'The server cannot be reached.');
  }
  const data = (res.headers.get('content-type') || '').includes('json') ? await res.json() : null;
  if (!res.ok) throw new ApiError(res.status, data?.error?.code ?? 'http_' + res.status, data?.error?.message ?? res.statusText);
  return data;
}

export const api = {
  /** Any endpoint, such as a plugin's: api.get('git.history', { page }) and api.post('git.restore', { ... }). */
  get: (name, query) => call('GET', name, { query }),
  post: (name, body = {}) => call('POST', name, { body }),
  ping: () => call('GET', 'ping'),
  login: (password) => call('POST', 'login', { body: { password } }),
  logout: () => call('POST', 'logout', { body: {} }),
  page: (title) => call('GET', 'page', { query: { title } }),
  pageById: (id) => call('GET', 'page', { query: { id } }),
  node: (id) => call('GET', 'node', { query: { id } }),
  backlinks: (id) => call('GET', 'backlinks', { query: { id } }),
  pages: () => call('GET', 'pages'),
  journalDays: (q) => call('GET', 'journal-days', { query: typeof q === 'string' ? { month: q } : q }),
  search: (q, limit) => call('GET', 'search', { query: { q, limit } }),
  query: (q) => call('GET', 'query', { query: { q } }),
  changes: (since) => call('GET', 'changes', { query: { since } }),
  ops: (ops) => call('POST', 'ops', { body: { ops } }),
  plugins: () => call('GET', 'plugins'),
  setPlugin: (name, enabled) => call('POST', 'plugins', { body: { name, enabled } }),
  upload: (file) => { const f = new FormData(); f.append('file', file); return call('POST', 'files', { body: f }); },
};
