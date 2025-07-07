// Math Notepad JavaScript
window.addEventListener('DOMContentLoaded', async function () {
    const textarea = document.getElementById('math-input');
    const resultsDiv = document.getElementById('math-results');
    const saveBtn = document.getElementById('save-btn');
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');

    // --- Configuration ---
    const config = await fetch('config.json').then(res => res.json());
    const defaultLight = config.defaultLightTheme || 'default'; // CodeMirror default
    const defaultDark = config.defaultDarkTheme || 'zenburn'; // A common dark theme

    let currentThemeMode = localStorage.getItem('math-notepad-theme') || 'light';
    let currentCmTheme = (currentThemeMode === 'dark') ? defaultDark : defaultLight;

    // --- Helper to load a CodeMirror theme CSS dynamically ---
    function loadThemeCss(themeName) {
        let oldLink = document.getElementById('cm-theme-css');
        if (oldLink) oldLink.remove();
        if (themeName === 'default') return; // No CSS needed for default CM theme

        let link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id = 'cm-theme-css';
        link.href = `../zen/codemirror/themes/${themeName}.css`; // Corrected path to 'themes'
        document.head.appendChild(link);
    }
    
    function updateThemeDisplay() {
        document.body.classList.toggle('notepad-dark', currentThemeMode === 'dark');
        if (themeIcon) {
            themeIcon.innerHTML = currentThemeMode === 'dark'
                ? `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><path d="M12 1v2"></path><path d="M12 21v2"></path><path d="M4.22 4.22l1.42 1.42"></path><path d="M18.36 18.36l1.42 1.42"></path><path d="M1 12h2"></path><path d="M21 12h2"></path><path d="M4.22 19.78l1.42-1.42"></path><path d="M18.36 5.64l1.42-1.42"></path></svg>` // Sun icon
                : `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z"></path></svg>`; // Moon icon
        }
        editor.setOption('theme', currentCmTheme);
        loadThemeCss(currentCmTheme);
    }
    
    loadThemeCss(currentCmTheme); // Initial theme load

    // --- CodeMirror Setup ---
    const editor = CodeMirror.fromTextArea(textarea, {
        lineNumbers: true,
        mode: null, // Using null mode as we parse manually. Could define a simple mode later.
        theme: currentCmTheme,
        autofocus: true,
        lineWrapping: true,
        gutters: ["CodeMirror-linenumbers"],
        extraKeys: {
            'Ctrl-S': cm => saveContent(cm),
            'Cmd-S': cm => saveContent(cm)
        }
    });

    // Adjust CodeMirror layout if it's not filling height properly
    editor.setSize(null, "100%");


    // --- Calculation Logic ---
    let variables = {};

    function evaluateLine(line) {
        line = line.trim();
        if (line === '' || line.startsWith('#')) {
            return { type: 'comment', value: '' };
        }
        // Remove inline comments (anything after #)
        const hashIndex = line.indexOf('#');
        if (hashIndex !== -1) {
            line = line.slice(0, hashIndex).trim();
            if (line === '') {
                return { type: 'comment', value: '' };
            }
        }

        // Variable assignment: x = 10 or y = x * 2
        const assignmentMatch = line.match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/);
        if (assignmentMatch) {
            const varName = assignmentMatch[1];
            const expression = assignmentMatch[2];
            try {
                const value = evaluateExpression(expression);
                variables[varName] = value;
                return { type: 'assignment', name: varName, value: value };
            } catch (e) {
                return { type: 'error', value: e.message };
            }
        }

        // Expression evaluation: 10 + 5 or x * y
        try {
            const value = evaluateExpression(line);
            return { type: 'expression', value: value };
        } catch (e) {
            // If it's not a valid assignment and fails as an expression, it might be an error or just an unparseable line
            return { type: 'error', value: e.message };
        }
    }

    function evaluateExpression(expression) {
        // Sanitize expression: allow variables, numbers, +, -, *, /, (, )
        // Replace variable names with their values
        let processedExpression = expression.replace(/\b[a-zA-Z_][a-zA-Z0-9_]*\b/g, (match, offset, string) => {
            // Don't replace if preceded by a dot (object property)
            if (offset > 0 && string[offset - 1] === '.') {
                return match;
            }
            if (variables.hasOwnProperty(match)) {
                return variables[match];
            }
            if (Object.getOwnPropertyNames(Math).includes(match)) {
                return `Math.${match}`;
            }
            throw new Error(`Undefined variable: ${match}`);
        });

        // Basic security: Disallow characters not part of a safe expression
        // This is a very basic check and not foolproof for complex scenarios.
        // For a production app, a proper parser/evaluator (like math.js) is much safer.
        if (/[^0-9a-zA-Z_+\-*/().\sMath]/.test(processedExpression)) {
             // Check for function calls like Math.sqrt(...)
            if (!/Math\.[a-zA-Z_][a-zA-Z0-9_]*\s*\(/.test(processedExpression) && !/Math\.PI|Math\.E/.test(processedExpression) ) {
                 throw new Error("Invalid characters in expression.");
            }
        }


        try {
            // Using Function constructor for evaluation. Be cautious with this in broader contexts.
            // It's safer than eval() but still has risks if expression is not controlled.
            const result = new Function(`return ${processedExpression}`)();
            if (typeof result !== 'number' || isNaN(result)) {
                throw new Error("Invalid calculation result");
            }
            return result;
        } catch (e) {
            console.error("Evaluation error:", e, "Original expression:", expression, "Processed:", processedExpression);
            throw new Error(`Calculation error: ${e.message}`);
        }
    }

    function processAndDisplayResults() {
        const lines = editor.getValue().split('\n');
        resultsDiv.innerHTML = ''; // Clear previous results
        variables = {}; // Reset variables for each full calculation run

        lines.forEach((lineContent, index) => {
            const result = evaluateLine(lineContent);
            const lineResultDiv = document.createElement('div');
            lineResultDiv.classList.add('result-line');
            lineResultDiv.style.height = editor.defaultTextHeight() + 'px'; // Align with editor lines

            if (result.type === 'assignment') {
                lineResultDiv.textContent = `${result.name} = ${result.value}`;
            } else if (result.type === 'expression') {
                lineResultDiv.textContent = `= ${result.value}`;
            } else if (result.type === 'error') {
                lineResultDiv.textContent = `Error: ${result.value}`;
                lineResultDiv.classList.add('error');
            } else if (result.type === 'comment' || lineContent.trim() === '') {
                lineResultDiv.textContent = ' '; // Keep empty lines aligned
                lineResultDiv.classList.add('empty');
            }
            resultsDiv.appendChild(lineResultDiv);
        });
    }

    editor.on('change', () => {
        processAndDisplayResults();
    });

    // Sync scrolling between editor and results
    let editorScrolling = false;
    let resultsScrolling = false;

    editor.on('scroll', () => {
        if (editorScrolling) return;
        resultsScrolling = true;
        const scrollInfo = editor.getScrollInfo();
        resultsDiv.scrollTop = scrollInfo.top;
        // Reset after a short delay to allow the other panel to scroll naturally if user switches focus
        setTimeout(() => resultsScrolling = false, 100); 
    });

    resultsDiv.addEventListener('scroll', () => {
        if (resultsScrolling) return;
        editorScrolling = true;
        // This is trickier as resultsDiv scrolling doesn't map 1:1 to CodeMirror's complex scroll structure easily.
        // A simpler approach might be to just scroll to the same percentage.
        // However, for now, we'll focus on editor -> results scroll.
        // Optional: Implement results -> editor scroll if truly needed, but it can be complex.
        setTimeout(() => editorScrolling = false, 100);
    });

    // --- API CLIENT (direct, not window.notesAPI for robustness in extensions) ---
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
        if (!response.ok) { // Check for non-2xx responses
            const errorText = await response.text();
            throw new Error(`API request failed: ${response.status} ${response.statusText} - ${errorText}`);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            // If text is empty but response was OK, it might be a 204 No Content for example
            if (response.ok && !text) return null; 
            throw new Error(`Invalid API response type: ${contentType}. Content: ${text}`);
        }
        const data = await response.json();
        if (data.status === 'error') throw new Error(data.message || 'API error');
        return data.data;
    }

    const notesAPI = {
        getNote: (noteId) => apiRequest(`notes.php?id=${noteId}`),
        batchUpdateNotes: (operations) => apiRequest('notes.php', 'POST', { action: 'batch', operations })
    };
    const pagesAPI = {
        getPage: (pageId) => apiRequest(`pages.php?id=${pageId}`),
        updatePage: (id, pageData) => apiRequest('pages.php', 'POST', { _method: 'PUT', id, ...pageData })
    };

    // --- NOTE/PAGE LOADING LOGIC ---
    function getNoteOrPageIdFromUrl() {
        const params = new URLSearchParams(window.location.search);
        return {
            note_id: params.get('note_id'),
            page_id: params.get('page_id')
        };
    }

    async function loadInitialContent() {
        const { note_id, page_id } = getNoteOrPageIdFromUrl();
        let contentToLoad = '';
        try {
            if (note_id) {
                const note = await notesAPI.getNote(note_id);
                contentToLoad = (note && note.content !== undefined && note.content !== null) ? note.content : '';
            } else if (page_id) {
                const page = await pagesAPI.getPage(page_id);
                contentToLoad = (page && page.content !== undefined && page.content !== null) ? page.content : '';
            } else {
                contentToLoad = localStorage.getItem('math-notepad-content') || '# Welcome to Math Notepad!\n# Define variables like:\na = 10\nb = a * 2\n\n# Perform calculations:\na + b\nPI * 100 # Use PI for Math.PI\nsqrt(b)';
            }
        } catch (err) {
            console.error('Failed to load content:', err);
            alert('Failed to load content: ' + (err.message || err));
            contentToLoad = `# Error loading content.\n${err.message}`;
        }
        editor.setValue(contentToLoad);
        processAndDisplayResults(); // Initial calculation after loading
    }

    // --- Save button ---
    if (saveBtn) {
        saveBtn.onclick = () => saveContent(editor);
    }

    // --- Theme toggle ---
    if (themeToggle && themeIcon) {
        themeToggle.onclick = () => {
            currentThemeMode = (currentThemeMode === 'dark') ? 'light' : 'dark';
            currentCmTheme = (currentThemeMode === 'dark') ? defaultDark : defaultLight;
            localStorage.setItem('math-notepad-theme', currentThemeMode);
            updateThemeDisplay();
        };
    }
    updateThemeDisplay(); // Set initial theme icon and body class

    // --- NOTE/PAGE SAVING LOGIC ---
    async function saveContent(cm) {
        const value = cm.getValue();
        const { note_id, page_id } = getNoteOrPageIdFromUrl();
        try {
            if (note_id) {
                await notesAPI.batchUpdateNotes([
                    { type: 'update', payload: { id: note_id, content: value } }
                ]);
                alert('Note saved!');
            } else if (page_id) {
                await pagesAPI.updatePage(page_id, { content: value });
                alert('Page saved!');
            } else {
                localStorage.setItem('math-notepad-content', value);
                alert('Saved to local storage!');
            }
        } catch (err) {
            console.error('Failed to save content:', err);
            alert('Failed to save content: ' + (err.message || err));
        }
    }

    // --- Initial Load ---
    loadInitialContent();

});
