/**
 * Loading and saving one note from an editor that runs in its own tab (the
 * Zen writer, the math notepad). A plain script: it defines window.notdNoteSync.
 *
 *   const note = await notdNoteSync.load(id);              // {id, content, version, page_id, ...}
 *   const r = await notdNoteSync.save(id, text, note.version);
 *   // r.status: 'saved' (r.note is the stored note) or 'conflict' (r.note is
 *   // the newer version someone else saved; nothing was written)
 *   await notdNoteSync.save(id, text, null);               // save regardless
 *   notdNoteSync.onChanged(pageId, callback);              // another tab changed notes on that page
 *   notdNoteSync.toast('Saved');
 *
 *   const session = await notdNoteSync.attach(codeMirror, id);  // all of the above for one editor:
 *   session.save();                                         // loads the note, saves with a conflict check,
 *                                                           // follows changes made elsewhere, warns before
 *                                                           // closing with unsaved text
 *
 * Saving tells open notd tabs (over the 'notd-sync' channel the outline
 * listens on), so they show the new text without a reload.
 */
(function () {
    // Both editors live two folders below notd's root (extensions/<name>/).
    const API = new URL('../../api/v1/', window.location.href).href;
    const channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('notd-sync') : null;

    async function request(endpoint, body) {
        const response = await fetch(API + endpoint, {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'Content-Type': 'application/json', Accept: 'application/json' } : { Accept: 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
        });
        const json = await response.json().catch(() => null);
        if (!json) throw new Error(`The server answered ${response.status}.`);
        if (json.status !== 'success') throw new Error(json.message || `The server answered ${response.status}.`);
        return json.data;
    }

    function opId() {
        return 'editor-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }

    async function load(id) {
        return request('notes.php?id=' + encodeURIComponent(id));
    }

    async function save(id, content, baseVersion) {
        const op = { op_id: opId(), type: 'edit', id: String(id), content };
        if (baseVersion !== null && baseVersion !== undefined) op.base_version = baseVersion;
        const data = await request('ops.php', { ops: [op] });
        const note = (data.notes || []).find((n) => String(n.id) === String(id)) || null;
        const warning = (data.warnings || [])[0];
        if (warning && warning.code === 'conflict') return { status: 'conflict', note };
        if (warning && warning.code === 'missing') throw new Error('The note no longer exists.');
        if (note && channel) channel.postMessage({ type: 'changed', pageIds: [Number(note.page_id)] });
        return { status: 'saved', note };
    }

    function onChanged(pageId, callback) {
        if (!channel) return;
        channel.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'changed' && (e.data.pageIds || []).map(Number).includes(Number(pageId))) callback();
        });
    }

    let toastTimer = null;
    function toast(message, { error = false } = {}) {
        let el = document.getElementById('note-sync-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'note-sync-toast';
            el.setAttribute('role', 'status');
            el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);padding:8px 16px;border-radius:6px;'
                + 'font:14px/1.4 system-ui,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.2);z-index:1000;transition:opacity .2s;max-width:80vw;';
            document.body.appendChild(el);
        }
        el.textContent = message;
        el.style.background = error ? '#b3261e' : '#323232';
        el.style.color = '#fff';
        el.style.opacity = '1';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.style.opacity = '0'; }, error ? 6000 : 2000);
    }

    const CONFLICT = 'This note was changed in notd since you opened it here.\n\n'
        + 'OK replaces that change with your text.\nCancel keeps it; your text stays here, unsaved.';

    /** Connects a CodeMirror editor to a note. @returns {Promise<{save: function}|null>} */
    async function attach(editor, id) {
        let version = null;
        let pageId = null;
        let dirty = false;
        let quiet = false;
        let saving = false;
        const setText = (text) => {
            quiet = true;
            const cursor = editor.getCursor();
            editor.setValue(text);
            editor.setCursor(cursor);
            quiet = false;
            dirty = false;
        };
        try {
            const note = await load(id);
            version = note.version;
            pageId = note.page_id;
            setText(note.content || '');
            editor.clearHistory();
        } catch (error) {
            toast('Could not load the note: ' + error.message, { error: true });
            return null;
        }
        editor.on('change', () => { if (!quiet) dirty = true; });
        window.addEventListener('beforeunload', (e) => {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });
        // Someone changed the note in notd: take the new text if nothing is unsaved here.
        onChanged(pageId, async () => {
            if (saving) return;
            const note = await load(id).catch(() => null);
            if (!note || note.version === version) return;
            if (!dirty) {
                version = note.version;
                setText(note.content || '');
                toast('Updated with the newer text from notd');
            } else {
                toast('This note was changed in notd. Saving will ask before replacing that change.');
            }
        });

        async function saveNow() {
            const text = editor.getValue();
            saving = true;
            try {
                let result = await save(id, text, version);
                if (result.status === 'conflict') {
                    if (!window.confirm(CONFLICT)) {
                        toast('Not saved', { error: true });
                        return;
                    }
                    result = await save(id, text, null);
                }
                if (result.note) version = result.note.version;
                if (editor.getValue() === text) dirty = false;
                toast('Saved');
            } catch (error) {
                toast('Could not save: ' + error.message, { error: true });
            } finally {
                saving = false;
            }
        }
        return { save: saveNow };
    }

    window.notdNoteSync = { load, save, onChanged, toast, attach };
})();
