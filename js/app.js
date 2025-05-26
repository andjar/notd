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
let prefetchedBlocks = {}; // Cache for prefetched blocks for the current page load
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
        await loadPage(targetHash);
    } else {
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
        await zoomOut(); 
    }
    navigateToPage(today);
});


window.addEventListener('hashchange', () => {
    const pageId = window.location.hash.substring(1);
    if (pageId === 'search-results') {
        showSearchResults();
    } else if (pageId) { 
        loadPage(pageId);
    } else { 
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
        navigateToPage(today); 
    } else {
        if (initialHash === 'search-results') {
            showSearchResults();
        } else {
            navigateToPage(initialHash); 
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
            navigateToPage(data.properties.alias);
            return;
        }

        currentPage = data;
        await renderPage(data); // This will call the NEW renderPage
        updateRecentPages(pageId);
        if (window.renderCalendarForCurrentPage) { 
            window.renderCalendarForCurrentPage(); 
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
            navigateToPage(encodeURIComponent(pageId)); 
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

async function renderNoteContent(note, prefetchedBlocks = {}) { 
    let content = note.content;
    
    content = content.replace(/^(TODO|DONE)\s*(.*)/gm, (match, status, taskText) => {
        return `%%TODO_ITEM%%${status}%%${taskText}%%`; 
    });
    
    const blockMatches = content.match(/\{\{([^}]+)\}\}/g) || [];
    for (const blockRefMatch of blockMatches) {
        const blockId = blockRefMatch.slice(2, -2).trim(); 
        let transclusionHtml = '';
        let sourcePageId = null;
        let sourcePageTitle = null;
        let blockDataForRecursiveCall = null;

        if (prefetchedBlocks && prefetchedBlocks[blockId]) {
            const prefetchedData = prefetchedBlocks[blockId];
            sourcePageId = prefetchedData.page_id;
            sourcePageTitle = prefetchedData.page_title;
            blockDataForRecursiveCall = { // Construct a note-like object for the recursive call
                content: prefetchedData.content,
                id: prefetchedData.note_id, 
                block_id: blockId, 
                attachments: [], // Prefetched data doesn't include these, assume empty or fetch if critical
                properties: {}   // Same as attachments
            };
            const renderedBlockContent = await renderNoteContent(blockDataForRecursiveCall, prefetchedBlocks);
            transclusionHtml = renderedBlockContent;
        } else {
            const fallbackBlock = await findBlockById(blockId); 
            if (fallbackBlock) {
                sourcePageId = fallbackBlock.page_id;
                sourcePageTitle = fallbackBlock.page_title;
                // fallbackBlock is a full note object, suitable for renderNoteContent
                const renderedBlockContent = await renderNoteContent(fallbackBlock, prefetchedBlocks);
                transclusionHtml = renderedBlockContent;
            } else {
                content = content.replace(blockRefMatch, `<span class="block-ref-brackets">{{</span><a href="#" class="block-ref-link" onclick="event.stopPropagation(); console.warn('Block not found: ${blockId}')">${blockId}</a><span class="block-ref-brackets">}}</span><span class="broken-transclusion"> (not found)</span>`);
                continue; 
            }
        }
        
        content = content.replace(blockRefMatch, `
            <div class="transcluded-block" data-block-id="${blockId}">
                ${transclusionHtml}
                <div class="transclusion-source">
                    <a href="#${encodeURIComponent(sourcePageId)}" onclick="event.stopPropagation();">
                        Source: ${sourcePageTitle || sourcePageId}</a>
                </div>
            </div>`);
    }

    content = content.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
        if (key.trim().toLowerCase() === 'tag') {
            return value.trim().split(',').map(tagValue => 
                `<a href="#${encodeURIComponent(tagValue.trim())}" class="property-tag" onclick="event.stopPropagation();">#${tagValue.trim()}</a>`
            ).join(' ');
        }
        return `<span class="property-tag">${key.trim()}: ${value.trim()}</span>`;
    });

    content = content.replace(/<<([^>]+)>>/g, (match, query) => {
        const displayQuery = query.length > 30 ? query.substring(0, 27) + '...' : query;
        return `<a href="#" class="search-link" onclick="event.preventDefault(); event.stopPropagation(); executeSearchLink('${query.replace(/'/g, "\\'")}')"><<${displayQuery}>></a>`;
    });
    
    content = marked.parse(content); 

    content = content.replace(/\[\[([^\]#]+?)\]\]/g, (match, pageName) => { 
        return `<span class="internal-link-brackets">[[</span><a href="#${encodeURIComponent(pageName.trim())}" class="internal-link" onclick="event.stopPropagation();">${pageName.trim()}</a><span class="internal-link-brackets">]]</span>`;
    });
    content = content.replace(/\[\[#(.*?)\]\]/g, (match, tagName) => {
        return `<span class="internal-link-brackets">[[</span><a href="#${encodeURIComponent(tagName.trim())}" class="property-tag" onclick="event.stopPropagation();">#${tagName.trim()}</a><span class="internal-link-brackets">]]</span>`;
    });
    content = content.replace(/(^|\s)#([a-zA-Z0-9_\-\/]+)/g, (match, precedingSpace, tagName) => {
        return `${precedingSpace}<a href="#${encodeURIComponent(tagName)}" class="property-tag" onclick="event.stopPropagation();">#${tagName}</a>`;
    });

    content = content.replace(/%%TODO_ITEM%%(TODO|DONE)%%(.*?)%%/g, (match, status, taskTextHtml) => {
        const isDone = status === 'DONE';
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

    content = content.replace(/<img src="([^"]+)" alt="([^"]*)"/g, (match, src, alt) => {
        return `<img src="${src}" alt="${alt}" class="note-image" onclick="event.stopPropagation(); showImageModal(this.src, this.alt)">`;
    });
    
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

async function renderOutline(notes, level = 0, prefetchedBlocks = {}) { 
    if (!notes || notes.length === 0) return ''; 

    let html = '';
    for (const note of notes) {
        const blockId = note.block_id;
        const contentHtml = await renderNoteContent(note, prefetchedBlocks); 
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
                        <button data-action="indent-note" title="Indent note (make child of item above)">→</button>
                        <button data-action="upload" title="Upload file">↑</button>
                        <button data-action="delete" title="Delete note">×</button>
                         | <span class="note-date" title="Created: ${new Date(note.created_at).toLocaleString()}">${new Date(note.created_at).toLocaleDateString()}</span>
                    </div>
                </div>`; 

        if (hasChildren) {
            html += `<div class="outline-children">`;
            html += await renderOutline(note.children, level + 1, prefetchedBlocks); 
            html += `</div>`; 
        }

        html += `</div>`; 
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

    // 1. Collect all blockIds for batch fetching
    const blockIdsToFetch = new Set();
    function collectBlockIdsRecursive(notes) { 
        if (!notes) return;
        for (const note of notes) {
            if (note.content) {
                const matches = note.content.matchAll(/{{\s*([a-zA-Z0-9_-]+)\s*}}/g);
                for (const match of matches) {
                    blockIdsToFetch.add(match[1].trim()); 
                }
            }
            if (note.children) {
                collectBlockIdsRecursive(note.children);
            }
        }
    }
    
    prefetchedBlocks = {}; 
    collectBlockIdsRecursive(page.notes);

    if (blockIdsToFetch.size > 0) {
        try {
            const idsArray = Array.from(blockIdsToFetch);
            const response = await fetch(`api/batch_blocks.php?ids=${idsArray.join(',')}`);
            if (response.ok) {
                prefetchedBlocks = await response.json();
            } else {
                console.error('Failed to fetch batch blocks:', response.status, await response.text());
            }
        } catch (error) {
            console.error('Error fetching batch blocks:', error);
        }
    }

    outlineContainer.innerHTML = await renderOutline(page.notes, 0, prefetchedBlocks); 
    initSortable(outlineContainer); 
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
            navigateToPage(currentPage.id); 
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
            // Pass prefetchedBlocks to renderOutline for backlinks, though it might be empty or from main page context
            const threadContentHtml = hierarchicalNotes.length > 0 ? await renderOutline(hierarchicalNotes, 0, prefetchedBlocks) : '';
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

function renderSearchResults(results) { 
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
        navigateToPage(currentPage.id); 
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
            navigateToPage(currentPage.id); 
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
    const todayDate = new Date(); 

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
                dayClasses += ' today'; 
            }
            if (date === currentPageId) { 
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
    renderCalendar(); 
}

function showImageModal(src, alt) {
    const modal = document.createElement('div');
    modal.className = 'modal image-modal';
    modal.innerHTML = `<div class="image-modal-content"><img src="${src}" alt="${alt || 'Pasted Image'}">
                       <button class="close-modal">×</button></div>`;
    modal.onclick = (e) => { if (e.target === modal || e.target.classList.contains('close-modal')) document.body.removeChild(modal); };
    document.body.appendChild(modal);
}

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

    if (breadcrumbItem && breadcrumbItem.dataset.action) {
        action = breadcrumbItem.dataset.action;
        if (action === 'zoom-in') {
            noteIdForAction = breadcrumbItem.dataset.noteId;
        }
    } else if (breadcrumbBar && !breadcrumbItem && breadcrumbBar.dataset.action === 'zoom-out') {
        action = 'zoom-out';
    }


    if (action === 'zoom-out') {
        zoomOut();
        return;
    }

    if (action === 'zoom-in') {
        const targetId = noteIdForAction; 
        if (targetId) {
            zoomInOnNote(targetId); 
        } else {
            console.warn("Zoom-in clicked, but no target ID found.");
        }
        return;
    }
    
    if (!noteElement || !noteIdForAction) {
        return;
    }

    switch (action) {
        case 'toggle-children':
            if (noteElement.classList.contains('has-children')) toggleChildren(noteElement);
            break;
        case 'copy-block-id':
            const blockIdToCopy = noteElement.querySelector('.outline-content[data-block-id]')?.dataset.blockId;
            if (blockIdToCopy) {
                navigator.clipboard.writeText(`{{${blockIdToCopy}}}`)
                    .then(() => console.log('Block ID copied:', blockIdToCopy))
                    .catch(err => console.error('Failed to copy block ID:', err));
            }
            break;
        case 'add-child': createNote(noteIdForAction, parseInt(noteElement.dataset.level) + 1); break;
        case 'indent-note': 
            handleIndentNote(noteIdForAction, noteElement);
            break;
        case 'edit': editNote(noteIdForAction, noteElement.dataset.content); break;
        case 'upload':
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.onchange = (e) => uploadFile(noteIdForAction, e.target.files[0]);
            fileInput.click();
            break;
        case 'delete': deleteNote(noteIdForAction); break;
        default:
            break;
    }
}

async function handleIndentNote(noteId, noteElement) {
    if (!currentPage || !currentPage.id) {
        console.error("Cannot indent: Current page context lost.");
        alert("Error: Current page context lost. Please refresh.");
        return;
    }

    let previousSiblingElement = noteElement.previousElementSibling;
    while (previousSiblingElement && !previousSiblingElement.matches('.outline-item')) {
        previousSiblingElement = previousSiblingElement.previousElementSibling;
    }

    if (!previousSiblingElement) {
        alert("Cannot indent this note further: no suitable preceding item found to become its parent.");
        return;
    }

    const newParentId = previousSiblingElement.dataset.noteId;
    if (!newParentId) {
        alert("Error: Preceding item is missing a note ID.");
        return;
    }

    const newParentChildrenContainer = previousSiblingElement.querySelector(':scope > .outline-children');
    let newIndex = 0;
    if (newParentChildrenContainer) {
        newIndex = newParentChildrenContainer.querySelectorAll(':scope > .outline-item').length;
    }
    
    const payload = {
        action: 'reorder_note',
        note_id: parseInt(noteId),
        new_parent_id: parseInt(newParentId),
        new_order: newIndex, 
        page_id: currentPage.id
    };

    try {
        const response = await fetch('api/note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }
        loadPage(currentPage.id); 
    } catch (error) {
        console.error('Error indenting note:', error);
        alert('Error indenting note: ' + error.message + '. Please refresh.');
        loadPage(currentPage.id); 
    }
}

function toggleChildren(noteElement) {
    noteElement.classList.toggle('children-hidden');
    const childrenContainer = noteElement.querySelector('.outline-children');
    if (childrenContainer) {
        if (noteElement.classList.contains('children-hidden')) {
            childrenContainer.style.display = 'none'; 
        } else {
            childrenContainer.style.display = ''; 
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
                parentNoteElement.appendChild(childrenContainer);
            }
            childrenContainer.appendChild(noteEditorContainer); 
    
            parentNoteElement.classList.add('has-children');
            if (parentNoteElement.classList.contains('children-hidden')) {
                parentNoteElement.classList.remove('children-hidden');
                childrenContainer.style.display = ''; 
            }
        } else { outlineContainer.appendChild(noteEditorContainer); }
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
            navigateToPage(currentPage.id); 
        } catch (error) { console.error('Error creating note:', error); alert('Error creating note: ' + error.message); }
    };
    cancelButton.onclick = () => {
        noteEditorContainer.remove();
        if (parentId) {
            const parentNoteEl = document.querySelector(`.outline-item[data-note-id="${parentId}"]`);
            if (parentNoteEl) {
                const childrenContainer = parentNoteEl.querySelector('.outline-children');
                if (childrenContainer && childrenContainer.children.length === 0) {
                    parentNoteEl.classList.remove('has-children');
                    // Optionally remove the empty childrenContainer, or hide it
                    // childrenContainer.remove(); 
                }
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
            navigateToPage(currentPage.id); 
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
            navigateToPage(currentPage.id); 
        } catch (error) { console.error('Error deleting note:', error); alert('Error deleting note: ' + error.message); document.body.removeChild(modal); }
    };
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); });
}

async function showSearchResults() { 
    const results = JSON.parse(sessionStorage.getItem('searchResults') || '[]');
    const query = sessionStorage.getItem('searchQuery') || '';
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';
    pageProperties.style.display = 'none';
    const renderedResultsPromises = results.map(async result => {
        // Ensure prefetchedBlocks is passed, though it might be from a different page context
        const content = await renderNoteContent(result, prefetchedBlocks); 
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
async function executeSearchLink(query) { 
    try {
        const response = await fetch('api/advanced_search.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ query }) });
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const results = await response.json();
        if (results.error) throw new Error(results.error);
        sessionStorage.setItem('searchResults', JSON.stringify(results));
        sessionStorage.setItem('searchQuery', query);
        window.location.hash = 'search-results'; 
    } catch (error) { console.error('Error executing search link:', error); alert('Error executing search: ' + error.message); }
}

async function toggleTodo(blockId, isDone) {
    try {
        // Try to get from prefetched first if it's a pure block_id based toggle
        let currentNoteData;
        if (prefetchedBlocks && prefetchedBlocks[blockId]) {
            const prefetched = prefetchedBlocks[blockId];
            currentNoteData = { // Reconstruct a note-like object
                id: prefetched.note_id, // actual note ID
                content: prefetched.content,
                block_id: blockId,
                // These might be missing or stale from prefetched data:
                properties: {}, // findBlockById would have full properties
                level: 0,       // unknown from prefetched
                parent_id: null // unknown from prefetched
            };
            // If properties are critical for TODO state, findBlockById might be better.
            // For now, assume content is primary.
        } else {
            currentNoteData = await findBlockById(blockId); // Fetches full note data
        }

        if (!currentNoteData) throw new Error('Note not found for toggling TODO');
        
        let rawContent = currentNoteData.content;
        let taskTextWithProperties = "";
        let currentStatus = "";

        if (rawContent.startsWith('TODO ')) {
            taskTextWithProperties = rawContent.substring(5);
            currentStatus = "TODO";
        } else if (rawContent.startsWith('DONE ')) {
            taskTextWithProperties = rawContent.substring(5);
            currentStatus = "DONE";
        } else {
            taskTextWithProperties = rawContent;
            currentStatus = "TODO"; 
        }
        
        const taskSpecificProperties = {};
        let cleanTaskDescription = taskTextWithProperties.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
            taskSpecificProperties[key.trim()] = value.trim();
            return ''; 
        }).trim();

        let newStatusPrefix = isDone ? 'DONE ' : 'TODO ';
        let newContentString = newStatusPrefix + cleanTaskDescription; 

        const updatedNoteProperties = { ...(currentNoteData.properties || {}) }; 
        
        if (isDone) {
            taskSpecificProperties['done-at'] = new Date().toISOString();
        } else {
            delete taskSpecificProperties['done-at']; 
        }
        
        for (const [key, value] of Object.entries(taskSpecificProperties)) {
            newContentString += ` {${key}::${value}}`;
            updatedNoteProperties[key] = value; 
        }
        if (!isDone) {
            delete updatedNoteProperties['done-at']; 
        }
        
        // Use currentNoteData.id which is the actual note ID
        const response = await fetch(`api/note.php?id=${currentNoteData.id}`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'update', 
                content: newContentString.trim(), 
                properties: updatedNoteProperties, 
                level: currentNoteData.level, 
                parent_id: currentNoteData.parent_id 
            })
        });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        navigateToPage(currentPage.id); 
    } catch (error) {
        console.error('Error updating todo status:', error); 
        alert('Error updating task: ' + error.message);
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

    const textarea = modal.querySelector('#advanced-search-query');
    if (textarea) {
        textarea.focus();
    }

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
            let errorMsg = `HTTP error! status: ${response.status}`;
            try {
                const errorData = await response.json();
                if (errorData && errorData.error) {
                    errorMsg = errorData.error;
                }
            } catch (e) { }
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
    
    breadcrumbHtml += `<span class="breadcrumb-item page-link" data-action="zoom-out">${currentPage.title}</span>`;

    path.forEach((note, index) => {
        let plainContent = note.content.replace(/<\/?[^>]+(>|$)/g, " ").replace(/\s+/g, ' ').trim();
        const contentSnippet = plainContent.length > 30 
            ? plainContent.substring(0, 27) + '...' 
            : (plainContent || 'Untitled Note');
            
        const isLast = index === path.length - 1;
        breadcrumbHtml += `<span class="breadcrumb-separator">»</span>`;
        if (isLast) {
            breadcrumbHtml += `<span class="breadcrumb-item current-focus">${contentSnippet}</span>`;
        } else {
            breadcrumbHtml += `<span class="breadcrumb-item" data-action="zoom-in" data-note-id="${note.id}">${contentSnippet}</span>`;
        }
    });

    breadcrumbHtml += '</div>';
    return breadcrumbHtml;
}


async function zoomInOnNote(targetNoteReference) {
    let noteIdToZoom;

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
        await zoomOut();
        return;
    }

    const noteDataWithPath = findNoteAndPath(noteIdToZoom, currentPage.notes);

    if (!noteDataWithPath || !noteDataWithPath.note) {
        console.error("Note to zoom (ID: " + noteIdToZoom + ") not found in current page data. Zooming out.");
        await zoomOut(); 
        return;
    }

    const { note: noteInFullTree, path } = noteDataWithPath;
    window.breadcrumbPath = path; 

    document.body.classList.add('logseq-focus-active');
    outlineContainer.classList.add('focused');

    const clonedFocusedNote = JSON.parse(JSON.stringify(noteInFullTree));
    clonedFocusedNote.level = 0; 
    if (clonedFocusedNote.children) {
        adjustLevels(clonedFocusedNote.children, 1); 
    }
    const focusedNotesArray = [clonedFocusedNote]; 

    pageProperties.style.display = 'none';
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';

    const breadcrumbsHtml = renderBreadcrumbs(path); 
    // Pass prefetchedBlocks (which is global) to renderOutline for the zoomed view
    outlineContainer.innerHTML = breadcrumbsHtml + (await renderOutline(focusedNotesArray, 0, prefetchedBlocks));
    initSortable(outlineContainer); 

    const focusedDomNote = outlineContainer.querySelector('.outline-item[data-level="0"]');
    if (focusedDomNote) {
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
        handle: '.static-bullet', 
        onEnd: function(evt) {
            handleNoteDrop(evt);
        }
    };

    if (containerElement && !containerElement.classList.contains('has-sortable')) {
        new Sortable(containerElement, sortableOptions);
        containerElement.classList.add('has-sortable');
    }

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
        const childItems = childrenContainer.querySelectorAll(':scope > .outline-item'); 
        childItems.forEach(child => {
            updateDraggedItemLevel(child, newBaseLevel + 1);
        });
    }
}


function handleNoteDrop(evt) {
    const draggedItem = evt.item;
    const draggedNoteId = draggedItem.dataset.noteId;

    const oldParentItem = evt.from.closest('.outline-item');
    const oldParentId = oldParentItem ? oldParentItem.dataset.noteId : null;
    const oldLevel = parseInt(draggedItem.dataset.level); 
    const oldIndex = evt.oldIndex; 

    let newParentIdCandidateEl = evt.to.closest('.outline-item');
    let newParentId = newParentIdCandidateEl ? newParentIdCandidateEl.dataset.noteId : null;
    let newIndex = evt.newIndex;
    let newLevel;

    const dropTargetItemElement = evt.originalEvent.target.closest('.outline-item');

    if (dropTargetItemElement && dropTargetItemElement !== draggedItem) {
        const parentListOfDropTarget = dropTargetItemElement.parentElement; 

        if (evt.to === parentListOfDropTarget &&
            parentListOfDropTarget.children[evt.newIndex - 1] === dropTargetItemElement) {
            newParentId = dropTargetItemElement.dataset.noteId; 
            newIndex = 0; 
        }
    }

    if (newParentId === null) { 
        newLevel = 0;
    } else {
        const finalNewParentDomItem = outlineContainer.querySelector(`.outline-item[data-note-id="${newParentId}"]`);
        if (finalNewParentDomItem) {
            newLevel = parseInt(finalNewParentDomItem.dataset.level) + 1;
        } else {
            console.error("Error: Could not find the final new parent DOM element for level calculation. Reloading page.");
            loadPage(currentPage.id); 
            return;
        }
    }

    updateDraggedItemLevel(draggedItem, newLevel);

    const updateData = {
        noteId: draggedNoteId,
        newParentId: newParentId,
        newIndex: newIndex,
        oldParentId: oldParentId, 
        oldIndex: oldIndex,       
        oldLevel: oldLevel        
    };


    if (!currentPage || !currentPage.id) {
        console.error("Current page information is not available. Cannot save reorder changes.");
        alert("Error: Current page context lost. Please refresh.");
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today);
        return;
    }

    const payload = {
        action: 'reorder_note',
        note_id: parseInt(draggedNoteId),
        new_parent_id: newParentId ? parseInt(newParentId) : null,
        new_order: parseInt(newIndex), 
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
            loadPage(currentPage.id); 
        } else {
            loadPage(currentPage.id);
        }
    })
    .catch(error => {
        console.error('Fetch error during reorder:', error);
        alert('Network error while saving changes. Please refresh.');
        loadPage(currentPage.id); 
    });
}


async function zoomOut() {
    document.body.classList.remove('logseq-focus-active');
    outlineContainer.classList.remove('focused');
    window.breadcrumbPath = null; 
    
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