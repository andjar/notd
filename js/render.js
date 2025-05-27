// Functions that generate HTML content
// (e.g., renderPage, renderOutline, renderNoteContent, renderRecentPages, renderBreadcrumbs)

/**
 * Renders the suggestions popup for page linking.
 * @param {Array<Object>} suggestions - Array of suggestion objects (e.g., {id: "page_id", title: "Page Title"}).
 * @param {HTMLTextAreaElement} textarea - The textarea element that triggered the suggestions.
 * Depends on functions from `ui.js`: `closeSuggestionsPopup`, `insertSuggestion`, `positionSuggestionsPopup`.
 * Depends on global state from `state.js`: `suggestionsPopup`, `currentSuggestions`.
 */
function renderSuggestions(suggestions, textarea) { 
    closeSuggestionsPopup(); // `closeSuggestionsPopup` from ui.js
    if (!suggestions || suggestions.length === 0) {
        return;
    }

    suggestionsPopup = document.createElement('div'); // `suggestionsPopup` from state.js
    suggestionsPopup.className = 'suggestions-popup';

    suggestions.forEach((suggestion, index) => {
        const item = document.createElement('div');
        item.className = 'suggestion-item';
        item.textContent = suggestion.title;
        item.dataset.id = suggestion.id; // Store id and title for insertion
        item.dataset.title = suggestion.title;

        // Event listener for clicking a suggestion
        item.addEventListener('mousedown', (event) => { 
            event.preventDefault(); // Prevent textarea from losing focus
            insertSuggestion(textarea, suggestion.title); // `insertSuggestion` from ui.js
            closeSuggestionsPopup(); // `closeSuggestionsPopup` from ui.js
        });
        suggestionsPopup.appendChild(item);
    });

    document.body.appendChild(suggestionsPopup);
    positionSuggestionsPopup(textarea); // `positionSuggestionsPopup` from ui.js
    currentSuggestions = suggestions; // `currentSuggestions` from state.js
}

/**
 * Preprocesses raw note content to replace TODO/DONE markers with temporary placeholders.
 * This allows markdown parsing without interference from the TODO/DONE keywords.
 * @param {string} content - The raw content of the note.
 * @returns {string} The content with TODO/DONE items replaced by placeholders (e.g., %%TODO_ITEM%%DONE%%Task text%%).
 */
function preprocessTodoItems(content) {
    // Regex to find TODO or DONE at the beginning of a line, followed by any text.
    // gm flags: g for global (all matches), m for multiline (^ matches start of line).
    return content.replace(/^(TODO|DONE)\s*(.*)/gm, (match, status, taskText) => {
        return `%%TODO_ITEM%%${status}%%${taskText}%%`; // Custom placeholder format
    });
}

/**
 * Renders the HTML content of a single note, including parsing markdown,
 * handling transclusions (block references), properties, links, and attachments.
 * @async
 * @param {Object} note - The note object to render. Expected properties: `content`, `id`, `block_id` (optional), `attachments` (optional), `properties` (optional).
 * @param {Object} [prefetchedBlocks={}] - A cache of prefetched block data for transclusions, `prefetchedBlocks` from state.js.
 * @returns {Promise<string>} A promise that resolves with the HTML string of the rendered note content.
 * Depends on `findBlockByIdAPI` (from api.js), `replacePropertyTags` (this file), `marked.parse` (global library),
 * `executeSearchLink` (app.js), `toggleTodo` (app.js/ui.js), `showImageModal` (ui.js), `formatFileSize` (utils.js), `deleteAttachment` (ui.js).
 */
async function renderNoteContent(note, prefetchedBlocks = {}) { 
    let processedContent = note.content || '';

    // Step 1: Preprocess TODO items to avoid markdown interference
    processedContent = preprocessTodoItems(processedContent);

    // Step 2: Handle transclusions (block references {{block-id}})
    const transclusionPlaceholders = []; // To store details of transclusions for later replacement
    // Regex to find all occurrences of {{block-id}}
    const blockMatches = processedContent.matchAll(/\{\{([^}]+)\}\}/g);

    for (const blockRefMatch of blockMatches) {
        const fullMatch = blockRefMatch[0]; // e.g., "{{some-block-id}}"
        const blockId = blockRefMatch[1].trim(); // e.g., "some-block-id"
        let renderedBlockContent = '';
        let sourcePageId = null;
        let sourcePageTitle = null;
        let blockDataForRecursiveCall = null;

        // Check if the block content is already prefetched
        if (prefetchedBlocks && prefetchedBlocks[blockId]) {
            const prefetchedData = prefetchedBlocks[blockId];
            sourcePageId = prefetchedData.page_id;
            sourcePageTitle = prefetchedData.page_title;
            blockDataForRecursiveCall = { // Prepare data for recursive call
                content: prefetchedData.content,
                id: prefetchedData.note_id, // Use note_id as the primary id for the block
                block_id: blockId,
                attachments: prefetchedData.attachments || [],
                properties: prefetchedData.properties || {}
            };
            // Recursively render the content of the transcluded block
            renderedBlockContent = await renderNoteContent(blockDataForRecursiveCall, prefetchedBlocks);
        } else {
            // If not prefetched, fetch the block data from the API
            const fallbackBlock = await findBlockByIdAPI(blockId); // `findBlockByIdAPI` from api.js
            if (fallbackBlock) {
                sourcePageId = fallbackBlock.page_id;
                sourcePageTitle = fallbackBlock.page_title;
                renderedBlockContent = await renderNoteContent(fallbackBlock, prefetchedBlocks); // Recursive call
            } else {
                // If block is not found, render a "not found" message
                renderedBlockContent = `<span class="block-ref-brackets">{{</span><a href="#" class="block-ref-link" onclick="event.stopPropagation(); console.warn('Block not found: ${blockId}')">${blockId}</a><span class="block-ref-brackets">}}</span><span class="broken-transclusion"> (not found)</span>`;
                // Directly replace in content if it's a broken link, skip placeholder logic for this one.
                processedContent = processedContent.replace(fullMatch, renderedBlockContent);
                continue; // Move to the next match
            }
        }

        // Replace the transclusion syntax with a unique placeholder for now.
        // This is done to avoid issues with markdown parsing of nested structures.
        const placeholderId = `%%TRANSCLUSION_PLACEHOLDER_${transclusionPlaceholders.length}%%`;
        transclusionPlaceholders.push({
            placeholder: placeholderId,
            html: renderedBlockContent,
            blockId: blockId,
            sourcePageId: sourcePageId,
            sourcePageTitle: sourcePageTitle
        });
        processedContent = processedContent.replace(fullMatch, placeholderId);
    }

    // Step 3: Replace property tags (e.g., {key::value}) with HTML spans or links
    processedContent = replacePropertyTags(processedContent); // `replacePropertyTags` is in this file

    // Step 4: Replace search links (e.g., <<query>>) with clickable links
    processedContent = processedContent.replace(/<<([^>]+)>>/g, (match, query) => {
        const displayQuery = query.length > 30 ? query.substring(0, 27) + '...' : query; // Truncate long queries
        // `executeSearchLink` is expected to be in app.js or ui.js for handling the search action
        return `<a href="#" class="search-link" onclick="event.preventDefault(); event.stopPropagation(); executeSearchLink('${query.replace(/'/g, "\\'")}')"><<${displayQuery}>></a>`;
    });

    // Step 5: Parse the main content with Markdown
    let htmlContent = marked.parse(processedContent); // `marked` is a global library

    // Step 6: Insert rendered transclusions back into the HTML
    transclusionPlaceholders.forEach(item => {
        const finalTransclusionWrapperHtml = `
            <div class="transcluded-block" data-block-id="${item.blockId}">
                ${item.html}
                <div class="transclusion-source">
                    <a href="#${encodeURIComponent(item.sourcePageId)}" onclick="event.stopPropagation();">
                        Source: ${item.sourcePageTitle || item.sourcePageId}</a>
                </div>
            </div>`;
        // Replace placeholders with the fully rendered transclusion HTML
        htmlContent = htmlContent.split(item.placeholder).join(finalTransclusionWrapperHtml);
    });

    // Step 7: Render internal page links (e.g., [[Page Name]]) and tag links (e.g., #tag or [[#tag]])
    htmlContent = htmlContent.replace(/\[\[([^\]#]+?)\]\]/g, (match, pageName) => { // Page links: [[Page Name]]
        return `<span class="internal-link-brackets">[[</span><a href="#${encodeURIComponent(pageName.trim())}" class="internal-link" onclick="event.stopPropagation();">${pageName.trim()}</a><span class="internal-link-brackets">]]</span>`;
    });
    htmlContent = htmlContent.replace(/\[\[#(.*?)\]\]/g, (match, tagName) => { // Tag links: [[#tag]]
        return `<span class="internal-link-brackets">[[</span><a href="#${encodeURIComponent(tagName.trim())}" class="property-tag" onclick="event.stopPropagation();">#${tagName.trim()}</a><span class="internal-link-brackets">]]</span>`;
    });
    htmlContent = htmlContent.replace(/(^|\s)#([a-zA-Z0-9_\-\/]+)/g, (match, precedingSpace, tagName) => { // Hashtags: #tag
        return `${precedingSpace}<a href="#${encodeURIComponent(tagName)}" class="property-tag" onclick="event.stopPropagation();">#${tagName}</a>`;
    });

    // Step 8: Replace TODO/DONE placeholders with interactive HTML elements
    htmlContent = htmlContent.replace(/%%TODO_ITEM%%(TODO|DONE)%%(.*?)%%/g, (match, status, taskTextHtml) => {
        const isDone = status === 'DONE';
        // `toggleTodo` is expected to be in app.js or ui.js for handling state changes and API calls
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

    // Step 9: Enhance image tags with a click handler to show a modal
    htmlContent = htmlContent.replace(/<img src="([^"]+)" alt="([^"]*)"/g, (match, src, alt) => {
        // `showImageModal` is expected to be in ui.js
        return `<img src="${src}" alt="${alt}" class="note-image" onclick="event.stopPropagation(); showImageModal(this.src, this.alt)">`;
    });

    // Step 10: Render attachments, if any
    if (note.attachments && note.attachments.length > 0) {
        htmlContent += '<div class="attachments">';
        note.attachments.forEach(attachment => {
            const fileExtension = attachment.filename.split('.').pop().toLowerCase();
            let icon = '📄'; // Default icon
            let previewHtml = '';
            // Determine icon and preview based on file type
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) {
                icon = '🖼️'; 
                previewHtml = `<img src="uploads/${attachment.filename}" class="attachment-image" alt="${attachment.original_name}" onclick="event.stopPropagation(); showImageModal(this.src, this.alt);">`;
            } else if (['mp4', 'webm', 'mov'].includes(fileExtension)) {
                icon = '🎥'; 
                previewHtml = `<div class="attachment-preview"><video src="uploads/${attachment.filename}" controls width="100%"></video></div>`;
            } else if (['mp3', 'wav', 'ogg'].includes(fileExtension)) {
                icon = '🎵'; 
                previewHtml = `<div class="attachment-preview"><audio src="uploads/${attachment.filename}" controls></audio></div>`;
            }
            // `deleteAttachment` is expected to be in ui.js (for modal) and app.js (for API call)
            // `formatFileSize` is from utils.js
            htmlContent += `<div class="attachment"><div class="attachment-info">
                        <span class="attachment-icon">${icon}</span>
                        <a href="uploads/${attachment.filename}" target="_blank" class="attachment-name" onclick="event.stopPropagation();">${attachment.original_name}</a>
                        <span class="attachment-size">${formatFileSize(attachment.size)}</span>
                        <div class="attachment-actions"><button onclick="event.stopPropagation(); deleteAttachment(${attachment.id}, event)" title="Delete attachment">×</button></div>
                    </div>${previewHtml}</div>`;
        });
        htmlContent += '</div>';
    }
    return htmlContent;
}

/**
 * Recursively renders an outline of notes (a hierarchical list of notes and their children).
 * @async
 * @param {Array<Object>} notes - An array of note objects to render.
 * @param {number} [level=0] - The current indentation level for rendering.
 * @param {Object} [prefetchedBlocks={}] - A cache of prefetched block data for transclusions.
 * @returns {Promise<string>} A promise that resolves with the HTML string representing the rendered outline.
 * Depends on `renderNoteContent` and `renderPropertiesInline` (this file).
 */
async function renderOutline(notes, level = 0, prefetchedBlocks = {}) { 
    if (!notes || notes.length === 0) return ''; // Base case for recursion or empty notes list

    let html = '';
    for (const note of notes) {
        const blockId = note.block_id; // Specific block ID, if available
        // Render the individual note's content (which handles markdown, transclusions, etc.)
        const contentHtml = await renderNoteContent(note, prefetchedBlocks); 
        let linkedPageType = null; // This was in original code, seems unused currently.

        const hasChildren = note.children && note.children.length > 0;
        const hasChildrenClass = hasChildren ? 'has-children' : '';
        const linkedPageTypeClass = linkedPageType ? `linked-page-${linkedPageType.toLowerCase()}` : '';

        // Format creation date and time for display
        const createdAt = new Date(note.created_at);
        const displayDate = createdAt.toLocaleDateString();
        const displayTime = createdAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        // HTML for note controls (bullet, toggle arrow)
        const controlsHtml = `
            <span class="static-bullet" data-action="zoom-in" title="Zoom in"></span>
            ${hasChildren ? 
                `<span class="hover-arrow-toggle" data-action="toggle-children" title="Toggle children">
                     <svg class="arrow-svg" viewBox="0 0 192 512"><path d="M0 384.662V127.338c0-17.818 21.543-26.741 34.142-14.142l128.662 128.662c7.81 7.81 7.81 20.474 0 28.284L34.142 398.804C21.543 411.404 0 402.48 0 384.662z"></path></svg>
                 </span>` : '' }`;

        // Main HTML structure for a single outline item
        const starClass = note.is_favorite ? 'active' : '';
        console.log('Initial render - Note ID:', note.id, 'is_favorite:', note.is_favorite, 'Has "active" class in HTML:', (note.is_favorite ? 'yes' : 'no'));
        const starButtonHtml = `<button class="favorite-star ${starClass}" data-action="toggle-favorite" title="Toggle favorite">★</button>`;
        // console.log('Rendered star HTML for note ' + note.id + ':', starButtonHtml); // Optional: Log the full star HTML

        html += `
            <div class="outline-item ${linkedPageTypeClass} ${hasChildrenClass}"
                 data-note-id="${note.id}" 
                 data-level="${level}" 
                 data-content="${note.content.replace(/"/g, '&quot;')}">
                <div class="outline-content" ${blockId ? `data-block-id="${blockId}"` : ''}>
                    ${controlsHtml}
                    ${contentHtml}
                    ${note.properties && Object.keys(note.properties).length > 0 ? renderPropertiesInline(note.properties) : ''}
                    <div class="note-actions">
                        <button data-action="add-child" title="Add child note">+</button>
                        ${blockId ? `<button data-action="copy-block-id" title="Copy block ID">#</button>` : ''}
                        ${starButtonHtml}
                        <button data-action="edit" title="Edit note">✎</button>
                        <button data-action="indent-note" title="Indent note (make child of item above)">→</button>
                        <button data-action="upload" title="Upload file">🗎</button>
                        <button data-action="delete" title="Delete note">×</button>
                        ︱<span class="note-date" title="Created: ${createdAt.toLocaleString()}">${displayDate} ${displayTime}</span>
                    </div>
                </div>`;

        // If the note has children, recursively render them within a nested container
        if (hasChildren) {
            html += `<div class="outline-children">`;
            html += await renderOutline(note.children, level + 1, prefetchedBlocks); // Recursive call for children
            html += `</div>`;
        }
        html += `</div>`;
    }
    return html;
}

// This function was empty in the original code.
/**
 * Renders note properties inline. (Currently a placeholder).
 * @param {Object} properties - The properties object of the note.
 * @returns {string} HTML string for inline properties. Returns empty string for now.
 */
function renderPropertiesInline(properties) { return ''; } // Placeholder, was empty.

/**
 * Renders the main page structure including title, properties, and the note outline.
 * @async
 * @param {Object} page - The page data object. Expected properties: `title`, `properties`, `notes`, `id`.
 * Depends on DOM elements: `pageTitle`, `pageProperties`, `outlineContainer` (from ui.js).
 * Depends on functions: `editPageProperties` (ui.js), `renderOutline` (this file), 
 * `initSortable` (app.js/ui.js), `renderBacklinks` (this file).
 * Assumes `prefetchedBlocks` (from state.js) is populated by the calling function (e.g., `loadPage` in app.js).
 */
async function renderPage(page) { 
    // Set page title and edit properties button
    pageTitle.innerHTML = `<span class="page-title-text">${page.title}</span>
                           <button class="edit-properties-button" title="Edit page properties"></button>`;
    
    // Display page properties if they exist
    if (page.properties && Object.keys(page.properties).length > 0) {
        const propertiesHtml = Object.entries(page.properties)
            .map(([key, value]) => `<div class="page-property"><span class="property-key">${key}:</span><span class="property-value">${value}</span></div>`).join('');
        pageProperties.innerHTML = `<div class="page-properties-content">${propertiesHtml}</div>`;
        pageProperties.style.display = 'block';
    } else {
        pageProperties.innerHTML = '';
        pageProperties.style.display = 'none';
    }
    // Attach event listener to the edit properties button
    const editButtonInPageTitle = pageTitle.querySelector('.edit-properties-button'); 
    if (editButtonInPageTitle) editButtonInPageTitle.onclick = editPageProperties; // `editPageProperties` is expected in ui.js

    // Render the main note outline
    // `prefetchedBlocks` is assumed to be available globally (from state.js) and populated by `loadPage`.
    outlineContainer.innerHTML = await renderOutline(page.notes, 0, prefetchedBlocks); 
    
    // Initialize sortable functionality for the outline
    initSortable(outlineContainer); // `initSortable` is expected in app.js or ui.js

    // Render backlinks
    const backlinksContainer = document.getElementById('backlinks-container'); 
    if (backlinksContainer) {
        // `renderBacklinks` (this file) fetches its own data via `fetchBacklinksAPI`.
        renderBacklinks(page.id).then(html => { backlinksContainer.innerHTML = html; });
    }
}

/**
 * Fetches and renders backlinks for a given page.
 * @async
 * @param {string} pageId - The ID of the page for which to render backlinks.
 * @returns {Promise<string>} A promise that resolves with the HTML string for backlinks, or an error message.
 * Depends on `fetchBacklinksAPI` (api.js) and `renderOutline` (this file).
 * Uses global state `prefetchedBlocks` (state.js).
 */
async function renderBacklinks(pageId) { 
    try {
        const threads = await fetchBacklinksAPI(pageId); // API call

        if (!threads || threads.length === 0) return '<h3>Backlinks</h3><p>No backlinks found</p>';
        
        let html = '<h3>Backlinks</h3><div class="backlinks-list">';
        
        // Helper function to structure notes hierarchically for rendering with renderOutline
        const buildNoteTree = (notesList) => { 
            if (!notesList || notesList.length === 0) return [];
            const noteMap = {};
            notesList.forEach(note => { noteMap[note.id] = { ...note, children: [] }; });
            const rootNotes = [];
            notesList.forEach(noteData => {
                const currentMappedNote = noteMap[noteData.id];
                if (noteData.parent_id && noteMap[noteData.parent_id]) {
                    noteMap[noteData.parent_id].children.push(currentMappedNote);
                } else { 
                    rootNotes.push(currentMappedNote); 
                }
            });
            return rootNotes;
        };

        for (const thread of threads) {
            const hierarchicalNotes = buildNoteTree(thread.notes);
            // Render the content of each backlink thread using the existing outline renderer
            const threadContentHtml = hierarchicalNotes.length > 0 
                ? await renderOutline(hierarchicalNotes, 0, prefetchedBlocks) // `prefetchedBlocks` from state.js
                : ''; 
            html += `<div class="backlink-thread-item">
                        <a href="#${encodeURIComponent(thread.linking_page_id)}" onclick="event.stopPropagation();">
                            ${thread.linking_page_title || thread.linking_page_id}</a>
                        <div class="backlink-thread-content">${threadContentHtml}</div>
                    </div>`;
        }
        html += '</div>'; 
        return html;
    } catch (error) {
        console.error('Error loading backlinks:', error);
        return `<h3>Backlinks</h3><p>Error loading backlinks: ${error.message}</p>`; // Return error message as HTML
    }
}

/**
 * Renders the list of recent pages in the UI.
 * Depends on global DOM element `recentPagesList` (ui.js) and global state `recentPages` (state.js).
 * Depends on `navigateToPage` (app.js) and `showAllPages` (ui.js).
 */
function renderRecentPages() {
    if (!recentPagesList) return; // Ensure the target element exists

    // Generate HTML for each recent page item
    const recentPagesHtml = recentPages // `recentPages` from state.js
        .map(page => 
            `<li onclick="navigateToPage('${page.page_id.replace(/'/g, "\\'")}')">
                ${page.title || decodeURIComponent(page.page_id)}
                <small>${new Date(page.last_opened).toLocaleDateString()}</small>
            </li>`
        ).join('');
    
    // Populate the recent pages list container
    recentPagesList.innerHTML = `
        <div class="recent-pages-header">
            <h3>Recent Pages</h3>
            <a href="#" class="all-pages-link" onclick="event.preventDefault(); showAllPages();">All pages</a>
        </div>
        <ul>${recentPagesHtml}</ul>`;
    // `navigateToPage` is from app.js; `showAllPages` is from ui.js.
}

/**
 * Renders search results directly below the search input (inline search).
 * @param {Array<Object>} results - Array of search result objects. Each object should have `page_id`, `page_title`, and `content`.
 * Depends on global DOM element `searchInput` (ui.js).
 * Depends on `navigateToPage` (app.js).
 */
function renderSearchResults(results) { 
    // Get or create the container for inline search results
    const searchResultsContainer = document.getElementById('search-results-container') || document.createElement('div');
    searchResultsContainer.id = 'search-results-container';
    searchResultsContainer.className = 'search-results-inline';

    if (results.length === 0) {
        searchResultsContainer.innerHTML = '<p>No results found.</p>';
    } else {
        // Generate HTML for each search result item
        searchResultsContainer.innerHTML = results.map(result => `
            <div class="search-result-inline-item" 
                 onclick="navigateToPage('${result.page_id}'); searchInput.value=''; this.parentElement.remove();">
                <strong>${result.page_title || result.page_id}</strong>: 
                ${result.content.substring(0, 100)}...
            </div>
        `).join('');
        // `navigateToPage` is from app.js.
    }

    // Add the results container to the DOM if it's not already there
    if (!document.getElementById('search-results-container')) {
        searchInput.parentNode.insertBefore(searchResultsContainer, searchInput.nextSibling);
    }

    // Add a click listener to the document to clear inline search results when clicking outside
    // This is a one-time listener that removes itself.
     document.addEventListener('click', function clearInlineSearch(event) { 
        // Do nothing if the click is within the search input or results container
        if (searchResultsContainer.contains(event.target) || searchInput.contains(event.target)) return;
        
        // If clicked outside, remove the results and the listener
        searchResultsContainer.remove();
        document.removeEventListener('click', clearInlineSearch);
    }, { capture: true }); // Use capture to catch clicks early
}

/**
 * Renders the dedicated search results page.
 * @async
 * Depends on `sessionStorage` for search results and query.
 * Depends on global DOM elements `outlineContainer`, `pageTitle`, `pageProperties`, etc. (ui.js).
 * Calls `renderNoteContent` (this file) and `copySearchLink` (ui.js).
 * Uses global state `prefetchedBlocks` (state.js).
 */
async function showSearchResults() { 
    // Retrieve search results and query from session storage
    const results = JSON.parse(sessionStorage.getItem('searchResults') || '[]'); 
    const query = sessionStorage.getItem('searchQuery') || ''; 

    // Hide elements not relevant to the search results page
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';
    pageProperties.style.display = 'none'; // `pageProperties` from ui.js

    // Render each search result item asynchronously
    const renderedResultsPromises = results.map(async result => {
        // `renderNoteContent` is used to display the context of the search match
        const content = await renderNoteContent(result, prefetchedBlocks); // `prefetchedBlocks` from state.js
        return `
            <div class="search-result-item">
                <div class="result-header">
                    <a href="#${encodeURIComponent(result.page_id)}" onclick="event.stopPropagation();">
                        ${result.page_title || result.page_id}
                    </a>
                    <span class="result-date">${new Date(result.created_at).toLocaleDateString()}</span>
                </div>
                <div class="result-content">${content}</div>
            </div>`;
    });
    const renderedResultsHtml = (await Promise.all(renderedResultsPromises)).join('');

    // Populate the outline container with the search results page structure
    outlineContainer.innerHTML = `
        <div class="search-results-page">
            <div class="search-results-header">
                <h2>Advanced Search Results</h2>
                <div class="search-actions">
                    <button class="btn-secondary" onclick="copySearchLink()">Copy Search Link</button>
                    <button class="btn-secondary" onclick="window.history.back()">Back</button>
                </div>
            </div>
            <div class="search-query" style="margin-bottom:1em; font-size:0.9em;">
                <strong>Query:</strong> <code>${query}</code>
            </div>
            ${renderedResultsHtml}
        </div>`;
    // `copySearchLink` is from ui.js.
    
    pageTitle.innerHTML = '<span class="page-title-text">Search Results</span>'; // `pageTitle` from ui.js
}

/**
 * Replaces property tags (e.g., {key::value} or {tag::value1,value2}) in content
 * with appropriate HTML spans or links for display.
 * @param {string} content - The content string to process.
 * @returns {string} The content with property tags replaced by HTML.
 */
function replacePropertyTags(content) {
    // Regex to find {key::value} patterns
    return content.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
        const trimmedKey = key.trim().toLowerCase();
        const trimmedValue = value.trim();
        // Special handling for 'tag' property to create clickable tag links
        if (trimmedKey === 'tag') {
            return trimmedValue.split(',') // Tags can be comma-separated
                .map(tagValue => 
                    `<a href="#${encodeURIComponent(tagValue.trim())}" class="property-tag" onclick="event.stopPropagation();">#${tagValue.trim()}</a>`
                ).join(' '); // Join multiple tags with a space
        }
        // For other properties, render as simple text spans
        return `<span class="property-tag">${key.trim()}: ${trimmedValue}</span>`;
    });
}

/**
 * Renders breadcrumbs for navigation when in a zoomed-in (focused) view.
 * @param {Array<Object>} path - An array of note objects representing the path from the page root to the focused note.
 * @returns {string} HTML string for the breadcrumbs bar.
 * Depends on global state `currentPage` (from state.js).
 */
function renderBreadcrumbs(path) { 
    if (!path || path.length === 0 || !currentPage) return ''; // `currentPage` from state.js

    let breadcrumbHtml = '<div class="breadcrumb-bar" data-action="zoom-out" title="Click to zoom out">';

    // First breadcrumb is always the current page title, clicking it zooms out completely.
    breadcrumbHtml += `<span class="breadcrumb-item page-link" data-action="zoom-out">${currentPage.title}</span>`;

    // Add breadcrumbs for each note in the path to the focused item
    path.forEach((note, index) => {
        // Create a short snippet of the note's content for the breadcrumb text
        let plainContent = note.content.replace(/<\/?[^>]+(>|$)/g, " ").replace(/\s+/g, ' ').trim(); // Strip HTML tags
        const contentSnippet = plainContent.length > 30 
            ? plainContent.substring(0, 27) + '...' 
            : (plainContent || 'Untitled Note'); // Fallback for empty content

        const isLast = index === path.length - 1; // Is this the currently focused note?
        breadcrumbHtml += `<span class="breadcrumb-separator">»</span>`;
        if (isLast) {
            // The last item is the current focus, not clickable to zoom further in via breadcrumb.
            breadcrumbHtml += `<span class="breadcrumb-item current-focus">${contentSnippet}</span>`;
        } else {
            // Intermediate items are clickable to zoom into that specific note level.
            breadcrumbHtml += `<span class="breadcrumb-item" data-action="zoom-in" data-note-id="${note.id}">${contentSnippet}</span>`;
        }
    });

    breadcrumbHtml += '</div>';
    return breadcrumbHtml;
}

// --- Placeholder comments for dependencies (managed by loading order in HTML for now) ---

// Global DOM Elements (expected to be available via constants in ui.js or passed if this were a class/module)
/*
- pageTitle (from ui.js)
- pageProperties (from ui.js)
- outlineContainer (from ui.js)
- recentPagesList (from ui.js)
- searchInput (from ui.js, used by renderSearchResults)
*/

// Global state variables (expected to be available from state.js)
/*
- suggestionsPopup (state.js, used by renderSuggestions)
- currentSuggestions (state.js, used by renderSuggestions)
- prefetchedBlocks (state.js, used by renderNoteContent, renderOutline, renderBacklinks, showSearchResults)
- recentPages (state.js, used by renderRecentPages)
- currentPage (state.js, used by renderBreadcrumbs)
*/

// Functions from other modules called by functions in this file:
/*
- closeSuggestionsPopup (from ui.js)
- insertSuggestion (from ui.js)
- positionSuggestionsPopup (from ui.js)
- findBlockByIdAPI (from api.js)
- executeSearchLink (from app.js - handles the action)
- toggleTodo (from app.js - handles the action)
- showImageModal (from ui.js)
- formatFileSize (from utils.js)
- deleteAttachment (from ui.js - handles modal, app.js for API)
- editPageProperties (from ui.js - handles modal, app.js for API)
- initSortable (from app.js or ui.js - handles SortableJS setup)
- navigateToPage (from app.js - handles navigation)
- showAllPages (from ui.js - handles modal display)
- copySearchLink (from ui.js - handles clipboard copy)
- fetchBacklinksAPI (from api.js)
*/

function renderNote(note, level = 0, parentId = null) {
    const noteElement = document.createElement('div');
    noteElement.className = 'outline-item';
    noteElement.dataset.noteId = note.id;
    noteElement.dataset.level = level;
    noteElement.dataset.content = note.content; // Ensure raw content is stored
    // Add is_favorite to dataset if needed for other JS, though classList.toggle is primary for UI
    if (note.is_favorite) {
        noteElement.dataset.isFavorite = "true";
    }


    const contentDiv = document.createElement('div');
    contentDiv.className = 'outline-content';

    // Add bullet and arrow toggle
    const bullet = document.createElement('div');
    bullet.className = 'static-bullet';
    contentDiv.appendChild(bullet);

    if (note.children && note.children.length > 0) {
        noteElement.classList.add('has-children');
        const arrowToggle = document.createElement('div');
        arrowToggle.className = 'hover-arrow-toggle';
        arrowToggle.innerHTML = '<svg class="arrow-svg" viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>';
        contentDiv.appendChild(arrowToggle);
    }

    // Render note content
    const content = document.createElement('div');
    content.innerHTML = marked.parse(note.content);
    contentDiv.appendChild(content);

    // Add note actions
    const actions = document.createElement('div');
    actions.className = 'note-actions';
    
    // Add favorite star
    const starButton = document.createElement('button');
    // Use note.is_favorite (boolean expected from API)
    console.log('Initial render (renderNote) - Note ID:', note.id, 'is_favorite:', note.is_favorite, 'Has "active" class:', (note.is_favorite ? 'yes' : 'no'));
    starButton.className = 'favorite-star' + (note.is_favorite ? ' active' : ''); 
    starButton.innerHTML = '★';
    starButton.onclick = (e) => {
        e.stopPropagation();
        toggleFavorite(note.id, starButton);
    };
    actions.appendChild(starButton);

    // Add other action buttons
    const editButton = document.createElement('button');
    editButton.innerHTML = '✎';
    editButton.onclick = () => editNote(note.id, note.content);
    actions.appendChild(editButton);

    const deleteButton = document.createElement('button');
    deleteButton.innerHTML = '×';
    deleteButton.onclick = () => deleteNote(note.id);
    actions.appendChild(deleteButton);

    contentDiv.appendChild(actions);
    noteElement.appendChild(contentDiv);

    // Render children if any
    if (note.children && note.children.length > 0) {
        const childrenContainer = document.createElement('div');
        childrenContainer.className = 'outline-children';
        note.children.forEach(child => {
            childrenContainer.appendChild(renderNote(child, level + 1, note.id));
        });
        noteElement.appendChild(childrenContainer);
    }

    return noteElement;
}
