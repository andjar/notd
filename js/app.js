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

// Event Listeners
searchInput.addEventListener('input', debounce(handleSearch, 300));
newPageButton.addEventListener('click', createNewPage);
outlineContainer.addEventListener('click', handleOutlineClick);

// Add advanced search functionality
document.getElementById('advanced-search-link').addEventListener('click', (e) => {
    e.preventDefault();
    showAdvancedSearch();
});

function showAdvancedSearch() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="advanced-search-content">
            <h3>Advanced Search</h3>
            <div class="help-text">
                Enter a SQL query to search based on block properties. Example:<br>
                <code>SELECT * FROM notes WHERE block_id IN (SELECT block_id FROM properties WHERE property_key = 'status' AND property_value = 'done')</code>
            </div>
            <textarea id="advanced-search-query" placeholder="Enter your SQL query..."></textarea>
            <div class="button-group">
                <button class="btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                <button class="btn-primary" onclick="executeAdvancedSearch()">Search</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Close modal when clicking outside
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

async function executeAdvancedSearch() {
    const query = document.getElementById('advanced-search-query').value.trim();
    if (!query) return;
    
    try {
        const response = await fetch('api/advanced_search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const results = await response.json();
        if (results.error) {
            throw new Error(results.error);
        }
        
        // Store results in sessionStorage
        sessionStorage.setItem('searchResults', JSON.stringify(results));
        sessionStorage.setItem('searchQuery', query);
        
        // Close the modal
        document.querySelector('.modal').remove();
        
        // Navigate to search results page
        window.location.hash = 'search-results';
    } catch (error) {
        console.error('Error executing advanced search:', error);
        alert('Error executing search: ' + error.message);
    }
}

// Add this to the hash change listener
window.addEventListener('hashchange', () => {
    const pageId = window.location.hash.substring(1);
    if (pageId === 'search-results') {
        showSearchResults();
    } else if (pageId) {
        loadPage(pageId);
    }
});

// Initialize the app
document.addEventListener('DOMContentLoaded', async () => {
    await loadTemplates();
    loadRecentPages();
    initCalendar();
    // If no page is specified in URL, create today's journal page
    if (!window.location.hash) {
        const today = new Date().toISOString().split('T')[0];
        window.location.hash = today;
    } else {
        loadPage(window.location.hash.substring(1));
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

// API Functions
async function loadPage(pageId) {
    try {
        // Show new note button and backlinks
        document.getElementById('new-note').style.display = 'block';
        document.getElementById('backlinks-container').style.display = 'block';
        
        const response = await fetch(`api/page.php?id=${pageId}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const text = await response.text();
        if (!text) {
            throw new Error('Empty response from server');
        }
        const data = JSON.parse(text);
        
        if (data.error) {
            console.error('Server error:', data.error);
            return;
        }

        // Check for alias property
        if (data.properties && data.properties.alias) {
            window.location.hash = data.properties.alias;
            return;
        }

        currentPage = data;
        await renderPage(data);
        updateRecentPages(pageId);
    } catch (error) {
        console.error('Error loading page:', error);
        // Show error to user
        outlineContainer.innerHTML = `
            <div class="error-message">
                <h3>Error loading page</h3>
                <p>${error.message}</p>
                <button onclick="loadPage('${pageId}')">Retry</button>
            </div>
        `;
    }
}

async function loadRecentPages() {
    try {
        const response = await fetch('api/recent_pages.php');
        const data = await response.json();
        if (data.error) {
            console.error(data.error);
            return;
        }
        recentPages = data;
        renderRecentPages();
    } catch (error) {
        console.error('Error loading recent pages:', error);
    }
}

async function handleSearch(event) {
    const query = event.target.value;
    if (query.length < 2) return;

    try {
        const response = await fetch(`api/search.php?q=${encodeURIComponent(query)}`);
        const results = await response.json();
        renderSearchResults(results);
    } catch (error) {
        console.error('Error searching:', error);
    }
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
        if (!pageId) {
            alert('Please enter a page ID');
            return;
        }

        // Determine type based on pageId (date-like)
        let type = 'note';
        let properties = {};
        
        // Check if pageId is a date (YYYY-MM-DD)
        if (/^\d{4}-\d{2}-\d{2}$/.test(pageId)) {
            type = 'journal';
            properties = { 'type': 'journal' };  // Set type property correctly
        }

        const requestData = {
            id: encodeURIComponent(pageId),
            title: pageId, // Use the ID as the title initially
            type,
            properties
        };
        
        console.log('Creating page with data:', requestData);

        try {
            const response = await fetch('api/page.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            });

            const data = await response.json();
            console.log('Server response:', data);
            
            if (data.error) {
                console.error(data.error);
                return;
            }

            document.body.removeChild(modal);
            window.location.hash = encodeURIComponent(pageId);
            loadPage(encodeURIComponent(pageId));
        } catch (error) {
            console.error('Error creating page:', error);
        }
    };

    cancelButton.onclick = () => {
        document.body.removeChild(modal);
    };
}

// Add this function before renderNoteContent
async function findBlockById(blockId) {
    try {
        const response = await fetch(`api/block.php?id=${blockId}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();
        if (data.error) {
            throw new Error(data.error);
        }
        return data;
    } catch (error) {
        console.error('Error finding block:', error);
        return null;
    }
}

// Update renderNoteContent to handle search links
async function renderNoteContent(note) {
    // First process search links before markdown parsing
    let content = note.content;
    
    // Check if content is just a single page link
    const singleLinkMatch = content.match(/^\[\[(.*?)\]\]$/);
    if (singleLinkMatch) {
        const linkedPageId = singleLinkMatch[1];
        try {
            const response = await fetch(`api/page.php?id=${linkedPageId}`);
            if (response.ok) {
                const linkedPage = await response.json();
                if (linkedPage.type) {
                    // Add the linked page type as a class to the note element
                    const noteElement = document.querySelector(`[data-note-id="${note.id}"]`);
                    if (noteElement) {
                        noteElement.classList.add(`linked-page-${linkedPage.type}`);
                    }
                }
            }
        } catch (error) {
            console.error('Error fetching linked page type:', error);
        }
    }
    
    // Process search links <<query>> - store them temporarily
    const searchLinks = [];
    content = content.replace(/<<([^>]+)>>/g, (match, query) => {
        const displayQuery = query.length > 30 ? query.substring(0, 27) + '...' : query;
        const id = `search-link-${searchLinks.length}`;
        searchLinks.push({ id, query, displayQuery });
        return `<a href="#" class="search-link" onclick="event.preventDefault(); executeSearchLink('${query.replace(/'/g, "\\'")}')"><<${displayQuery}>></a>`;
    });
    
    // Extract properties but keep them in the content
    const properties = {};
    content = content.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
        properties[key.trim()] = value.trim();
        if (key.trim() === 'tag') {
            return `<a href="#${value.trim()}" class="property-tag" onclick="event.preventDefault(); loadPage('${value.trim()}');">#${value.trim()}</a>`;
        }
        return `<span class="property-tag">${key.trim()}: ${value.trim()}</span>`;
    });
    
    // Now process markdown
    content = marked.parse(content);
    
    // Process block transclusion {{block_id}}
    const blockMatches = content.match(/\{\{([^}]+)\}\}/g) || [];
    for (const match of blockMatches) {
        const blockId = match.slice(2, -2); // Remove {{ and }}
        const block = await findBlockById(blockId);
        if (block) {
            const blockContent = await renderNoteContent(block);
            content = content.replace(match, `
                <div class="transcluded-block" data-block-id="${blockId}">
                    ${blockContent}
                    <div class="transclusion-source">
                        <a href="#${block.page_id}" onclick="event.preventDefault(); loadPage('${block.page_id}');">
                            Source: ${block.page_title || block.page_id}
                        </a>
                    </div>
                </div>
            `);
        } else {
            content = content.replace(match, `<span class="broken-transclusion">${match}</span>`);
        }
    }
    
    // Process images to add click-to-enlarge functionality
    content = content.replace(/<img src="([^"]+)" alt="([^"]+)"/g, (match, src, alt) => {
        return `<img src="${src}" alt="${alt}" class="note-image" onclick="showImageModal(this.src, this.alt)">`;
    });
    
    // Process TODO/DONE status with improved regex
    content = content.replace(/<p>(TODO|DONE)\s*(.*?)<\/p>/g, (match, status, textContentHtml) => {
        const isDone = status === 'DONE';
        // ... (processedTextForDisplay logic remains the same)
        const processedTextForDisplay = textContentHtml.replace(/\[\[(.*?)\]\]/g, (linkMatch, p1) => {
            return `<a href="#${p1}" class="internal-link" onclick="event.stopPropagation(); loadPage('${p1}');">${p1}</a>`;
        });
        
        return `
            <div class="todo-item">
                <label class="todo-checkbox">
                    <input type="checkbox" ${isDone ? 'checked' : ''} 
                        onchange="event.stopPropagation(); toggleTodo('${note.block_id}', this.checked)"> 
                    <span class="status-${status.toLowerCase()}">${processedTextForDisplay}</span>
                </label>
            </div>
        `;
    });
    
    // Process links
    content = content.replace(/\[\[(.*?)\]\]/g, (match, p1) => {
        return `<a href="#${p1}" class="internal-link" onclick="event.preventDefault(); loadPage('${p1}');">${p1}</a>`;
    });
    
    // Add attachments section
    if (note.attachments && note.attachments.length > 0) {
        content += '<div class="attachments">';
        note.attachments.forEach(attachment => {
            content += `
                <div class="file-attachment">
                    <a href="uploads/${attachment.filename}" target="_blank">${attachment.original_name}</a>
                    <span class="delete-attachment" onclick="deleteAttachment(${attachment.id})">×</span>
                </div>
            `;
        });
        content += '</div>';
    }
    
    return content;
}

// Update renderOutline to add focus button
async function renderOutline(notes, level = 0) {
    if (!notes) return '';
    
    const renderedNotes = await Promise.all(notes.map(async note => {
        // Ensure block_id is available
        const blockId = note.block_id;
        const content = await renderNoteContent(note);
        
        // Check if content is just a single page link
        const singleLinkMatch = note.content.match(/^\[\[(.*?)\]\]$/);
        let linkedPageType = null;
        if (singleLinkMatch) {
            const linkedPageId = singleLinkMatch[1];
            try {
                console.log('Fetching page type for:', linkedPageId);
                const response = await fetch(`api/page.php?id=${linkedPageId}`);
                if (response.ok) {
                    const linkedPage = await response.json();
                    console.log('Linked page data:', linkedPage);
                    // Check properties.type first, then fall back to root type
                    if (linkedPage.properties && linkedPage.properties.type) {
                        linkedPageType = linkedPage.properties.type;
                        console.log('Setting linked page type from properties:', linkedPageType);
                    } else if (linkedPage.type) {
                        linkedPageType = linkedPage.type;
                        console.log('Setting linked page type from root:', linkedPageType);
                    } else {
                        console.log('No type found in linked page data');
                    }
                } else {
                    console.log('Failed to fetch page:', response.status);
                }
            } catch (error) {
                console.error('Error fetching linked page type:', error);
            }
        }
        
        // Create the note HTML
        const noteHtml = `
            <div class="outline-item ${linkedPageType ? `linked-page-${linkedPageType}` : ''}" 
                 data-note-id="${note.id}"
                 data-level="${level}"
                 data-content="${note.content.replace(/"/g, '&quot;')}">
                <div class="outline-content" ${blockId ? `data-block-id="${blockId}"` : ''}>
                    ${content}
                    ${note.properties ? renderProperties(note.properties) : ''}
                    <div class="note-actions">
                        <button data-action="add-child" title="Add child note">+</button>
                        <button data-action="focus" title="Focus on this thread"></button>
                        ${blockId ? `<button data-action="copy-block-id" title="Copy block ID">#</button>` : ''}
                        <button data-action="edit" title="Edit note">✎</button>
                        <button data-action="upload" title="Upload file">↑</button>
                        <button data-action="delete" title="Delete note">×</button>
                        <span class="note-date" title="Created: ${new Date(note.created_at).toLocaleString()}">
                            ${new Date(note.created_at).toLocaleDateString()}
                        </span>
                    </div>
                </div>
            </div>
        `;

        // If there are children, render them recursively
        const childrenHtml = note.children ? await renderOutline(note.children, level + 1) : '';
        
        // Return both the note and its children
        return noteHtml + childrenHtml;
    }));
    
    return renderedNotes.join('');
}

// Update renderPage to handle async content
async function renderPage(page) {
    pageTitle.innerHTML = `
        <span class="page-title-text">${page.title}</span>
        <button class="edit-properties-button" title="Edit page properties"></button>
    `;
    
    // Render page properties
    if (page.properties) {
        const propertiesHtml = Object.entries(page.properties)
            .map(([key, value]) => `
                <div class="page-property">
                    <span class="property-key">${key}:</span>
                    <span class="property-value">${value}</span>
                </div>
            `).join('');
        pageProperties.innerHTML = `
            <div class="page-properties-content">
                ${propertiesHtml}
            </div>
        `;
    } else {
        pageProperties.innerHTML = `
            <div class="page-properties-content">
            </div>
        `;
    }
    
    // Add edit properties button handler
    const editButton = pageTitle.querySelector('.edit-properties-button');
    if (editButton) {
        editButton.onclick = editPageProperties;
    }
    
    outlineContainer.innerHTML = await renderOutline(page.notes);
    
    // Render backlinks
    const backlinksContainer = document.getElementById('backlinks-container');
    if (backlinksContainer) {
        renderBacklinks(page.id).then(html => {
            backlinksContainer.innerHTML = html;
        });
    }
}

async function editPageProperties() {
    if (!currentPage) return;
    
    const properties = currentPage.properties || {};
    const propertyList = Object.entries(properties)
        .map(([key, value]) => `${key}::${value}`)
        .join('\n');
    
    const editorHtml = `
        <div class="properties-editor">
            <textarea class="properties-textarea" placeholder="Enter properties (one per line, format: key::value)">${propertyList}</textarea>
            <div class="properties-editor-actions">
                <button class="btn-primary save-properties">Save</button>
                <button class="btn-secondary cancel-properties">Cancel</button>
            </div>
        </div>
    `;
    
    const propertiesContent = pageProperties.querySelector('.page-properties-content');
    const originalContent = propertiesContent.innerHTML;
    propertiesContent.innerHTML = editorHtml;
    
    const textarea = propertiesContent.querySelector('.properties-textarea');
    const saveButton = propertiesContent.querySelector('.save-properties');
    const cancelButton = propertiesContent.querySelector('.cancel-properties');
    
    textarea.focus();
    
    // Add Ctrl+Enter handler
    textarea.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            saveButton.click();
        }
    });
    
    saveButton.onclick = async () => {
        const newProperties = textarea.value.trim();
        if (!newProperties) {
            propertiesContent.innerHTML = originalContent;
            return;
        }
        
        const updatedProperties = {};
        newProperties.split('\n').forEach(line => {
            const [key, value] = line.split('::').map(s => s.trim());
            if (key && value) {
                updatedProperties[key] = value;
            }
        });
        
        try {
            const response = await fetch(`api/page.php?id=${currentPage.id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'update',
                    title: currentPage.title,
                    type: currentPage.type || 'note',
                    properties: updatedProperties
                })
            });
            
            const data = await response.json();
            if (data.error) {
                console.error(data.error);
                return;
            }
            
            loadPage(currentPage.id);
        } catch (error) {
            console.error('Error updating properties:', error);
        }
    };
    
    cancelButton.onclick = () => {
        propertiesContent.innerHTML = originalContent;
    };
}

function renderProperties(properties) {
    if (!properties || Object.keys(properties).length === 0) return '';
    
    return Object.entries(properties)
        .map(([key, value]) => `
            <span class="property-tag">
                ${key}: ${value}
            </span>
        `).join('');
}

async function renderBacklinks(pageId) {
    try {
        const response = await fetch(`api/search.php?q=[[${pageId}]]`);
        const results = await response.json();
        
        if (!results || results.length === 0) {
            return '<p>No backlinks found</p>';
        }
        
        const renderedResults = await Promise.all(results.map(async result => {
            const content = await renderNoteContent(result);
            return `
                <div class="backlink-item">
                    <a href="#${result.page_id}" onclick="event.preventDefault(); loadPage('${result.page_id}');">
                        ${result.page_title || result.page_id}
                    </a>
                    <div class="backlink-context">${content}</div>
                </div>
            `;
        }));
        
        return `
            <h3>Backlinks</h3>
            <div class="backlinks-list">
                ${renderedResults.join('')}
            </div>
        `;
    } catch (error) {
        console.error('Error loading backlinks:', error);
        return '<p>Error loading backlinks</p>';
    }
}

function renderRecentPages() {
    if (!recentPagesList) return;
    
    const recentPagesHtml = recentPages
        .map(page => `
            <li onclick="loadPage('${page.page_id.replace(/'/g, "\\'")}')">
                ${page.title || decodeURIComponent(page.page_id)}
                <small>${new Date(page.last_opened).toLocaleDateString()}</small>
            </li>
        `).join('');

    recentPagesList.innerHTML = `
        <div class="recent-pages-header">
            <h3>Recent Pages</h3>
            <a href="#" class="all-pages-link" onclick="showAllPages(); return false;">All pages</a>
        </div>
        <ul>${recentPagesHtml}</ul>
    `;
}

async function showAllPages() {
    try {
        const response = await fetch('api/all_pages.php');
        const pages = await response.json();
        
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="page-list-modal">
                <h3>All Pages</h3>
                <div class="page-list">
                    ${pages.map(page => `
                        <div class="page-list-item" onclick="loadPage('${page.id.replace(/'/g, "\\'")}'); document.body.removeChild(modal);">
                            ${page.title || decodeURIComponent(page.id)}
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
        
        modal.onclick = (e) => {
            if (e.target === modal) {
                document.body.removeChild(modal);
            }
        };
        
        document.body.appendChild(modal);
    } catch (error) {
        console.error('Error loading all pages:', error);
    }
}

function renderSearchResults(results) {
    const searchResults = document.createElement('div');
    searchResults.className = 'search-results';
    
    results.forEach(result => {
        const resultElement = document.createElement('div');
        resultElement.className = 'search-result';
        resultElement.innerHTML = `
            <h4>${result.page_title}</h4>
            <div class="result-content">${renderNoteContent(result)}</div>
            <div class="result-context">
                ${result.context.map(note => `
                    <div class="context-item">${renderNoteContent(note)}</div>
                `).join('')}
            </div>
        `;
        resultElement.onclick = () => {
            loadPage(result.page_id);
            searchInput.value = '';
            searchResults.remove();
        };
        searchResults.appendChild(resultElement);
    });
    
    // Remove existing results if any
    const existingResults = document.querySelector('.search-results');
    if (existingResults) {
        existingResults.remove();
    }
    
    document.body.appendChild(searchResults);
}

// Update URL and recent pages when loading a page
function updateRecentPages(pageId) {
    window.location.hash = pageId;
    fetch('api/recent_pages.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ page_id: pageId })
    }).then(() => loadRecentPages());
}

async function uploadFile(noteId, file) {
    if (!file) return;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('note_id', noteId);

    try {
        const response = await fetch('api/attachment.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        if (data.error) {
            console.error(data.error);
            return;
        }

        loadPage(currentPage.id);
    } catch (error) {
        console.error('Error uploading file:', error);
    }
}

async function deleteAttachment(id) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;

    try {
        const response = await fetch(`api/attachment.php?id=${id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'delete'
            })
        });

        const data = await response.json();
        if (data.error) {
            console.error(data.error);
            return;
        }

        loadPage(currentPage.id);
    } catch (error) {
        console.error('Error deleting attachment:', error);
    }
}

// Add calendar functionality
function initCalendar() {
    const calendar = document.getElementById('calendar');
    if (!calendar) return;

    const today = new Date();
    let currentMonth = today.getMonth();
    let currentYear = today.getFullYear();

    function renderCalendar() {
        const firstDay = new Date(currentYear, currentMonth, 1);
        const lastDay = new Date(currentYear, currentMonth + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDay = firstDay.getDay();

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                          'July', 'August', 'September', 'October', 'November', 'December'];

        let html = `
            <div class="calendar-header">
                <button class="calendar-nav" onclick="prevMonth()">←</button>
                <span><b>${monthNames[currentMonth]} ${currentYear}</b></span>
                <button class="calendar-nav" onclick="nextMonth()">→</button>
            </div>
            <div class="calendar-grid">
                <div class="calendar-weekday">Sun</div>
                <div class="calendar-weekday">Mon</div>
                <div class="calendar-weekday">Tue</div>
                <div class="calendar-weekday">Wed</div>
                <div class="calendar-weekday">Thu</div>
                <div class="calendar-weekday">Fri</div>
                <div class="calendar-weekday">Sat</div>
        `;

        let day = 1;
        for (let i = 0; i < 6; i++) {
            for (let j = 0; j < 7; j++) {
                if (i === 0 && j < startingDay) {
                    html += '<div class="calendar-day empty"></div>';
                } else if (day > daysInMonth) {
                    html += '<div class="calendar-day empty"></div>';
                } else {
                    const date = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const isToday = date === today.toISOString().split('T')[0];
                    html += `
                        <div class="calendar-day ${isToday ? 'today' : ''}" 
                             onclick="loadPage('${date}')"
                             data-date="${date}">
                            ${day}
                        </div>
                    `;
                    day++;
                }
            }
        }

        html += '</div>';
        calendar.innerHTML = html;
    }

    window.prevMonth = () => {
        if (currentMonth === 0) {
            currentMonth = 11;
            currentYear--;
        } else {
            currentMonth--;
        }
        renderCalendar();
    };

    window.nextMonth = () => {
        if (currentMonth === 11) {
            currentMonth = 0;
            currentYear++;
        } else {
            currentMonth++;
        }
        renderCalendar();
    };

    renderCalendar();
}

// Add home button functionality
document.getElementById('home-button').addEventListener('click', (e) => {
    e.preventDefault();
    const today = new Date().toISOString().split('T')[0];
    loadPage(today);
});

// Add image modal functionality
function showImageModal(src, alt) {
    const modal = document.createElement('div');
    modal.className = 'image-modal';
    modal.innerHTML = `
        <div class="image-modal-content">
            <img src="${src}" alt="${alt}">
            <button class="close-modal">×</button>
        </div>
    `;
    
    modal.onclick = (e) => {
        if (e.target === modal) {
            document.body.removeChild(modal);
        }
    };
    
    modal.querySelector('.close-modal').onclick = () => {
        document.body.removeChild(modal);
    };
    
    document.body.appendChild(modal);
}

function renderNote(note) {
    const noteElement = document.createElement('div');
    noteElement.className = 'outline-item';
    noteElement.dataset.noteId = note.id;
    noteElement.dataset.level = note.level;
    noteElement.dataset.content = note.content;
    noteElement.dataset.blockId = note.block_id;  // Add block_id to data attributes

    const contentDiv = document.createElement('div');
    contentDiv.className = 'outline-content';
    contentDiv.innerHTML = marked.parse(note.content);

    // Add note actions
    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'note-actions';
    actionsDiv.innerHTML = `
        <button data-action="add-child" title="Add child note">+</button>
        <button data-action="edit" title="Edit note">✎</button>
        <button data-action="upload" title="Upload file">↑</button>
        <button data-action="delete" title="Delete note">×</button>
        <span class="note-date" title="Created: ${new Date(note.created_at).toLocaleString()}">
            ${new Date(note.created_at).toLocaleDateString()}
        </span>
    `;

    contentDiv.appendChild(actionsDiv);
    noteElement.appendChild(contentDiv);

    // Add event listeners for actions
    actionsDiv.querySelectorAll('button').forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            handleNoteAction(button.dataset.action, note.id, noteElement);
        });
    });

    return noteElement;
}

// Add this after renderPage function
function handleOutlineClick(event) {
    const target = event.target;
    const noteElement = target.closest('.outline-item');
    if (!noteElement) return;

    const noteId = noteElement.dataset.noteId;
    const action = target.dataset.action;

    switch (action) {
        case 'focus':
            toggleFocus(noteElement);
            break;
        case 'copy-block-id':
            const blockId = noteElement.querySelector('.outline-content').dataset.blockId;
            if (blockId) {
                navigator.clipboard.writeText(blockId).then(() => {
                    // Visual feedback
                    target.style.backgroundColor = 'var(--accent-color)';
                    target.style.color = 'white';
                    setTimeout(() => {
                        target.style.backgroundColor = '';
                        target.style.color = '';
                    }, 200);
                });
            }
            break;
        case 'add-child':
            createNote(noteId, parseInt(noteElement.dataset.level) + 1);
            break;
        case 'edit':
            editNote(noteId, noteElement.dataset.content);
            break;
        case 'upload':
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.style.display = 'none';
            document.body.appendChild(fileInput);
            
            fileInput.onchange = (e) => {
                if (e.target.files.length > 0) {
                    uploadFile(noteId, e.target.files[0]);
                }
                document.body.removeChild(fileInput);
            };
            
            fileInput.click();
            break;
        case 'delete':
            deleteNote(noteId);
            break;
    }
}

// Add back the createNote function
function createNote(parentId = null, level = 0) {
    if (!currentPage) return;

    const noteElement = document.createElement('div');
    noteElement.className = 'note-editor';
    noteElement.innerHTML = `
        <textarea class="note-textarea" placeholder="Enter note content... (Ctrl+Enter to save)"></textarea>
        <div class="note-editor-actions">
            <button class="btn-primary save-note">Save</button>
            <button class="btn-secondary cancel-note">Cancel</button>
            <div class="template-selector">
                <select>
                    ${Object.entries(noteTemplates).map(([key, _]) => 
                        `<option value="${key}">${key.charAt(0).toUpperCase() + key.slice(1)}</option>`
                    ).join('')}
                </select>
            </div>
        </div>
    `;

    const textarea = noteElement.querySelector('.note-textarea');
    const saveButton = noteElement.querySelector('.save-note');
    const cancelButton = noteElement.querySelector('.cancel-note');
    const templateSelect = noteElement.querySelector('select');

    if (parentId) {
        const parentNote = document.querySelector(`[data-note-id="${parentId}"]`);
        parentNote.appendChild(noteElement);
    } else {
        outlineContainer.appendChild(noteElement);
    }

    textarea.focus();

    // Add keyboard shortcut
    textarea.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            saveButton.click();
        }
        
        // Auto-close brackets and braces
        if (e.key === '{') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            textarea.value = text.substring(0, start) + '{}' + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;
        }
        if (e.key === '[') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            textarea.value = text.substring(0, start) + '[]' + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;
        }
    });

    // Add paste handler for images
    textarea.addEventListener('paste', async (e) => {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        
        for (const item of items) {
            if (item.type.indexOf('image') === 0) {
                e.preventDefault();
                
                const file = item.getAsFile();
                const formData = new FormData();
                formData.append('image', file);
                
                try {
                    const response = await fetch('api/image.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    
                    // Insert image markdown at cursor position
                    const cursorPos = textarea.selectionStart;
                    const textBefore = textarea.value.substring(0, cursorPos);
                    const textAfter = textarea.value.substring(cursorPos);
                    const imageMarkdown = `![${data.original_name}](uploads/${data.filename})`;
                    
                    textarea.value = textBefore + imageMarkdown + textAfter;
                    textarea.focus();
                    textarea.selectionStart = textarea.selectionEnd = cursorPos + imageMarkdown.length;
                } catch (error) {
                    console.error('Error uploading image:', error);
                }
            }
        }
    });

    // Add template change handler
    templateSelect.addEventListener('change', (e) => {
        const templateContent = noteTemplates[e.target.value] || '';
        const currentContent = textarea.value;
        
        if (currentContent && currentContent.trim()) {
            textarea.value = currentContent + '\n\n' + templateContent;
        } else {
            textarea.value = templateContent;
        }
        
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
    });

    saveButton.onclick = async () => {
        const content = textarea.value.trim();
        if (!content) {
            noteElement.remove();
            return;
        }

        // Extract properties from content
        const properties = {};
        const propertyRegex = /\{([^:]+)::([^}]+)\}/g;
        let match;
        while ((match = propertyRegex.exec(content)) !== null) {
            const [_, key, value] = match;
            properties[key.trim()] = value.trim();
        }

        try {
            const response = await fetch('api/note.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    page_id: currentPage.id,
                    content: content,
                    level: level,
                    parent_id: parentId,
                    properties: properties
                })
            });

            const data = await response.json();
            if (data.error) {
                console.error(data.error);
                return;
            }

            loadPage(currentPage.id);
        } catch (error) {
            console.error('Error creating note:', error);
        }
    };

    cancelButton.onclick = () => {
        noteElement.remove();
    };
}

// Add back the editNote function
function editNote(id, currentContent) {
    const noteElement = document.querySelector(`[data-note-id="${id}"]`);
    const contentElement = noteElement.querySelector('.outline-content');
    const originalContent = contentElement.innerHTML;

    const editorElement = document.createElement('div');
    editorElement.className = 'note-editor';
    editorElement.innerHTML = `
        <textarea class="note-textarea">${currentContent}</textarea>
        <div class="note-editor-actions">
            <button class="btn-primary save-note">Save</button>
            <button class="btn-secondary cancel-note">Cancel</button>
        </div>
    `;

    const textarea = editorElement.querySelector('.note-textarea');
    const saveButton = editorElement.querySelector('.save-note');
    const cancelButton = editorElement.querySelector('.cancel-note');

    contentElement.innerHTML = '';
    contentElement.appendChild(editorElement);
    textarea.focus();

    // Add keyboard shortcut
    textarea.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            saveButton.click();
        }
        
        // Auto-close brackets and braces
        if (e.key === '{') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            textarea.value = text.substring(0, start) + '{}' + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;
        }
        if (e.key === '[') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            textarea.value = text.substring(0, start) + '[]' + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;
        }
    });

    // Add paste handler for images
    textarea.addEventListener('paste', async (e) => {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        
        for (const item of items) {
            if (item.type.indexOf('image') === 0) {
                e.preventDefault();
                
                const file = item.getAsFile();
                const formData = new FormData();
                formData.append('image', file);
                
                try {
                    const response = await fetch('api/image.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    
                    // Insert image markdown at cursor position
                    const cursorPos = textarea.selectionStart;
                    const textBefore = textarea.value.substring(0, cursorPos);
                    const textAfter = textarea.value.substring(cursorPos);
                    const imageMarkdown = `![${data.original_name}](uploads/${data.filename})`;
                    
                    textarea.value = textBefore + imageMarkdown + textAfter;
                    textarea.focus();
                    textarea.selectionStart = textarea.selectionEnd = cursorPos + imageMarkdown.length;
                } catch (error) {
                    console.error('Error uploading image:', error);
                }
            }
        }
    });

    saveButton.onclick = async () => {
        const content = textarea.value.trim();
        if (!content) {
            contentElement.innerHTML = originalContent;
            return;
        }

        // Extract properties from content
        const properties = {};
        const propertyRegex = /\{([^:]+)::([^}]+)\}/g;
        let match;
        while ((match = propertyRegex.exec(content)) !== null) {
            const [_, key, value] = match;
            properties[key.trim()] = value.trim();
        }

        try {
            const response = await fetch(`api/note.php?id=${id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'update',
                    content: content,
                    properties: properties,
                    level: parseInt(noteElement.dataset.level) || 0,
                    parent_id: noteElement.dataset.parentId || null
                })
            });

            const data = await response.json();
            if (data.error) {
                console.error(data.error);
                return;
            }

            loadPage(currentPage.id);
        } catch (error) {
            console.error('Error updating note:', error);
        }
    };

    cancelButton.onclick = () => {
        contentElement.innerHTML = originalContent;
    };
}

// Add back the deleteNote function
async function deleteNote(id) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="delete-confirmation-modal">
            <h3>Delete Note</h3>
            <p>Are you sure you want to delete this note and all its children?</p>
            <div class="button-group">
                <button class="btn-secondary cancel-delete">Cancel</button>
                <button class="btn-primary confirm-delete">Delete</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Close modal when clicking outside
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            document.body.removeChild(modal);
        }
    });
    
    // Handle cancel
    modal.querySelector('.cancel-delete').onclick = () => {
        document.body.removeChild(modal);
    };
    
    // Handle confirm
    modal.querySelector('.confirm-delete').onclick = async () => {
        try {
            const response = await fetch(`api/note.php?id=${id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'delete'
                })
            });

            const data = await response.json();
            if (data.error) {
                console.error(data.error);
                return;
            }

            document.body.removeChild(modal);
            loadPage(currentPage.id);
        } catch (error) {
            console.error('Error deleting note:', error);
        }
    };
}

async function showSearchResults() {
    const results = JSON.parse(sessionStorage.getItem('searchResults') || '[]');
    const query = sessionStorage.getItem('searchQuery') || '';
    
    // Hide new note button and backlinks only on search results page
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';
    
    // Render all results content first
    const renderedResults = await Promise.all(results.map(async result => {
        const content = await renderNoteContent(result);
        return `
            <div class="search-result-item">
                <div class="result-header">
                    <a href="#${result.page_id}" onclick="event.preventDefault(); loadPage('${result.page_id}');">
                        ${result.page_title || result.page_id}
                    </a>
                    <span class="result-date">${new Date(result.created_at).toLocaleDateString()}</span>
                </div>
                <div class="result-content">${content}</div>
            </div>
        `;
    }));
    
    outlineContainer.innerHTML = `
        <div class="search-results-page">
            <div class="search-results-header">
                <h2>Advanced Search Results</h2>
                <div class="search-actions">
                    <button class="btn-secondary" onclick="copySearchLink()">Copy Search Link</button>
                    <button class="btn-secondary" onclick="loadPage(currentPage.id)">Back to Page</button>
                </div>
            </div>
            <div class="search-query">
                <strong>Query:</strong> <code>${query}</code>
            </div>
            ${renderedResults.join('')}
        </div>
    `;
    
    // Update page title
    pageTitle.innerHTML = '<span class="page-title-text">Search Results</span>';
    pageProperties.innerHTML = '';
}

// Add function to copy search link
function copySearchLink() {
    const query = sessionStorage.getItem('searchQuery') || '';
    const searchLink = `<<${query}>>`;
    navigator.clipboard.writeText(searchLink).then(() => {
        alert('Search link copied to clipboard!');
    });
}

// Add function to execute search from link
async function executeSearchLink(query) {
    try {
        const response = await fetch('api/advanced_search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const results = await response.json();
        if (results.error) {
            throw new Error(results.error);
        }
        
        // Store results and show search page
        sessionStorage.setItem('searchResults', JSON.stringify(results));
        sessionStorage.setItem('searchQuery', query);
        window.location.hash = 'search-results';
    } catch (error) {
        console.error('Error executing search link:', error);
        alert('Error executing search: ' + error.message);
    }
}

async function toggleTodo(blockId, isDone) { // Changed noteId to blockId
    try {
        // Fetch the current note data using the blockId (UUID)
        const currentNote = await findBlockById(blockId); // This now uses the UUID
        if (!currentNote) {
            // Updated error message for clarity
            console.error('Note not found for toggling TODO (by block_id):', blockId);
            alert('Error: Could not find the note to update.');
            return;
        }

        let rawContent = currentNote.content; 

        // Determine existing status and task text
        let taskTextWithProperties = "";
        if (rawContent.startsWith('TODO ')) {
            taskTextWithProperties = rawContent.substring(5);
        } else if (rawContent.startsWith('DONE ')) {
            taskTextWithProperties = rawContent.substring(5);
        } else {
            taskTextWithProperties = rawContent;
            console.warn('Toggling a note that does not start with TODO/DONE:', rawContent);
        }

        const taskSpecificProperties = {};
        let cleanTaskDescription = taskTextWithProperties.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
            taskSpecificProperties[key.trim()] = value.trim();
            return ''; 
        }).trim();

        let newContentString;
        const updatedNoteProperties = { ...(currentNote.properties || {}) }; 

        if (isDone) {
            newContentString = `DONE ${cleanTaskDescription}`;
            taskSpecificProperties['done-at'] = new Date().toISOString();
        } else {
            newContentString = `TODO ${cleanTaskDescription}`;
            delete taskSpecificProperties['done-at']; 
        }

        for (const [key, value] of Object.entries(taskSpecificProperties)) {
            newContentString += ` {${key}::${value}}`;
            updatedNoteProperties[key] = value; 
        }
        if (!isDone) {
            delete updatedNoteProperties['done-at'];
        }

        const response = await fetch(`api/note.php?id=${currentNote.id}`, { // Uses currentNote.id (PK)
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'update',
                content: newContentString.trim(),
                properties: updatedNoteProperties, 
                level: currentNote.level,          
                parent_id: currentNote.parent_id   
            })
        });

        const data = await response.json();
        if (data.error) {
            console.error('Error updating todo status (API):', data.error);
            alert('Error updating task: ' + data.error);
            const checkbox = document.querySelector(`[data-block-id="${blockId}"] input[type="checkbox"], [data-note-id="${currentNote.id}"] input[type="checkbox"]`); // Try both selectors
            if (checkbox) checkbox.checked = !isDone;
            return;
        }

        const checkbox = document.querySelector(`[data-block-id="${blockId}"] input[type="checkbox"], [data-note-id="${currentNote.id}"] input[type="checkbox"]`); // Try both selectors
        if (checkbox) {
            checkbox.checked = isDone; 
            checkbox.style.transform = 'scale(1.2)';
            setTimeout(() => {
                checkbox.style.transform = 'scale(1)';
            }, 200);
        }
        loadPage(currentPage.id);

    } catch (error) {
        console.error('Error updating todo status (JS):', error);
        alert('Error updating task: ' + error.message);
        const checkbox = document.querySelector(`[data-block-id="${blockId}"] input[type="checkbox"], [data-note-id="${currentNote.id}"] input[type="checkbox"]`); // Try both selectors
        if (checkbox) checkbox.checked = !isDone; 
    }
}

// Add focus toggle functionality
function toggleFocus(noteElement) {
    const isFocused = noteElement.classList.contains('focused');
    
    if (isFocused) {
        // If already focused, unfocus and reload the page
        loadPage(currentPage.id);
        return;
    }
    
    // Get the note's data
    const noteId = noteElement.dataset.noteId;
    const level = parseInt(noteElement.dataset.level);
    const content = noteElement.dataset.content;
    
    // Find all child notes
    let childNotes = [];
    let currentLevel = level;
    let nextElement = noteElement.nextElementSibling;
    
    while (nextElement && parseInt(nextElement.dataset.level) > currentLevel) {
        childNotes.push({
            id: nextElement.dataset.noteId,
            level: parseInt(nextElement.dataset.level) - level, // Adjust levels relative to parent
            content: nextElement.dataset.content,
            children: [] // Will be populated recursively
        });
        nextElement = nextElement.nextElementSibling;
    }
    
    // Create a new page structure with the focused note as root
    const focusedPage = {
        id: currentPage.id,
        title: currentPage.title,
        notes: [{
            id: noteId,
            level: 0, // Make it a top-level note
            content: content,
            children: childNotes
        }]
    };
    
    // Render the focused view
    renderFocusedView(focusedPage, noteElement);
}

// Add function to render focused view
async function renderFocusedView(page, originalNote) {
    // Add a back button to the page title
    pageTitle.innerHTML = `
        <button class="btn-secondary unfocus-button" onclick="loadPage(currentPage.id)">Back</button>
        <span class="page-title-text">${page.title}</span>
        <button class="edit-properties-button" title="Edit page properties"></button>
    `;
    
    // Clear the outline container
    outlineContainer.innerHTML = '';
    
    // Render the focused note and its children
    const renderedNotes = await renderOutline(page.notes);
    outlineContainer.innerHTML = renderedNotes;
    
    // Scroll the focused note into view
    const focusedNote = outlineContainer.querySelector('.outline-item');
    focusedNote?.scrollIntoView({ behavior: 'smooth', block: 'center' });
} 