window.addEventListener('DOMContentLoaded', async function () {
  // Helper to load a theme CSS dynamically
  function loadThemeCss(themeName) {
    // Remove any existing theme link
    let oldLink = document.getElementById('cm-theme-css');
    if (oldLink) oldLink.remove();
    // Add new theme link
    let link = document.createElement('link');
    link.rel = 'stylesheet';
    link.id = 'cm-theme-css';
    link.href = `codemirror/themes/${themeName}.css`;
    document.head.appendChild(link);
  }

  // Helper to get note_id or page_id from URL
  function getNoteOrPageIdFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return {
      note_id: params.get('note_id'),
      page_id: params.get('page_id')
    };
  }

  // --- API CLIENT (direct, not window.notesAPI) ---
  const API_BASE_URL = (document.querySelector('base[href]')?.href || '/') + 'api/v1/';
  async function apiRequest(endpoint, method = 'GET', body = null) {
    const options = {
      method,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    };
    if (body) {
      if (body instanceof FormData) {
        options.body = body;
      } else {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
      }
    }
    const response = await fetch(API_BASE_URL + endpoint, options);
    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await response.text();
      throw new Error(`Invalid response: ${text}`);
    }
    const data = await response.json();
    if (data.status === 'error') throw new Error(data.message || 'API error');
    return data.data;
  }
  // Notes API
  const notesAPI = {
    getNote: (noteId) => apiRequest(`notes.php?id=${noteId}`),
    batchUpdateNotes: (operations) => apiRequest('notes.php', 'POST', { action: 'batch', operations })
  };
  // Pages API (optional, for page editing)
  const pagesAPI = {
    getPage: (pageId) => apiRequest(`pages.php?id=${pageId}`),
    updatePage: (id, pageData) => apiRequest('pages.php', 'POST', { _method: 'PUT', id, ...pageData })
  };

  fetch('config.json')
    .then(response => response.json())
    .then(async config => {
      const textarea = document.getElementById('zen-textarea');
      const saveBtn = document.getElementById('save-btn');
      const themeToggle = document.getElementById('theme-toggle');
      const themeIcon = document.getElementById('theme-icon');
      const defaultLight = config.defaultLightTheme || 'duotone-light';
      const defaultDark = config.defaultDarkTheme || 'zenburn';
      let theme = localStorage.getItem('zen-theme') || 'light';
      let cmTheme = (theme === 'dark') ? defaultDark : defaultLight;
      loadThemeCss(cmTheme);
      document.body.classList.toggle('zen-dark', theme === 'dark');
      if (themeIcon) {
        themeIcon.innerHTML = theme === 'dark'
          ? `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><path d="M12 1v2"></path><path d="M12 21v2"></path><path d="M4.22 4.22l1.42 1.42"></path><path d="M18.36 18.36l1.42 1.42"></path><path d="M1 12h2"></path><path d="M21 12h2"></path><path d="M4.22 19.78l1.42-1.42"></path><path d="M18.36 5.64l1.42-1.42"></path></svg>`
          : `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z"></path></svg>`;
      }
      const editor = CodeMirror.fromTextArea(textarea, {
        mode: 'markdown',
        theme: cmTheme,
        lineNumbers: false,
        lineWrapping: true,
        autofocus: true,
        extraKeys: {
          'Ctrl-S': cm => saveContent(cm),
          'Cmd-S': cm => saveContent(cm)
        }
      });

      // --- NOTE/PAGE LOADING LOGIC ---
      const { note_id, page_id } = getNoteOrPageIdFromUrl();
      if (note_id) {
        try {
          const note = await notesAPI.getNote(note_id);
          if (note && note.content !== undefined && note.content !== null) {
            editor.setValue(note.content);
          } else {
            editor.setValue('');
          }
        } catch (err) {
          editor.setValue('');
          alert('Failed to load note: ' + (err.message || err));
        }
      } else if (page_id) {
        try {
          const page = await pagesAPI.getPage(page_id);
          if (page && page.content !== undefined && page.content !== null) {
            editor.setValue(page.content);
          } else {
            editor.setValue('');
          }
        } catch (err) {
          editor.setValue('');
          alert('Failed to load page: ' + (err.message || err));
        }
      } else {
        // Fallback: load from localStorage
        const saved = localStorage.getItem('zen-md-content');
        if (saved) {
          editor.setValue(saved);
        }
      }

      // Save button
      saveBtn.onclick = () => saveContent(editor);
      // Theme toggle
      if (themeToggle && themeIcon) {
        themeToggle.onclick = () => {
          theme = (theme === 'dark') ? 'light' : 'dark';
          cmTheme = (theme === 'dark') ? defaultDark : defaultLight;
          document.body.classList.toggle('zen-dark', theme === 'dark');
          editor.setOption('theme', cmTheme);
          themeIcon.innerHTML = theme === 'dark'
            ? `<svg width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"5\"></circle><path d=\"M12 1v2\"></path><path d=\"M12 21v2\"></path><path d=\"M4.22 4.22l1.42 1.42\"></path><path d=\"M18.36 18.36l1.42 1.42\"></path><path d=\"M1 12h2\"></path><path d=\"M21 12h2\"></path><path d=\"M4.22 19.78l1.42-1.42\"></path><path d=\"M18.36 5.64l1.42-1.42\"></path></svg>`
            : `<svg width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z\"></path></svg>`;
          localStorage.setItem('zen-theme', theme);
          loadThemeCss(cmTheme);
        };
      }

      // --- NOTE/PAGE SAVING LOGIC ---
      async function saveContent(cm) {
        const value = cm.getValue();
        const { note_id, page_id } = getNoteOrPageIdFromUrl();
        if (note_id) {
          try {
            await notesAPI.batchUpdateNotes([
              { type: 'update', payload: { id: note_id, content: value } }
            ]);
            alert('Note saved!');
          } catch (err) {
            alert('Failed to save note: ' + (err.message || err));
          }
        } else if (page_id) {
          try {
            await pagesAPI.updatePage(page_id, { content: value });
            alert('Page saved!');
          } catch (err) {
            alert('Failed to save page: ' + (err.message || err));
          }
        } else {
          localStorage.setItem('zen-md-content', value);
          alert('Saved to local storage!');
        }
      }
    });
});

// Helper: Render Markdown and highlight syntax (headers, bold, italic, links, code, lists)
function renderMarkdownStyled(md) {
  // Headers
  md = md.replace(/^(#{1,6})(\s*)(.+)$/gm, (m, hashes, space, text) => {
    const level = hashes.length;
    return `<span class='zen-md-muted'>${hashes}</span>${space}<span class='zen-md-header' style='font-size:${2.5 - (level-1)*0.3}rem;'>${text}</span>`;
  });
  // Bold (**text** or __text__)
  md = md.replace(/(\*\*|__)(.*?)\1/g, (m, sym, text) => {
    return `<span class='zen-md-muted'>${sym}</span><span class='zen-md-bold'>${text}</span><span class='zen-md-muted'>${sym}</span>`;
  });
  // Italic (*text* or _text_)
  md = md.replace(/(\*|_)([^\*_][^\1]*?)\1/g, (m, sym, text) => {
    return `<span class='zen-md-muted'>${sym}</span><span class='zen-md-italic'>${text}</span><span class='zen-md-muted'>${sym}</span>`;
  });
  // Inline code
  md = md.replace(/`([^`]+)`/g, (m, code) => {
    return `<span class='zen-md-muted'></span><span class='zen-md-code'>${code}</span><span class='zen-md-muted'></span>`;
  });
  // Links [text](url)
  md = md.replace(/\[([^\]]+)\]\(([^\)]+)\)/g, (m, text, url) => {
    return `<span class='zen-md-muted'>[</span><span class='zen-md-link'>${text}</span><span class='zen-md-muted'>]</span><span class='zen-md-muted'>(</span><span class='zen-md-link'>${url}</span><span class='zen-md-muted'>)</span>`;
  });
  // Lists - or * or +
  md = md.replace(/^(\s*)([-*+])\s+/gm, (m, space, bullet) => {
    return `${space}<span class='zen-md-list'>${bullet}</span> `;
  });
  // Blockquotes
  md = md.replace(/^(>)(\s*)(.*)$/gm, (m, gt, space, text) => {
    return `<span class='zen-md-muted'>${gt}</span>${space}<span class='zen-md-header' style='font-size:1.1rem;font-weight:400;'>${text}</span>`;
  });
  // Horizontal rule
  md = md.replace(/^([-*_]){3,}$/gm, () => {
    return `<hr>`;
  });
  // Paragraphs (add <br> for newlines)
  md = md.replace(/\n/g, '<br>');
  return md.replace(/\u007F/g, '`');
} 