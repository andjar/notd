// Initialize marked with code highlighting
marked.setOptions({
    highlight: function(code, lang) {
        if (lang && hljs.getLanguage(lang)) {
            return hljs.highlight(code, { language: lang }).value;
        }
        return hljs.highlightAuto(code).value;
    }
});

// State management
let currentPage = null;
let recentPages = [];
let noteTemplates = {};

// Load templates
async function loadTemplates() {
    try {
        const response = await fetch('api/templates.php');
        const templates = await response.json();
        noteTemplates = templates;
    } catch (error) {
        console.error('Error loading templates:', error);
    }
}

// DOM Elements
const searchInput = document.getElementById('search');
const recentPagesList = document.getElementById('recent-pages-list');
const newPageButton = document.getElementById('new-page');
const pageTitle = document.getElementById('page-title');
const pageProperties = document.getElementById('page-properties');
const outlineContainer = document.getElementById('outline-container');

// Helper for navigation
async function navigateToPage(pageId) {
    const targetHash = String(pageId).startsWith('#') ? String(pageId).substring(1) : String(pageId);
    const currentHash = window.location.hash.substring(1);

    if (currentHash === targetHash) {
        // If hash is already the target pageId, force a reload.
        // This is important for initial load if hash is set but page isn't rendered,
        // or if user clicks a link to the currently viewed page.
        await loadPage(targetHash);
    } else {
        // Set the hash, hashchange listener will call loadPage.
        window.location.hash = targetHash;
    }
}


// Event Listeners
searchInput.addEventListener('input', debounce(handleSearch, 300));
newPageButton.addEventListener('click', createNewPage);
outlineContainer.addEventListener('click', handleOutlineClick);

document.getElementById('advanced-search-link').addEventListener('click', (e) => {
    e.preventDefault();
    showAdvancedSearch();
});

document.getElementById('home-button').addEventListener('click', async (e) => {
    e.preventDefault();
    const today = new Date().toISOString().split('T')[0];
    if (document.body.classList.contains('logseq-focus-active')) {
        await zoomOut(); // zoomOut will call loadPage for the original page
    }
    // After zoomOut or if not zoomed, navigate to today's page.
    // navigateToPage will handle if we are already on 'today' or need to change hash.
    navigateToPage(today);
});


window.addEventListener('hashchange', () => {
    const pageId = window.location.hash.substring(1);
    if (pageId === 'search-results') {
        showSearchResults();
    } else if (pageId) { // pageId is from window.location.hash.substring(1)
        loadPage(pageId);
    } else { // Hash is empty
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today);
    }
});

document.addEventListener('DOMContentLoaded', async () => {
    await loadTemplates();
    loadRecentPages();
    initCalendar();
    const initialHash = window.location.hash.substring(1);
    if (!initialHash) {
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today); // Use helper for consistency
    } else {
        if (initialHash === 'search-results') {
            showSearchResults();
        } else {
            navigateToPage(initialHash); // Use helper
        }
    }
});

// Utility Functions
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// API Functions & Page Rendering
async function loadPage(pageId) {
    document.body.classList.remove('logseq-focus-active');
    outlineContainer.classList.remove('focused');
    
    // The page title is now set by renderPage, so no need to handle unzoom button specifically here.
    // If renderPage is called, it will set the standard page title.

    try {
        document.getElementById('new-note').style.display = 'block';
        document.getElementById('backlinks-container').style.display = 'block';
        
        const response = await fetch(`api/page.php?id=${pageId}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const text = await response.text();
        if (!text) throw new Error('Empty response from server');
        const data = JSON.parse(text);
        
        if (data.error) {
            console.error('Server error:', data.error);
            outlineContainer.innerHTML = `<div class="error-message"><h3>Error loading page</h3><p>${data.error}</p></div>`;
            return;
        }

        if (data.properties && data.properties.alias) {
            // Use navigateToPage for alias redirection to ensure hash is updated
            navigateToPage(data.properties.alias);
            return;
        }

        currentPage = data;
        await renderPage(data);
        updateRecentPages(pageId);
        if (window.renderCalendarForCurrentPage) { // Check if calendar is initialized
            window.renderCalendarForCurrentPage(); // Re-render to update selected date
        }
    } catch (error) {
        console.error('Error loading page:', error);
        outlineContainer.innerHTML = `
            <div class="error-message">
                <h3>Error loading page</h3>
                <p>${error.message}</p>
                <button onclick="navigateToPage('${pageId.replace(/'/g, "\\'")}')">Retry</button>
            </div>
        `;
    }
}

async function loadRecentPages() {
    try {
        const response = await fetch('api/recent_pages.php');
        const data = await response.json();
        if (data.error) { console.error(data.error); return; }
        recentPages = data;
        renderRecentPages();
    } catch (error) { console.error('Error loading recent pages:', error); }
}

async function handleSearch(event) {
    const query = event.target.value;
    if (query.length < 2) {
        const searchResultsContainer = document.getElementById('search-results-container');
        if (searchResultsContainer) searchResultsContainer.remove();
        return;
    }
    try {
        const response = await fetch(`api/search.php?q=${encodeURIComponent(query)}`);
        const results = await response.json();
        renderSearchResults(results);
    } catch (error) { console.error('Error searching:', error); }
}

async function createNewPage() {
    const pageEditor = document.createElement('div');
    pageEditor.className = 'page-editor';
    pageEditor.innerHTML = `
        <div class="page-editor-content">
            <input type="text" class="page-id-input" placeholder="Enter page ID (e.g., 2024-03-20 or project-name)">
            <div class="page-editor-actions">
                <button class="btn-primary save-page">Create</button>
                <button class="btn-secondary cancel-page">Cancel</button>
            </div>
        </div>
    `;
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.appendChild(pageEditor);
    document.body.appendChild(modal);
    const pageIdInput = pageEditor.querySelector('.page-id-input');
    const saveButton = pageEditor.querySelector('.save-page');
    const cancelButton = pageEditor.querySelector('.cancel-page');
    pageIdInput.focus();
    saveButton.onclick = async () => {
        const pageId = pageIdInput.value.trim();
        if (!pageId) { alert('Please enter a page ID'); return; }
        let type = 'note'; let properties = {};
        if (/^\d{4}-\d{2}-\d{2}$/.test(pageId)) { type = 'journal'; properties = { 'type': 'journal' }; }
        try {
            const response = await fetch('api/page.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: encodeURIComponent(pageId), title: pageId, type, properties })
            });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            document.body.removeChild(modal);
            navigateToPage(encodeURIComponent(pageId)); // Use navigateToPage
        } catch (error) { console.error('Error creating page:', error); alert('Error creating page: ' + error.message); }
    };
    cancelButton.onclick = () => document.body.removeChild(modal);
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); });
}

async function findBlockById(blockId) {
    try {
        const response = await fetch(`api/block.php?id=${blockId}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        return data;
    } catch (error) { console.error('Error finding block:', error); return null; }
}

async function renderNoteContent(note) {
    let content = note.content;
    
    // 1. Handle TODO/DONE first to preserve their structure before other replacements
    content = content.replace(/^(TODO|DONE)\s*(.*)/gm, (match, status, taskText) => {
        // The <p> tags will be added by marked.parse later if needed.
        // We just want the raw taskText here.
        // This regex assumes TODO/DONE are at the start of a line (or the block).
        // If they can be mid-paragraph, a more complex approach is needed.
        // For now, this handles block-level TODOs well.
        const isDone = status === 'DONE';
        // Temporarily mark this for later processing by a more specific regex
        // This avoids conflicts with general link/tag processing
        return `%%TODO_ITEM%%${status}%%${taskText}%%`; 
    });
    
    // 2. Transclusions {{blockId}} (Keep your existing logic, it's good)
    const blockMatches = content.match(/\{\{([^}]+)\}\}/g) || [];
    for (const blockRefMatch of blockMatches) { // Renamed match to blockRefMatch
        const blockId = blockRefMatch.slice(2, -2);
        const block = await findBlockById(blockId);
        if (block) {
            const blockContent = await renderNoteContent(block); // Recursive call
            content = content.replace(blockRefMatch, `
                <div class="transcluded-block" data-block-id="${blockId}">
                    ${blockContent}
                    <div class="transclusion-source">
                        <a href="#${encodeURIComponent(block.page_id)}" onclick="event.stopPropagation();">
                            Source: ${block.page_title || block.page_id}</a></div></div>`);
        } else { 
            content = content.replace(blockRefMatch, `<span class="block-ref-brackets">{{</span><a href="#" class="block-ref-link" onclick="event.stopPropagation(); console.warn('Block not found: ${blockId}')">${blockId}</a><span class="block-ref-brackets">}}</span><span class="broken-transclusion"> (not found)</span>`);
        }
    }

    // 3. Handle {key::value} properties
    content = content.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
        if (key.trim().toLowerCase() === 'tag') {
            return value.trim().split(',').map(tagValue => 
                // Using #tag format directly for properties, no brackets
                `<a href="#${encodeURIComponent(tagValue.trim())}" class="property-tag" onclick="event.stopPropagation();">#${tagValue.trim()}</a>`
            ).join(' ');
        }
        return `<span class="property-tag">${key.trim()}: ${value.trim()}</span>`;
    });

    // 4. Handle search links <<query>> (Your existing logic is good)
    content = content.replace(/<<([^>]+)>>/g, (match, query) => {
        const displayQuery = query.length > 30 ? query.substring(0, 27) + '...' : query;
        return `<a href="#" class="search-link" onclick="event.preventDefault(); event.stopPropagation(); executeSearchLink('${query.replace(/'/g, "\\'")}')"><<${displayQuery}>></a>`;
    });
    
    // 5. Markdown Parsing (keep this before final link/tag processing if they are generated by markdown)
    content = marked.parse(content); // This will add <p> tags etc.

    // 6. Process specific Logseq-style links/tags that might be *in the markdown source*
    // Page Links: [[Page Name]]
    content = content.replace(/\[\[([^\]#]+?)\]\]/g, (match, pageName) => { // Updated regex to not capture # in page name
        return `<span class="internal-link-brackets">[[</span><a href="#${encodeURIComponent(pageName.trim())}" class="internal-link" onclick="event.stopPropagation();">${pageName.trim()}</a><span class="internal-link-brackets">]]</span>`;
    });
    // Tags: [[#tag name]] or #tag-name (simpler form)
    // Handles [[#tag name]]
    content = content.replace(/\[\[#(.*?)\]\]/g, (match, tagName) => {
        return `<span class="internal-link-brackets">[[</span><a href="#${encodeURIComponent(tagName.trim())}" class="property-tag" onclick="event.stopPropagation();">#${tagName.trim()}</a><span class="internal-link-brackets">]]</span>`;
    });
    // Handles #tag-name (if not already processed by properties or the above)
    // Ensure this doesn't conflict with internal links if they can contain #
    // This regex is simple and might need refinement if your tag names are complex
    content = content.replace(/(^|\s)#([a-zA-Z0-9_\-\/]+)/g, (match, precedingSpace, tagName) => {
        return `${precedingSpace}<a href="#${encodeURIComponent(tagName)}" class="property-tag" onclick="event.stopPropagation();">#${tagName}</a>`;
    });


    // 7. Final processing for TODO_ITEM placeholders
    // This needs to happen *after* marked.parse so that links inside taskText are already HTML
    content = content.replace(/%%TODO_ITEM%%(TODO|DONE)%%(.*?)%%/g, (match, status, taskTextHtml) => {
        const isDone = status === 'DONE';
        // taskTextHtml is already processed by marked.parse and other link replacements above
        return `<div class="todo-item">
                    <label class="todo-checkbox">
                        <input type="checkbox" ${isDone ? 'checked' : ''} 
                            onclick="event.stopPropagation();" 
                            onchange="toggleTodo('${note.block_id || note.id}', this.checked)"> 
                        <span class="todo-marker status-${status.toLowerCase()}" 
                              onclick="event.stopPropagation(); this.previousElementSibling.click();">${status}</span>
                        <span class="todo-text status-${status.toLowerCase()}">${taskTextHtml.trim()}</span>
                    </label>
                </div>`;
    });

    // Image handling (keep your existing)
    content = content.replace(/<img src="([^"]+)" alt="([^"]*)"/g, (match, src, alt) => {
        return `<img src="${src}" alt="${alt}" class="note-image" onclick="event.stopPropagation(); showImageModal(this.src, this.alt)">`;
    });
    
    // Attachments (keep your existing)
    if (note.attachments && note.attachments.length > 0) {
        content += '<div class="attachments">';
        note.attachments.forEach(attachment => {
            const fileExtension = attachment.filename.split('.').pop().toLowerCase();
            let icon = '📄'; let previewHtml = '';
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) {
                icon = '🖼️'; previewHtml = `<img src="uploads/${attachment.filename}" class="attachment-image" alt="${attachment.original_name}">`;
            } else if (['mp4', 'webm', 'mov'].includes(fileExtension)) {
                icon = '🎥'; previewHtml = `<div class="attachment-preview"><video src="uploads/${attachment.filename}" controls></video></div>`;
            } else if (['mp3', 'wav', 'ogg'].includes(fileExtension)) {
                icon = '🎵'; previewHtml = `<div class="attachment-preview"><audio src="uploads/${attachment.filename}" controls></audio></div>`;
            } 
            content += `<div class="attachment"><div class="attachment-info">
                        <span class="attachment-icon">${icon}</span>
                        <a href="uploads/${attachment.filename}" target="_blank" class="attachment-name" onclick="event.stopPropagation();">${attachment.original_name}</a>
                        <span class="attachment-size">${formatFileSize(attachment.size)}</span>
                        <div class="attachment-actions"><button onclick="event.stopPropagation(); deleteAttachment(${attachment.id}, event)" title="Delete attachment">×</button></div>
                    </div>${previewHtml}</div>`;
        });
        content += '</div>';
    }
    return content;
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024; const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

async function renderOutline(notes, level = 0) {
    if (!notes || notes.length === 0) return ''; // Return empty string if no notes

    let html = '';
    for (const note of notes) {
        const blockId = note.block_id;
        const contentHtml = await renderNoteContent(note);
        let linkedPageType = null;
        // ... (your existing logic for linkedPageType) ...

        const hasChildren = note.children && note.children.length > 0;
        const hasChildrenClass = hasChildren ? 'has-children' : '';
        const linkedPageTypeClass = linkedPageType ? `linked-page-${linkedPageType.toLowerCase()}` : '';

        const controlsHtml = `
            <span class="static-bullet" data-action="zoom-in" title="Zoom in"></span>
            ${hasChildren ?
                `<span class="hover-arrow-toggle" data-action="toggle-children" title="Toggle children">
                     <svg class="arrow-svg" viewBox="0 0 192 512"><path d="M0 384.662V127.338c0-17.818 21.543-26.741 34.142-14.142l128.662 128.662c7.81 7.81 7.81 20.474 0 28.284L34.142 398.804C21.543 411.404 0 402.48 0 384.662z"></path></svg>
                 </span>` : '' }`;

        // Start of the .outline-item
        html += `
            <div class="outline-item ${linkedPageTypeClass} ${hasChildrenClass}"
                 data-note-id="${note.id}" data-level="${level}" data-content="${note.content.replace(/"/g, '"')}">
                <div class="outline-content" ${blockId ? `data-block-id="${blockId}"` : ''}>
                    ${controlsHtml}
                    ${contentHtml}
                    ${note.properties && Object.keys(note.properties).length > 0 ? renderPropertiesInline(note.properties) : ''}
                    <div class="note-actions">
                        <button data-action="add-child" title="Add child note">+</button>
                        ${blockId ? `<button data-action="copy-block-id" title="Copy block ID">#</button>` : ''}
                        <button data-action="edit" title="Edit note">✎</button>
                        <button data-action="upload" title="Upload file">↑</button>
                        <button data-action="delete" title="Delete note">×</button>
                         | <span class="note-date" title="Created: ${new Date(note.created_at).toLocaleString()}">${new Date(note.created_at).toLocaleDateString()}</span>
                    </div>
                </div>`; // End of .outline-content

        // Recursively render children and wrap them in .outline-children
        if (hasChildren) {
            html += `<div class="outline-children">`;
            html += await renderOutline(note.children, level + 1); // Recursive call
            html += `</div>`; // End of .outline-children
        }

        html += `</div>`; // End of .outline-item
    }
    return html;
}

function renderPropertiesInline(properties) { return ''; } 

async function renderPage(page) { 
    pageTitle.innerHTML = `<span class="page-title-text">${page.title}</span>
                           <button class="edit-properties-button" title="Edit page properties"></button>`;
    if (page.properties && Object.keys(page.properties).length > 0) {
        const propertiesHtml = Object.entries(page.properties)
            .map(([key, value]) => `<div class="page-property"><span class="property-key">${key}:</span><span class="property-value">${value}</span></div>`).join('');
        pageProperties.innerHTML = `<div class="page-properties-content">${propertiesHtml}</div>`;
        pageProperties.style.display = 'block';
    } else {
        pageProperties.innerHTML = '';
        pageProperties.style.display = 'none';
    }
    const editButton = pageTitle.querySelector('.edit-properties-button');
    if (editButton) editButton.onclick = editPageProperties;
    outlineContainer.innerHTML = await renderOutline(page.notes);
    initSortable(outlineContainer); // Initialize SortableJS
    const backlinksContainer = document.getElementById('backlinks-container');
    if (backlinksContainer) renderBacklinks(page.id).then(html => { backlinksContainer.innerHTML = html; });
}

async function editPageProperties() {
    if (!currentPage) return;
    const properties = currentPage.properties || {};
    const propertyList = Object.entries(properties).map(([key, value]) => `${key}::${value}`).join('\n');
    pageProperties.style.display = 'block';
    const editorHtml = `<div class="properties-editor">
            <textarea class="properties-textarea" placeholder="Enter properties (one per line, format: key::value)">${propertyList}</textarea>
            <div class="properties-editor-actions">
                <button class="btn-primary save-properties">Save</button>
                <button class="btn-secondary cancel-properties">Cancel</button>
            </div></div>`;
    let propertiesContent = pageProperties.querySelector('.page-properties-content');
    const originalContent = propertiesContent ? propertiesContent.innerHTML : '';
    if (!propertiesContent) {
        propertiesContent = document.createElement('div');
        propertiesContent.className = 'page-properties-content';
        pageProperties.appendChild(propertiesContent);
    }
    propertiesContent.innerHTML = editorHtml;
    const textarea = propertiesContent.querySelector('.properties-textarea');
    const saveButton = propertiesContent.querySelector('.save-properties');
    const cancelButton = propertiesContent.querySelector('.cancel-properties');
    textarea.focus();
    textarea.addEventListener('keydown', (e) => { if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); saveButton.click(); } });
    saveButton.onclick = async () => {
        const newPropertiesText = textarea.value.trim();
        const updatedProperties = {};
        if (newPropertiesText) {
            newPropertiesText.split('\n').forEach(line => {
                const parts = line.split('::');
                if (parts.length === 2) {
                    const key = parts[0].trim(); const value = parts[1].trim();
                    if (key && value) updatedProperties[key] = value;
                }
            });
        }
        try {
            const response = await fetch(`api/page.php?id=${currentPage.id}`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', title: currentPage.title, type: currentPage.type || 'note', properties: updatedProperties })
            });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            navigateToPage(currentPage.id); // Use navigateToPage to reload
        } catch (error) {
            console.error('Error updating properties:', error);
            propertiesContent.innerHTML = originalContent;
            if ((Object.keys(properties).length === 0 && !originalContent.includes('page-property')) || !propertiesContent.innerHTML.trim()) {
                 pageProperties.style.display = 'none';
            }
        }
    };
    cancelButton.onclick = () => {
        propertiesContent.innerHTML = originalContent;
        if ((Object.keys(properties).length === 0 && !originalContent.includes('page-property')) || !propertiesContent.innerHTML.trim()) {
             pageProperties.style.display = 'none';
        }
    };
}

async function renderBacklinks(pageId) {
    try {
        const response = await fetch(`api/backlinks.php?page_id=${pageId}`);
        if (!response.ok) throw new Error(`Network response error: ${response.statusText}`);
        const threads = await response.json();
        if (threads.error) throw new Error(threads.error);
        if (!threads || threads.length === 0) return '<h3>Backlinks</h3><p>No backlinks found</p>';
        let html = '<h3>Backlinks</h3><div class="backlinks-list">';
        for (const thread of threads) {
            const buildNoteTree = (notesList) => {
                if (!notesList || notesList.length === 0) return [];
                const noteMap = {};
                notesList.forEach(note => { noteMap[note.id] = { ...note, children: [] }; });
                const rootNotes = [];
                notesList.forEach(noteData => {
                    const currentMappedNote = noteMap[noteData.id];
                    if (noteData.parent_id && noteMap[noteData.parent_id]) {
                        noteMap[noteData.parent_id].children.push(currentMappedNote);
                    } else { rootNotes.push(currentMappedNote); }
                });
                return rootNotes;
            };
            const hierarchicalNotes = buildNoteTree(thread.notes);
            const threadContentHtml = hierarchicalNotes.length > 0 ? await renderOutline(hierarchicalNotes, 0) : '';
            html += `<div class="backlink-thread-item">
                        <a href="#${encodeURIComponent(thread.linking_page_id)}" onclick="event.stopPropagation();">
                            ${thread.linking_page_title || thread.linking_page_id}</a>
                        <div class="backlink-thread-content">${threadContentHtml}</div>
                    </div>`;
        }
        html += '</div>'; return html;
    } catch (error) {
        console.error('Error loading backlinks:', error);
        return `<h3>Backlinks</h3><p>Error loading backlinks: ${error.message}</p>`;
    }
}

function renderRecentPages() {
    if (!recentPagesList) return;
    const recentPagesHtml = recentPages
        .map(page => `<li onclick="navigateToPage('${page.page_id.replace(/'/g, "\\'")}')">
                        ${page.title || decodeURIComponent(page.page_id)}
                        <small>${new Date(page.last_opened).toLocaleDateString()}</small></li>`).join('');
    recentPagesList.innerHTML = `<div class="recent-pages-header">
            <h3>Recent Pages</h3>
            <a href="#" class="all-pages-link" onclick="event.preventDefault(); showAllPages();">All pages</a>
        </div><ul>${recentPagesHtml}</ul>`;
}

async function showAllPages() {
    try {
        const response = await fetch('api/all_pages.php');
        const pages = await response.json();
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `<div class="page-list-modal"><h3>All Pages</h3><div class="page-list">
            ${pages.map(page => `<div class="page-list-item" onclick="navigateToPage('${page.id.replace(/'/g, "\\'")}'); document.body.removeChild(this.closest('.modal'));">
                                ${page.title || decodeURIComponent(page.id)}</div>`).join('')}
            </div><button class="btn-secondary" style="margin-top:15px;" onclick="document.body.removeChild(this.closest('.modal'));">Close</button></div>`;
        modal.onclick = (e) => { if (e.target === modal) document.body.removeChild(modal); };
        document.body.appendChild(modal);
    } catch (error) { console.error('Error loading all pages:', error); }
}

function renderSearchResults(results) { // For inline search
    const searchResultsContainer = document.getElementById('search-results-container') || document.createElement('div');
    searchResultsContainer.id = 'search-results-container';
    searchResultsContainer.className = 'search-results-inline';
    if (results.length === 0) {
        searchResultsContainer.innerHTML = '<p>No results found.</p>';
    } else {
        searchResultsContainer.innerHTML = results.map(result => `
            <div class="search-result-inline-item" onclick="navigateToPage('${result.page_id}'); searchInput.value=''; this.parentElement.remove();">
                <strong>${result.page_title || result.page_id}</strong>: ${result.content.substring(0, 100)}...</div>`).join('');
    }
    if (!document.getElementById('search-results-container')) {
        searchInput.parentNode.insertBefore(searchResultsContainer, searchInput.nextSibling);
    }
     document.addEventListener('click', function clearInlineSearch(event) {
        if (searchResultsContainer.contains(event.target)) return; 
        if (!searchInput.contains(event.target) && !searchResultsContainer.contains(event.target)) {
            searchResultsContainer.remove();
            document.removeEventListener('click', clearInlineSearch);
        }
    }, { capture: true });
}

function updateRecentPages(pageId) {
    fetch('api/recent_pages.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ page_id: pageId })
    }).then(() => loadRecentPages());
}

async function uploadFile(noteId, file) {
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file); formData.append('note_id', noteId);
    try {
        const response = await fetch('api/attachment.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        navigateToPage(currentPage.id); // Use navigateToPage
    } catch (error) { console.error('Error uploading file:', error); alert('Error uploading file: ' + error.message); }
}

async function deleteAttachment(id, event) {
    event.stopPropagation();
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `<div class="delete-confirmation-modal"><h3>Delete Attachment</h3>
        <p>Are you sure you want to delete this attachment?</p>
        <div class="button-group"><button class="btn-secondary cancel-delete">Cancel</button><button class="btn-primary confirm-delete">Delete</button></div></div>`;
    document.body.appendChild(modal);
    modal.querySelector('.cancel-delete').onclick = () => document.body.removeChild(modal);
    modal.querySelector('.confirm-delete').onclick = async () => {
        try {
            const response = await fetch(`api/attachment.php`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: id })
            });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            document.body.removeChild(modal); 
            navigateToPage(currentPage.id); // Use navigateToPage
        } catch (error) { 
            console.error('Error deleting attachment:', error); 
            alert('Error deleting attachment: ' + error.message); 
            document.body.removeChild(modal); 
        }
    };
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); });
}

function initCalendar() {
    const calendar = document.getElementById('calendar');
    if (!calendar) return;
    const todayDate = new Date(); // Store today's actual date

    // Keep track of current month/year for rendering
    let currentDisplayMonth = todayDate.getMonth();
    let currentDisplayYear = todayDate.getFullYear();

    function renderCalendar() {
        const firstDay = new Date(currentDisplayYear, currentDisplayMonth, 1);
        const lastDay = new Date(currentDisplayYear, currentDisplayMonth + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDay = (firstDay.getDay() === 0) ? 6 : firstDay.getDay() - 1;

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        let html = `<div class="calendar-header"><button class="calendar-nav" onclick="prevMonth()">←</button>
                    <span><b>${monthNames[currentDisplayMonth]} ${currentDisplayYear}</b></span>
                    <button class="calendar-nav" onclick="nextMonth()">→</button></div>
                    <div class="calendar-grid">${dayNames.map(d => `<div class="calendar-weekday">${d}</div>`).join('')}`;
        
        for (let i = 0; i < startingDay; i++) html += '<div class="calendar-day empty"></div>';
        
        const todayString = todayDate.toISOString().split('T')[0];
        const currentPageId = currentPage ? currentPage.id : null;

        for (let day = 1; day <= daysInMonth; day++) {
            const date = `${currentDisplayYear}-${String(currentDisplayMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            let dayClasses = 'calendar-day';
            if (date === todayString) {
                dayClasses += ' today'; // Today's date
            }
            if (date === currentPageId) { // Currently selected/viewed page
                dayClasses += ' selected-date'; 
            }
            html += `<div class="${dayClasses}" onclick="window.location.hash = '${date}'" data-date="${date}">${day}</div>`;
        }
        
        const totalCells = startingDay + daysInMonth;
        const remainingCells = (totalCells % 7 === 0) ? 0 : 7 - (totalCells % 7);
        for (let i = 0; i < remainingCells; i++) html += '<div class="calendar-day empty"></div>';
        
        html += '</div>'; 
        calendar.innerHTML = html;
    }

    // Expose renderCalendar so it can be called after a page loads
    window.renderCalendarForCurrentPage = renderCalendar; 

    window.prevMonth = () => { 
        currentDisplayMonth = (currentDisplayMonth === 0) ? 11 : currentDisplayMonth - 1; 
        if (currentDisplayMonth === 11) currentDisplayYear--; 
        renderCalendar(); 
    };
    window.nextMonth = () => { 
        currentDisplayMonth = (currentDisplayMonth === 11) ? 0 : currentDisplayMonth + 1; 
        if (currentDisplayMonth === 0) currentDisplayYear++; 
        renderCalendar(); 
    };
    renderCalendar(); // Initial render
}

function showImageModal(src, alt) {
    const modal = document.createElement('div');
    modal.className = 'modal image-modal';
    modal.innerHTML = `<div class="image-modal-content"><img src="${src}" alt="${alt || 'Pasted Image'}">
                       <button class="close-modal">×</button></div>`;
    modal.onclick = (e) => { if (e.target === modal || e.target.classList.contains('close-modal')) document.body.removeChild(modal); };
    document.body.appendChild(modal);
}

// Outline Interaction
function handleOutlineClick(event) {
    const target = event.target;
    const breadcrumbItem = target.closest('.breadcrumb-item');
    const breadcrumbBar = target.closest('.breadcrumb-bar');
    const noteElement = target.closest('.outline-item');

    let action = target.dataset.action || target.closest('[data-action]')?.dataset.action;
    let noteIdForAction = null;

    if (noteElement) {
        noteIdForAction = noteElement.dataset.noteId;
    }

    // Prioritize breadcrumb actions if a breadcrumb item was clicked
    if (breadcrumbItem && breadcrumbItem.dataset.action) {
        action = breadcrumbItem.dataset.action;
        // If the action is zoom-in (from an intermediate breadcrumb), get its note ID
        if (action === 'zoom-in') {
            noteIdForAction = breadcrumbItem.dataset.noteId;
        }
        // If it's page-link with zoom-out, action is already set to 'zoom-out'
    } else if (breadcrumbBar && !breadcrumbItem && breadcrumbBar.dataset.action === 'zoom-out') {
        // Clicked on the bar itself (not an item), treat as full zoom-out
        action = 'zoom-out';
    }


    if (action === 'zoom-out') {
        // This handles clicks on the main breadcrumb bar or the page title link in breadcrumbs
        zoomOut();
        return;
    }

    if (action === 'zoom-in') {
        // This handles clicks on outline bullets OR intermediate breadcrumb items
        const targetId = noteIdForAction; // Already correctly set from breadcrumb or noteElement
        if (targetId) {
            zoomInOnNote(targetId); // Pass the ID directly
        } else {
            console.warn("Zoom-in clicked, but no target ID found.");
        }
        return;
    }
    
    // For all other actions, a noteElement in the outline and its ID are required
    if (!noteElement || !noteIdForAction) {
        // console.log("Action requires a note element and ID, but not found.", action, target);
        return;
    }

    // Existing switch cases for other actions:
    switch (action) {
        case 'toggle-children':
            if (noteElement.classList.contains('has-children')) toggleChildren(noteElement);
            break;
        case 'copy-block-id':
            const blockId = noteElement.querySelector('.outline-content[data-block-id]')?.dataset.blockId;
            if (blockId) {
                navigator.clipboard.writeText(`{{${blockId}}}`).then(() => {
                    const button = target.closest('button');
                    if (button) { button.textContent = 'Copied!'; setTimeout(() => { button.textContent = '#'; }, 1000); }
                }).catch(err => console.error('Failed to copy block ID:', err));
            }
            break;
        case 'add-child': createNote(noteIdForAction, parseInt(noteElement.dataset.level) + 1); break;
        case 'edit': editNote(noteIdForAction, noteElement.dataset.content); break;
        case 'upload':
            const fileInput = document.createElement('input'); fileInput.type = 'file'; fileInput.style.display = 'none';
            document.body.appendChild(fileInput);
            fileInput.onchange = (e) => { if (e.target.files.length > 0) uploadFile(noteIdForAction, e.target.files[0]); document.body.removeChild(fileInput); };
            fileInput.click();
            break;
        case 'delete': deleteNote(noteIdForAction); break;
        default:
            // console.log("Unknown action or unhandled click:", action, target);
            break;
    }
}

function toggleChildren(noteElement) {
    noteElement.classList.toggle('children-hidden');
    const childrenContainer = noteElement.querySelector('.outline-children');
    if (childrenContainer) {
        if (noteElement.classList.contains('children-hidden')) {
            childrenContainer.style.display = 'none'; // Or add a class
        } else {
            childrenContainer.style.display = ''; // Or remove a class
        }
    }
}

function createNote(parentId = null, level = 0) {
    if (!currentPage) return;
    const noteEditorContainer = document.createElement('div');
    if (parentId) noteEditorContainer.style.paddingLeft = `calc(var(--indentation-unit) * ${level > 0 ? 1 : 0})`;
    noteEditorContainer.className = 'note-editor-wrapper';
    const noteElement = document.createElement('div');
    noteElement.className = 'note-editor';
    let templateOptions = '<option value="">No Template</option>';
    if (noteTemplates && Object.keys(noteTemplates).length > 0) {
        templateOptions += Object.entries(noteTemplates).map(([key, _]) => `<option value="${key}">${key.charAt(0).toUpperCase() + key.slice(1)}</option>`).join('');
    }
    noteElement.innerHTML = `<textarea class="note-textarea" placeholder="Enter note content... (Ctrl+Enter to save)"></textarea>
        <div class="note-editor-actions">
            <button class="btn-primary save-note">Save</button>
            <button class="btn-secondary cancel-note">Cancel</button>
            ${Object.keys(noteTemplates).length > 0 ? `<div class="template-selector"><select>${templateOptions}</select></div>` : ''}
        </div>`;
    noteEditorContainer.appendChild(noteElement);
    const textarea = noteElement.querySelector('.note-textarea');
    const saveButton = noteElement.querySelector('.save-note');
    const cancelButton = noteElement.querySelector('.cancel-note');
    const templateSelect = noteElement.querySelector('select');

    if (parentId) {
        const parentNoteElement = document.querySelector(`.outline-item[data-note-id="${parentId}"]`);
        if (parentNoteElement) {
            let childrenContainer = parentNoteElement.querySelector('.outline-children');
            if (!childrenContainer) {
                childrenContainer = document.createElement('div');
                childrenContainer.className = 'outline-children';
                // Apply padding-left via CSS, or set it here if dynamic:
                // childrenContainer.style.paddingLeft = `var(--indentation-unit)`;
                parentNoteElement.appendChild(childrenContainer);
            }
            childrenContainer.appendChild(noteEditorContainer); // Append editor to children container
    
            // Update parent's state
            parentNoteElement.classList.add('has-children');
            if (parentNoteElement.classList.contains('children-hidden')) {
                parentNoteElement.classList.remove('children-hidden');
                childrenContainer.style.display = ''; // Ensure visible
            }
        } else { /* ... handle error or append to root ... */ }
    } else {
        outlineContainer.appendChild(noteEditorContainer);
    }
    textarea.focus();
    textarea.addEventListener('keydown', (e) => { if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); saveButton.click(); } });
    if (templateSelect) {
        templateSelect.addEventListener('change', (e) => {
            const templateKey = e.target.value;
            if (templateKey && noteTemplates[templateKey]) {
                textarea.value = textarea.value ? textarea.value + '\n' + noteTemplates[templateKey] : noteTemplates[templateKey];
                textarea.focus(); textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
            }
        });
    }
    saveButton.onclick = async () => {
        const content = textarea.value.trim();
        const properties = {};
        const propertyRegex = /\{([^:]+)::([^}]+)\}/g; let match;
        let tempContent = content;
        while ((match = propertyRegex.exec(tempContent)) !== null) properties[match[1].trim()] = match[2].trim();
        try {
            const response = await fetch('api/note.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ page_id: currentPage.id, content: content, level: level, parent_id: parentId, properties: properties })
            });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            navigateToPage(currentPage.id); // Use navigateToPage
        } catch (error) { console.error('Error creating note:', error); alert('Error creating note: ' + error.message); }
    };
    cancelButton.onclick = () => {
        noteEditorContainer.remove();
        if (parentId) {
            const parentNoteEl = document.querySelector(`.outline-item[data-note-id="${parentId}"]`);
            if (parentNoteEl) {
                let hasOtherChildren = false; let sibling = parentNoteEl.nextElementSibling;
                while(sibling && sibling.classList.contains('outline-item') && parseInt(sibling.dataset.level) > parseInt(parentNoteEl.dataset.level)) { hasOtherChildren = true; break; }
                if (!hasOtherChildren) parentNoteEl.classList.remove('has-children');
            }
        }
    };
}

function editNote(id, currentContentText) {
    const noteElement = document.querySelector(`.outline-item[data-note-id="${id}"]`);
    if (!noteElement) return;
    const contentElement = noteElement.querySelector('.outline-content');
    if (!contentElement) return;
    const originalDisplay = contentElement.style.display;
    contentElement.style.display = 'none';
    const editorWrapper = document.createElement('div');
    editorWrapper.className = 'note-editor-wrapper edit-mode';
    const noteEditorDiv = document.createElement('div');
    noteEditorDiv.className = 'note-editor';
    noteEditorDiv.innerHTML = `<textarea class="note-textarea">${currentContentText}</textarea>
        <div class="note-editor-actions"><button class="btn-primary save-note">Save</button><button class="btn-secondary cancel-note">Cancel</button></div>`;
    editorWrapper.appendChild(noteEditorDiv);
    noteElement.insertBefore(editorWrapper, contentElement);
    const textarea = noteEditorDiv.querySelector('.note-textarea');
    const saveButton = noteEditorDiv.querySelector('.save-note');
    const cancelButton = noteEditorDiv.querySelector('.cancel-note');
    textarea.focus(); textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
    textarea.addEventListener('keydown', (e) => { if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); saveButton.click(); } });
    saveButton.onclick = async () => {
        const newContent = textarea.value.trim();
        const properties = {}; const propertyRegex = /\{([^:]+)::([^}]+)\}/g; let match; let tempContent = newContent;
        while ((match = propertyRegex.exec(tempContent)) !== null) properties[match[1].trim()] = match[2].trim();
        try {
            const response = await fetch(`api/note.php?id=${id}`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', content: newContent, properties: properties, level: parseInt(noteElement.dataset.level) || 0 })
            });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            navigateToPage(currentPage.id); // Use navigateToPage
        } catch (error) { console.error('Error updating note:', error); alert('Error updating note: ' + error.message); }
    };
    cancelButton.onclick = () => { editorWrapper.remove(); contentElement.style.display = originalDisplay; };
}

async function deleteNote(id) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `<div class="delete-confirmation-modal"><h3>Delete Note</h3>
        <p>Are you sure you want to delete this note and all its children?</p>
        <div class="button-group"><button class="btn-secondary cancel-delete">Cancel</button><button class="btn-primary confirm-delete">Delete</button></div></div>`;
    document.body.appendChild(modal);
    modal.querySelector('.cancel-delete').onclick = () => document.body.removeChild(modal);
    modal.querySelector('.confirm-delete').onclick = async () => {
        try {
            const response = await fetch(`api/note.php?id=${id}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete' }) });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            document.body.removeChild(modal); 
            navigateToPage(currentPage.id); // Use navigateToPage
        } catch (error) { console.error('Error deleting note:', error); alert('Error deleting note: ' + error.message); document.body.removeChild(modal); }
    };
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); });
}

async function showSearchResults() { // For Advanced Search results page
    const results = JSON.parse(sessionStorage.getItem('searchResults') || '[]');
    const query = sessionStorage.getItem('searchQuery') || '';
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';
    pageProperties.style.display = 'none';
    const renderedResultsPromises = results.map(async result => {
        const content = await renderNoteContent(result);
        return `<div class="search-result-item"><div class="result-header">
                    <a href="#${encodeURIComponent(result.page_id)}" onclick="event.stopPropagation();">${result.page_title || result.page_id}</a>
                    <span class="result-date">${new Date(result.created_at).toLocaleDateString()}</span></div>
                <div class="result-content">${content}</div></div>`;
    });
    const renderedResultsHtml = (await Promise.all(renderedResultsPromises)).join('');
    outlineContainer.innerHTML = `<div class="search-results-page"><div class="search-results-header">
            <h2>Advanced Search Results</h2><div class="search-actions"><button class="btn-secondary" onclick="copySearchLink()">Copy Search Link</button>
            <button class="btn-secondary" onclick="window.history.back()">Back</button></div></div>
        <div class="search-query" style="margin-bottom:1em; font-size:0.9em;"><strong>Query:</strong> <code>${query}</code></div>
        ${renderedResultsHtml}</div>`;
    pageTitle.innerHTML = '<span class="page-title-text">Search Results</span>';
}
function copySearchLink() {
    const query = sessionStorage.getItem('searchQuery') || '';
    navigator.clipboard.writeText(`<<${query}>>`).then(() => alert('Search link copied!')).catch(err => alert('Failed to copy: ' + err));
}
async function executeSearchLink(query) { // Renamed from executeSearchLink
    try {
        const response = await fetch('api/advanced_search.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ query }) });
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const results = await response.json();
        if (results.error) throw new Error(results.error);
        sessionStorage.setItem('searchResults', JSON.stringify(results));
        sessionStorage.setItem('searchQuery', query);
        window.location.hash = 'search-results'; // This will trigger showSearchResults via hashchange
    } catch (error) { console.error('Error executing search link:', error); alert('Error executing search: ' + error.message); }
}

async function toggleTodo(blockId, isDone) {
    try {
        const currentNote = await findBlockById(blockId);
        if (!currentNote) throw new Error('Note not found for toggling TODO');
        
        let rawContent = currentNote.content;
        let taskTextWithProperties = "";
        let currentStatus = "";

        if (rawContent.startsWith('TODO ')) {
            taskTextWithProperties = rawContent.substring(5);
            currentStatus = "TODO";
        } else if (rawContent.startsWith('DONE ')) {
            taskTextWithProperties = rawContent.substring(5);
            currentStatus = "DONE";
        } else {
            // If no prefix, assume it's an undifferentiated task, treat as TODO for toggling
            taskTextWithProperties = rawContent;
            currentStatus = "TODO"; // Or handle as error if prefix is mandatory
        }
        
        const taskSpecificProperties = {};
        // Extract properties from the task text part
        let cleanTaskDescription = taskTextWithProperties.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
            taskSpecificProperties[key.trim()] = value.trim();
            return ''; 
        }).trim();

        let newStatusPrefix = isDone ? 'DONE ' : 'TODO ';
        let newContentString = newStatusPrefix + cleanTaskDescription; // Start with clean description

        const updatedNoteProperties = { ...(currentNote.properties || {}) }; 
        
        if (isDone) {
            taskSpecificProperties['done-at'] = new Date().toISOString();
        } else {
            delete taskSpecificProperties['done-at']; 
        }
        
        // Re-append all task-specific properties (including the new/removed done-at)
        for (const [key, value] of Object.entries(taskSpecificProperties)) {
            newContentString += ` {${key}::${value}}`;
            updatedNoteProperties[key] = value; // Also update the main note properties
        }
        if (!isDone) {
            delete updatedNoteProperties['done-at']; // Ensure it's removed from main properties too
        }
        
        const response = await fetch(`api/note.php?id=${currentNote.id}`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'update', 
                content: newContentString.trim(), 
                properties: updatedNoteProperties, 
                level: currentNote.level, 
                parent_id: currentNote.parent_id 
            })
        });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        navigateToPage(currentPage.id); 
    } catch (error) {
        console.error('Error updating todo status:', error); 
        alert('Error updating task: ' + error.message);
        // Revert checkbox state on error
        const checkbox = document.querySelector(`input[type="checkbox"][onchange*="'${blockId}'"]`);
        if (checkbox) checkbox.checked = !isDone; 
    }
}

function showAdvancedSearch() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="advanced-search-content">
            <h3>Advanced Search</h3>
            <div class="help-text">
                Enter a SQL query to search based on block properties. Example:<br>
                <code>SELECT * FROM notes WHERE id IN (SELECT note_id FROM properties WHERE property_key = 'status' AND property_value = 'done')</code>
            </div>
            <textarea id="advanced-search-query" placeholder="Enter your SQL query..."></textarea>
            <div class="button-group">
                <button class="btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                <button class="btn-primary" onclick="executeAdvancedSearch()">Search</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    // Focus the textarea
    const textarea = modal.querySelector('#advanced-search-query');
    if (textarea) {
        textarea.focus();
    }

    // Close modal when clicking outside
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

async function executeAdvancedSearch() {
    const queryInput = document.getElementById('advanced-search-query');
    if (!queryInput) {
        console.error('Advanced search query input not found.');
        return;
    }
    const query = queryInput.value.trim();
    
    if (!query) {
        // Optionally, provide feedback to the user that a query is required
        // For now, just return if query is empty, matching original behavior
        return;
    }

    try {
        const response = await fetch('api/advanced_search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query })
        });

        if (!response.ok) {
            // Attempt to get error message from response body
            let errorMsg = `HTTP error! status: ${response.status}`;
            try {
                const errorData = await response.json();
                if (errorData && errorData.error) {
                    errorMsg = errorData.error;
                }
            } catch (e) {
                // Ignore if response is not JSON or doesn't have error field
            }
            throw new Error(errorMsg);
        }

        const results = await response.json();
        if (results.error) {
            throw new Error(results.error);
        }

        sessionStorage.setItem('searchResults', JSON.stringify(results));
        sessionStorage.setItem('searchQuery', query);

        const modal = document.querySelector('.advanced-search-content');
        if (modal && modal.closest('.modal')) {
            modal.closest('.modal').remove();
        }

        window.location.hash = 'search-results';
    } catch (error) {
        console.error('Error executing advanced search:', error);
        alert('Error executing search: ' + error.message);
    }
}

// --- Logseq-style Zoom (Focus) Functions ---
function findNoteAndPath(targetId, notesArray, currentPath = []) {
    for (const note of notesArray) {
        const newPath = [...currentPath, note];
        if (String(note.id) === String(targetId)) {
            return { note, path: newPath };
        }
        if (note.children) {
            const foundInChildren = findNoteAndPath(targetId, note.children, newPath);
            if (foundInChildren) {
                return foundInChildren;
            }
        }
    }
    return null;
}

function adjustLevels(notesArray, currentLevel) {
    if (!notesArray) return;
    notesArray.forEach(note => {
        note.level = currentLevel;
        if (note.children) adjustLevels(note.children, currentLevel + 1);
    });
}

function renderBreadcrumbs(path) {
    if (!path || path.length === 0 || !currentPage) return '';

    let breadcrumbHtml = '<div class="breadcrumb-bar" data-action="zoom-out" title="Click to zoom out">';
    
    // Page Title as the root of the breadcrumb
    breadcrumbHtml += `<span class="breadcrumb-item page-link" data-action="zoom-out">${currentPage.title}</span>`;

    path.forEach((note, index) => {
        // Simple text extraction for snippet - replace HTML tags
        let plainContent = note.content.replace(/<\/?[^>]+(>|$)/g, " ").replace(/\s+/g, ' ').trim();
        const contentSnippet = plainContent.length > 30 
            ? plainContent.substring(0, 27) + '...' 
            : (plainContent || 'Untitled Note');
            
        const isLast = index === path.length - 1;
        breadcrumbHtml += `<span class="breadcrumb-separator">»</span>`;
        // Only the last item (current focus) is not clickable for zoom-in again via breadcrumb
        if (isLast) {
            breadcrumbHtml += `<span class="breadcrumb-item current-focus">${contentSnippet}</span>`;
        } else {
            // Make intermediate breadcrumb items zoomable to that specific level
            breadcrumbHtml += `<span class="breadcrumb-item" data-action="zoom-in" data-note-id="${note.id}">${contentSnippet}</span>`;
        }
    });

    breadcrumbHtml += '</div>';
    return breadcrumbHtml;
}


async function zoomInOnNote(targetNoteReference) {
    let noteIdToZoom;

    // Determine the note ID from the reference (could be an element or an ID string/number)
    if (typeof targetNoteReference === 'string' || typeof targetNoteReference === 'number') {
        noteIdToZoom = String(targetNoteReference);
    } else if (targetNoteReference && targetNoteReference.dataset && targetNoteReference.dataset.noteId) {
        noteIdToZoom = targetNoteReference.dataset.noteId;
    } else {
        console.error("Invalid target for zoomInOnNote:", targetNoteReference);
        return;
    }

    if (!currentPage || !currentPage.notes) {
        console.error("Cannot zoom, currentPage data is missing. Attempting to recover.");
        // Fallback: if current page data is lost, zoom out completely.
        await zoomOut();
        return;
    }

    // Always find the note and its path from the original, complete currentPage.notes structure
    const noteDataWithPath = findNoteAndPath(noteIdToZoom, currentPage.notes);

    if (!noteDataWithPath || !noteDataWithPath.note) {
        console.error("Note to zoom (ID: " + noteIdToZoom + ") not found in current page data. This might indicate stale data or an invalid ID. Zooming out.");
        await zoomOut(); // Fallback if the target note for zoom isn't in the master list
        return;
    }

    const { note: noteInFullTree, path } = noteDataWithPath;
    window.breadcrumbPath = path; // Store the new path for breadcrumb rendering

    document.body.classList.add('logseq-focus-active');
    outlineContainer.classList.add('focused');

    // Clone the focused note subtree from the original full tree
    // This ensures modifications (like level adjustments) don't affect currentPage.notes
    const clonedFocusedNote = JSON.parse(JSON.stringify(noteInFullTree));
    clonedFocusedNote.level = 0; // The new zoomed item is always level 0 in its view
    if (clonedFocusedNote.children) {
        adjustLevels(clonedFocusedNote.children, 1); // Adjust children levels relative to this new root
    }
    const focusedNotesArray = [clonedFocusedNote]; // The outline will be rendered from this array

    // Hide page-level elements that don't make sense in a focused view
    pageProperties.style.display = 'none';
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';

    const breadcrumbsHtml = renderBreadcrumbs(path); // Render breadcrumbs for the current zoom path
    // Render the outline starting from level 0 for the focused view
    outlineContainer.innerHTML = breadcrumbsHtml + (await renderOutline(focusedNotesArray, 0));
    initSortable(outlineContainer); // Initialize SortableJS for focused view

    // Scroll the newly focused item into view
    const focusedDomNote = outlineContainer.querySelector('.outline-item[data-level="0"]');
    if (focusedDomNote) {
        // Using a slight delay can help if the browser needs a moment to paint before scrolling
        setTimeout(() => {
             focusedDomNote.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 0);
    }
}

function initSortable(containerElement) {
    const sortableOptions = {
        group: 'nested',
        animation: 150,
        fallbackOnBody: true,
        swapThreshold: 0.65,
        handle: '.static-bullet', // Using the bullet as the drag handle
        onEnd: function(evt) {
            // console.log('Drag ended:', evt);
            // console.log('Item ID:', evt.item.dataset.noteId);
            // console.log('New parent ID:', evt.to.closest('.outline-item')?.dataset.noteId);
            // console.log('Old parent ID:', evt.from.closest('.outline-item')?.dataset.noteId);
            // console.log('New index:', evt.newIndex);
            // console.log('Old index:', evt.oldIndex);
            // Logic to update note order/parent will be added in the next step.
            handleNoteDrop(evt);
        }
    };

    // Initialize on the main container
    if (containerElement && !containerElement.classList.contains('has-sortable')) {
        new Sortable(containerElement, sortableOptions);
        containerElement.classList.add('has-sortable');
    }

    // Initialize on all child containers
    const childContainers = containerElement.querySelectorAll('.outline-children');
    childContainers.forEach(childContainer => {
        if (!childContainer.classList.contains('has-sortable')) {
            new Sortable(childContainer, sortableOptions);
            childContainer.classList.add('has-sortable');
        }
    });
}


function updateDraggedItemLevel(draggedItem, newBaseLevel) {
    if (!draggedItem || !draggedItem.dataset) return;

    draggedItem.dataset.level = newBaseLevel;

    const childrenContainer = draggedItem.querySelector('.outline-children');
    if (childrenContainer) {
        const childItems = childrenContainer.querySelectorAll(':scope > .outline-item'); // Direct children only
        childItems.forEach(child => {
            updateDraggedItemLevel(child, newBaseLevel + 1);
        });
    }
}


function handleNoteDrop(evt) {
    const draggedItem = evt.item;
    const draggedNoteId = draggedItem.dataset.noteId;
    const oldLevel = parseInt(draggedItem.dataset.level);
    const oldIndex = evt.oldIndex;

    const oldParentItem = evt.from.closest('.outline-item');
    const oldParentId = oldParentItem ? oldParentItem.dataset.noteId : null;

    const newParentItem = evt.to.closest('.outline-item');
    const newParentId = newParentItem ? newParentItem.dataset.noteId : null;

    let newLevel;
    if (newParentId === null) { // Dropped into the main outlineContainer
        newLevel = 0;
    } else {
        const newParentLevel = newParentItem ? parseInt(newParentItem.dataset.level) : -1; // Should always find parent if not root
        newLevel = newParentLevel + 1;
    }
    
    // Update data-level attribute before logging, so it reflects the new state
    updateDraggedItemLevel(draggedItem, newLevel);

    const updateData = {
        noteId: draggedNoteId,
        newParentId: newParentId,
        newIndex: evt.newIndex,
        newLevel: newLevel, 
        oldParentId: oldParentId,
        oldIndex: oldIndex,
        oldLevel: oldLevel 
    };

    console.log('Note Drop Update Data:', JSON.stringify(updateData));

    if (!currentPage || !currentPage.id) {
        console.error("Current page information is not available. Cannot save reorder changes.");
        alert("Error: Current page context lost. Please refresh.");
        // Optionally, try to force a reload or redirect to a default page.
        // For now, we just prevent the API call.
        return;
    }

    const payload = {
        action: 'reorder_note',
        note_id: parseInt(updateData.noteId),
        new_parent_id: updateData.newParentId ? parseInt(updateData.newParentId) : null,
        // new_level is no longer sent to the backend
        new_order: parseInt(updateData.newIndex), // This is the 'new_order' for the backend
        page_id: currentPage.id 
    };

    fetch('api/note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Error reordering note:', data.error);
            alert('Error saving changes: ' + data.error + '. Please refresh.');
            loadPage(currentPage.id); // Reload to ensure consistency
        } else {
            console.log('Note reordered successfully:', data);
            // The DOM was already updated optimistically regarding levels.
            // The order of siblings might have changed. Reloading the page
            // is the simplest way to ensure the UI reflects the database state.
            loadPage(currentPage.id);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Network error while saving changes. Please refresh.');
        loadPage(currentPage.id);
    });
}


async function zoomOut() {
    document.body.classList.remove('logseq-focus-active');
    outlineContainer.classList.remove('focused');
    window.breadcrumbPath = null; // Clear global breadcrumb path
    
    document.getElementById('new-note').style.display = 'block';
    document.getElementById('backlinks-container').style.display = 'block';
    
    if (currentPage && currentPage.id) {
        await loadPage(currentPage.id); 
    } else {
        console.warn("Zooming out but currentPage is not fully defined. Reloading to today's page.");
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today);
    }
}