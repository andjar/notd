/**
 * @file UI module for handling all direct DOM manipulations.
 * This module exports a single `ui` object that contains all UI-related functions.
 */

// Import note-related functions that were split into separate modules
import { displayNotes, addNoteElement, removeNoteElement, buildNoteTree, initializeDragAndDrop, handleNoteDrop } from './ui/note-elements.js';
import { renderNote, parseAndRenderContent, switchToEditMode, getRawTextWithNewlines, normalizeNewlines, renderAttachments, renderProperties, initializeDelegatedNoteEventListeners, renderTransclusion } from './ui/note-renderer.js';
import { domRefs } from './ui/dom-refs.js';
import { calendarWidget } from './ui/calendar-widget.js';

// Get Alpine store reference
function getAppStore() {
    return window.Alpine.store('app');
}

/**
 * Updates the entire page title block, including breadcrumbs and settings gear.
 * @param {string} pageName - The full name of the page, which may include namespaces.
 */
function updatePageTitle(pageName) {
    document.title = `${pageName} - notd`;
    if (!domRefs.pageTitleContainer) return;

    // Create the page title element
    const pageTitle = document.createElement('h1');
    pageTitle.id = 'page-title';
    pageTitle.className = 'page-title';
    
    // Clear the container and append the title element
    domRefs.pageTitleContainer.innerHTML = '';
    domRefs.pageTitleContainer.appendChild(pageTitle);

    // Create a span for the title content
    const titleContent = document.createElement('span');
    titleContent.className = 'page-title-content';
    pageTitle.appendChild(titleContent);

    // Add the page name parts with links for namespaces
    const pageNameParts = pageName.split('/');
    let currentPath = '';

    pageNameParts.forEach((part, index) => {
        if (index > 0) {
            titleContent.appendChild(document.createTextNode(' / '));
        }
        currentPath += (index > 0 ? '/' : '') + part;

        if (index < pageNameParts.length - 1) {
            const link = document.createElement('a');
            link.href = '#';
            link.textContent = part;
            link.dataset.pageName = currentPath;
            titleContent.appendChild(link);
        } else {
            titleContent.appendChild(document.createTextNode(part));
        }
    });
    
    // Add the gear icon to the titleContent span instead of pageTitle
    const gearIcon = document.createElement('i');
    gearIcon.dataset.feather = 'settings';
    gearIcon.id = 'page-properties-gear';
    gearIcon.className = 'page-title-gear';
    gearIcon.title = 'Page Properties';
    gearIcon.style.display = 'inline-block';
    gearIcon.style.marginLeft = '8px';
    gearIcon.style.cursor = 'pointer';
    titleContent.appendChild(gearIcon); // Changed from pageTitle to titleContent

    // Use traditional feather replacement for this specific element
    if (typeof feather !== 'undefined' && feather.replace) {
        setTimeout(() => {
            try {
                feather.replace();
            } catch (error) {
                console.warn('Feather icon replacement failed for page title:', error);
            }
        }, 0);
    }
}

/**
 * Updates the page list in the sidebar
 * @param {Array} pages - Array of page objects
 * @param {string} activePageName - Currently active page
 */
function updatePageList(pages, activePageName) {
    if (!domRefs.pageListContainer) return;
    domRefs.pageListContainer.innerHTML = '';

    if (!pages || !Array.isArray(pages) || pages.length === 0) {
        domRefs.pageListContainer.innerHTML = '<div class="no-pages-message">No recent pages</div>';
        return;
    }

    // Sort pages by last updated
    pages.sort((a, b) => {
        if (a.updated_at > b.updated_at) return -1;
        if (a.updated_at < b.updated_at) return 1;
        return a.name.localeCompare(b.name);
    });

    // Take only the most recent pages
    const recentPages = pages.slice(0, 7);

    // Create a list container
    const listContainer = document.createElement('ul');
    listContainer.className = 'recent-pages-list';

   recentPages.forEach((page) => {
    const listItem = document.createElement("li");
    const link = document.createElement("a");
    link.href = "#";
    link.dataset.pageName = page.name;
    link.className = "recent-page-link";

    // Add active class if this is the current page
    if (page.name === activePageName) {
      link.classList.add("active");
    }

    // Create the link content with icon and name
    const icon = document.createElement("i");
    icon.dataset.feather = "file-text";
    icon.className = "recent-page-icon";

    const nameSpan = document.createElement("span");
    nameSpan.className = "recent-page-name";
    nameSpan.textContent = page.name;

    link.appendChild(icon);
    link.appendChild(nameSpan);
    listItem.appendChild(link);
    listContainer.appendChild(listItem);
    // ✅ Add click handler here!
    link.addEventListener("click", (event) => {
      event.preventDefault();
      const pageName = event.currentTarget.dataset.pageName;

      // Load page when recent page is clicked

      updateActivePageLink(pageName); // Highlight the clicked link
      // Load page content
    });
  });

    domRefs.pageListContainer.appendChild(listContainer);
}

/**
 * Updates which link in the sidebar is marked as active
 * @param {string} pageName - The name of the page to mark as active
 */
function updateActivePageLink(pageName) {
    if (!domRefs.pageListContainer) return;
    const links = domRefs.pageListContainer.querySelectorAll('a');
    links.forEach(link => {
        if (link.dataset.pageName === pageName) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

/**
 * Renders page properties as inline "pills" directly on the page.
 * @param {Object} properties - The page's properties object.
 * @param {HTMLElement} targetContainer - The HTML element to render properties into.
 */
function renderPageInlineProperties(properties, targetContainer) {
    if (!targetContainer) return;
    targetContainer.innerHTML = '';
    targetContainer.style.display = 'none';

    if (!properties || Object.keys(properties).length === 0) return;

    const fragment = document.createDocumentFragment();
    let hasVisibleProperties = false;
    const RENDER_INTERNAL = window.APP_CONFIG?.RENDER_INTERNAL_PROPERTIES ?? false;

    Object.entries(properties).forEach(([key, instances]) => {
        if (!Array.isArray(instances)) return;
        instances.forEach(instance => {
            if (instance.internal && !RENDER_INTERNAL) return;

            hasVisibleProperties = true;
            const propItem = document.createElement('span');
            propItem.className = 'property-inline';

            if (key === 'favorite' && String(instance.value).toLowerCase() === 'true') {
                propItem.innerHTML = `<span class="property-favorite">⭐</span>`;
            } else {
                propItem.innerHTML = `<span class="property-key">${key}:</span> <span class="property-value">${instance.value}</span>`;
            }
            fragment.appendChild(propItem);
        });
    });

    if (hasVisibleProperties) {
        targetContainer.appendChild(fragment);
        targetContainer.style.display = 'flex';
    }
}

/**
 * Initializes the page properties modal and its event listeners
 */
function initPagePropertiesModal() {
    const modal = domRefs.pagePropertiesModal;
    if (!modal) return;

    const showModal = async () => {
        if (!window.currentPageId || !window.pagesAPI) return;
        try {
            const pageData = await window.pagesAPI.getPageById(window.currentPageId);
            if (window.displayPageProperties) {
                await window.displayPageProperties(pageData.properties || {});
                modal.classList.add('active');
            }
        } catch (error) {
            console.error("Error fetching page properties for modal:", error);
            alert("Error loading page properties.");
        }
    };

    const hideModal = () => modal.classList.remove('active');
    
    document.addEventListener('click', (e) => {
        if (e.target.closest('#page-properties-gear')) {
            showModal();
        }
    });

    domRefs.pagePropertiesModalClose?.addEventListener('click', hideModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) hideModal(); });
}

/**
 * Hides the page properties modal.
 */
export function hidePagePropertiesModal() {
    const modal = domRefs.pagePropertiesModal;
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Shows the encryption password modal and returns a Promise that resolves with the password.
 * @returns {Promise<string>} A promise that resolves with the entered password, or rejects if cancelled.
 */
export function promptForEncryptionPassword() {
    return new Promise((resolve, reject) => {
        const modal = domRefs.encryptionPasswordModal;
        const newPasswordInput = domRefs.newEncryptionPasswordInput;
        const confirmPasswordInput = domRefs.confirmEncryptionPasswordInput;
        const errorMessageElement = domRefs.encryptionPasswordError;
        const confirmBtn = domRefs.confirmEncryptionBtn;
        const cancelBtn = domRefs.cancelEncryptionBtn;
        const closeBtn = domRefs.encryptionModalClose;

        if (!modal || !newPasswordInput || !confirmPasswordInput || !errorMessageElement || !confirmBtn || !cancelBtn || !closeBtn) {
            console.error('Encryption password modal elements not found.');
            return reject(new Error('Encryption password modal elements missing.'));
        }

        // Clear previous inputs and errors
        newPasswordInput.value = '';
        confirmPasswordInput.value = '';
        errorMessageElement.textContent = '';
        errorMessageElement.style.display = 'none';

        modal.classList.add('active');
        newPasswordInput.focus();

        const cleanup = () => {
            confirmBtn.removeEventListener('click', handleSubmit);
            cancelBtn.removeEventListener('click', handleCancel);
            closeBtn.removeEventListener('click', handleCancel);
            newPasswordInput.removeEventListener('keydown', handleKeydown);
            confirmPasswordInput.removeEventListener('keydown', handleKeydown);
            modal.classList.remove('active');
        };

        const handleSubmit = () => {
            const newPassword = newPasswordInput.value;
            const confirmedPassword = confirmPasswordInput.value;

            if (!newPassword || newPassword.trim() === '') {
                errorMessageElement.textContent = 'Password cannot be empty.';
                errorMessageElement.style.display = 'block';
                return;
            }
            if (newPassword !== confirmedPassword) {
                errorMessageElement.textContent = 'Passwords do not match. Please try again.';
                errorMessageElement.style.display = 'block';
                return;
            }

            cleanup();
            resolve(newPassword);
        };

        const handleCancel = () => {
            cleanup();
            reject(new Error('Encryption cancelled by user.'));
        };

        const handleKeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleSubmit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                handleCancel();
            }
        };

        confirmBtn.addEventListener('click', handleSubmit);
        cancelBtn.addEventListener('click', handleCancel);
        closeBtn.addEventListener('click', handleCancel);
        newPasswordInput.addEventListener('keydown', handleKeydown);
        confirmPasswordInput.addEventListener('keydown', handleKeydown);

        modal.addEventListener('click', (e) => { // Close if backdrop is clicked
            if (e.target === modal) handleCancel();
        });
    });
}



/**
 * Updates the visual save status indicator.
 * @param {string} newStatus - The new status ('saved', 'pending', 'error').
 */
function updateSaveStatusIndicator(newStatus) {
    const indicator = document.getElementById('save-status-indicator');
    if (!indicator) return;

    const appStore = getAppStore();
    appStore.setSaveStatus(newStatus);
    indicator.className = 'save-status-indicator';
    indicator.classList.add(`status-${newStatus}`);

    let iconHtml = '';
    switch (newStatus) {
        case 'saved':
            iconHtml = '<i data-feather="check-circle"></i>';
            indicator.title = 'All changes saved';
            break;
        case 'pending':
            iconHtml = '<div class="dot-spinner"><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div></div>';
            indicator.title = 'Saving...';
            break;
        case 'error':
            iconHtml = '<i data-feather="alert-triangle"></i>';
            indicator.title = 'Error saving changes';
            break;
    }
    indicator.innerHTML = iconHtml;
    
    // Use traditional feather replacement for icons
    if (newStatus !== 'pending' && typeof feather !== 'undefined' && feather.replace) {
        setTimeout(() => {
            try {
                feather.replace();
            } catch (error) {
                console.warn('Feather icon replacement failed in save status indicator:', error);
            }
        }, 0);
    }
}

function renderBreadcrumbs(focusedNoteId, allNotesOnPage, currentPageName) {
    if (!domRefs.noteFocusBreadcrumbsContainer || !currentPageName) return;

    let breadcrumbLinksHtml = `<a href="#" onclick="ui.showAllNotesAndLoadPage('${currentPageName}'); return false;">${currentPageName}</a>`;

    if (focusedNoteId) {
        const ancestors = getNoteAncestors(focusedNoteId, allNotesOnPage);
        ancestors.forEach(ancestor => {
            const noteName = (ancestor.content || `Note ${ancestor.id}`).split('\n')[0].substring(0, 30);
            breadcrumbLinksHtml += ` > <a href="#" onclick="ui.focusOnNote('${ancestor.id}'); return false;">${noteName}</a>`;
        });
    }
    domRefs.noteFocusBreadcrumbsContainer.innerHTML = breadcrumbLinksHtml;
    domRefs.noteFocusBreadcrumbsContainer.style.display = 'block';
}

function focusOnNote(noteId) {
    window.currentFocusedNoteId = noteId;
    const allNoteElements = document.querySelectorAll('.note-item');
    allNoteElements.forEach(el => el.classList.add('note-hidden'));
    
    const elementsToShow = new Set();
    let current = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    while(current) {
        elementsToShow.add(current);
        const children = Array.from(current.querySelectorAll('.note-item'));
        children.forEach(child => elementsToShow.add(child));
        current = current.parentElement.closest('.note-item');
    }

    elementsToShow.forEach(el => el.classList.remove('note-hidden'));
    
    renderBreadcrumbs(noteId, window.notesForCurrentPage, window.currentPageName);
}

function showAllNotes() {
    window.currentFocusedNoteId = null;
    document.querySelectorAll('.note-item').forEach(el => el.classList.remove('note-hidden'));
    if (domRefs.noteFocusBreadcrumbsContainer) domRefs.noteFocusBreadcrumbsContainer.style.display = 'none';
}

function showAllNotesAndLoadPage(pageName) {
    showAllNotes();
    if(window.loadPage) window.loadPage(pageName);
}

function getNestingLevel(noteElement) {
    let level = 0;
    let parent = noteElement.parentElement;
    while (parent) {
        if (parent.classList.contains('note-children')) level++;
        if (parent.id === 'notes-container') break;
        parent = parent.parentElement;
    }
    return level;
}

function getNoteAncestors(noteId, allNotesOnPage) {
    const ancestors = [];
    let currentNote = allNotesOnPage.find(note => String(note.id) === String(noteId));
    while (currentNote && currentNote.parent_note_id) {
        const parentNote = allNotesOnPage.find(note => String(note.id) === String(currentNote.parent_note_id));
        if (parentNote) {
            ancestors.unshift(parentNote);
            currentNote = parentNote;
        } else {
            break;
        }
    }
    return ancestors;
}

function promptForPassword() {
    return new Promise((resolve, reject) => {
        const modal = domRefs.passwordModal;
        const passwordInput = domRefs.passwordInput;
        const submitBtn = domRefs.passwordSubmit;
        const cancelBtn = domRefs.passwordCancel;

        if (!modal || !passwordInput || !submitBtn || !cancelBtn) {
            console.error('Password modal elements not found.');
            return reject(new Error('Password modal elements missing.'));
        }

        passwordInput.value = ''; // Clear any previous input
        modal.classList.add('active');
        passwordInput.focus();

        const cleanup = () => {
            submitBtn.removeEventListener('click', handleSubmit);
            cancelBtn.removeEventListener('click', handleCancel);
            passwordInput.removeEventListener('keydown', handleKeydown);
            modal.classList.remove('active');
        };

        const handleSubmit = () => {
            const password = passwordInput.value;
            cleanup();
            resolve(password);
        };

        const handleCancel = () => {
            cleanup();
            reject(new Error('Decryption cancelled by user.'));
        };

        const handleKeydown = (e) => {
            if (e.key === 'Enter') {
                handleSubmit();
            } else if (e.key === 'Escape') {
                handleCancel();
            }
        };

        submitBtn.addEventListener('click', handleSubmit);
        cancelBtn.addEventListener('click', handleCancel);
        passwordInput.addEventListener('keydown', handleKeydown);
    });
}

/**
 * Shows the password modal for encrypted pages and returns a Promise that resolves with the password.
 * @returns {Promise<string>} A promise that resolves with the entered password, or rejects if cancelled.
 */
export function promptForPagePassword() {
    return new Promise((resolve, reject) => {
        const modal = domRefs.passwordModal;
        const passwordInput = domRefs.passwordInput;
        const submitBtn = domRefs.passwordSubmit;
        const cancelBtn = domRefs.passwordCancel;
        const closeBtn = domRefs.passwordModalClose;

        if (!modal || !passwordInput || !submitBtn || !cancelBtn || !closeBtn) {
            return reject(new Error("Password modal elements not found in the DOM."));
        }
        
        passwordInput.value = '';
        modal.classList.add('active');
        passwordInput.focus();

        const submitHandler = () => {
            cleanup();
            resolve(passwordInput.value);
        };

        const cancelHandler = () => {
            cleanup();
            reject(new Error("Password entry cancelled."));
        };
        
        const keydownHandler = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitHandler();
            } else if (e.key === 'Escape') {
                cancelHandler();
            }
        };

        function cleanup() {
            modal.classList.remove('active');
            submitBtn.removeEventListener('click', submitHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
            closeBtn.removeEventListener('click', cancelHandler);
            passwordInput.removeEventListener('keydown', keydownHandler);
            document.removeEventListener('keydown', keydownHandler); // General escape handler
        }

        submitBtn.addEventListener('click', submitHandler);
        cancelBtn.addEventListener('click', cancelHandler);
        closeBtn.addEventListener('click', cancelHandler);
        passwordInput.addEventListener('keydown', keydownHandler);
    });
}

function displayChildPages(pageName) {
    const container = document.getElementById('child-pages-container');
    if (!container) return;

    // Hide container by default
    container.style.display = 'none';

    // Use the API client to fetch child pages
    window.pagesAPI.getChildPages(pageName)
        .then(childPages => {
            if (!childPages || childPages.length === 0) {
                container.style.display = 'none';
                return;
            }

            // Show container only if there are child pages
            container.style.display = 'block';
            
            // Create header
            const header = document.createElement('h3');
            header.textContent = 'Child Pages';
            container.innerHTML = '';
            container.appendChild(header);

            // Create list
            const list = document.createElement('ul');
            list.className = 'child-page-list';

            childPages.forEach(page => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = '#';
                link.dataset.pageName = page.name;
                link.className = 'child-page-link';
                link.textContent = page.name;
                item.appendChild(link);
                list.appendChild(item);
            });

            container.appendChild(list);
        })
        .catch(error => {
            console.error('Error fetching child pages:', error);
            container.style.display = 'none';
        });
}

function displayChildPagesInSidebar(pageName) {
    const container = document.getElementById('child-pages-sidebar');
    if (!container) return;

    // Hide container by default
    container.style.display = 'none';

    // Use the API client to fetch child pages
    window.pagesAPI.getChildPages(pageName)
        .then(childPages => {
            if (!childPages || childPages.length === 0) {
                container.style.display = 'none';
                return;
            }

            // Show container only if there are child pages
            container.style.display = 'block';
            
            // Create header
            const header = document.createElement('h3');
            header.textContent = 'Child Pages';
            container.innerHTML = '';
            container.appendChild(header);

            // Create list
            const list = document.createElement('ul');
            list.className = 'child-pages-sidebar-list';

            childPages.forEach(page => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = '#';
                link.dataset.pageName = page.name;
                link.className = 'child-page-sidebar-link';
                
                // Create the link content with icon and name
                const icon = document.createElement('i');
                icon.dataset.feather = 'file-text';
                icon.className = 'child-page-sidebar-icon';
                
                const nameSpan = document.createElement('span');
                nameSpan.className = 'child-page-sidebar-name';
                // Show only the last part of the name if it contains a namespace
                nameSpan.textContent = page.name.includes('/') ? 
                    page.name.substring(page.name.lastIndexOf('/') + 1) : 
                    page.name;

                link.appendChild(icon);
                link.appendChild(nameSpan);
                item.appendChild(link);
                list.appendChild(item);
            });

            container.appendChild(list);
        })
        .catch(error => {
            console.error('Error fetching child pages:', error);
            container.style.display = 'none';
        });
}

function displayFavorites() {
    const container = document.getElementById('favorites-container');
    if (!container) return;

    // Get or create the UL for the list items
    let listContainer = container.querySelector('ul.favorites-list');
    if (!listContainer) {
        // If #favorites-container was empty or didn't have the UL, create it.
        // This avoids wiping out the H3 title if it's outside #favorites-container but inside div.favorites
        container.innerHTML = ''; // Clear #favorites-container before adding UL
        listContainer = document.createElement('ul');
        listContainer.className = 'favorites-list';
        container.appendChild(listContainer);
    } else {
        // If UL exists, just clear its content
        listContainer.innerHTML = '';
    }
    
    // Use searchAPI instead of localStorage
    window.searchAPI.getFavorites()
        .then(data => {
            const favorites = data.results || [];
            if (favorites.length === 0) {
                listContainer.innerHTML = '<p>No favorite pages yet.</p>'; // Or some other appropriate message
                return;
            }

            favorites.forEach(page => {
                const listItem = document.createElement('li'); // Create LI

                const link = document.createElement('a'); // Create A
                link.href = '#';
                link.className = 'favorite-page-link'; // Correct class
                link.dataset.pageName = page.page_name;
                
                if (page.page_name === window.currentPageName) {
                    link.classList.add('active');
                }

                const icon = document.createElement('i');
                icon.dataset.feather = 'star';
                icon.className = 'favorite-page-icon'; // Correct class
                
                const nameSpan = document.createElement('span');
                nameSpan.className = 'favorite-page-name'; // Correct class
                nameSpan.textContent = page.page_name;

                link.appendChild(icon);
                link.appendChild(nameSpan);
                listItem.appendChild(link); // Append A to LI
                listContainer.appendChild(listItem); // Append LI to UL (listContainer)
            });
        })
        .catch(error => {
            console.error('Error fetching favorites:', error);
            listContainer.innerHTML = '<p>Error loading favorites.</p>';
        });
}

async function toggleFavorite(pageName) {
    try {
        // Get current page data to check favorite status
        const pageData = await window.pagesAPI.getPageByName(pageName);
        if (!pageData) throw new Error('Page not found');

        const isFavorited = pageData.properties?.some(p => p.name === 'favorite' && p.value === 'true');
        
        // Use propertiesAPI to update the favorite status
        if (isFavorited) {
            // Remove favorite property
            await window.propertiesAPI.deleteProperty('page', pageData.id, 'favorite');
        } else {
            // Add favorite property
            await window.propertiesAPI.setProperty('page', pageData.id, 'favorite', 'true');
        }
        
        // Refresh the display - disabled to prevent conflicts with Alpine.js
        // displayFavorites();
    } catch (error) {
        console.error('Error toggling favorite:', error);
        alert('Error updating favorite status. Please try again.');
    }
}

function displayBacklinksInSidebar(pageName) {
    const container = document.getElementById('backlinks-container');
    if (!container) {
        console.log('Backlinks container not found');
        return;
    }

    console.log('Starting backlinks display for page:', pageName);
    
    // Clear the entire container first to avoid duplicate headers
    container.innerHTML = '';

    // Create header
    const header = document.createElement('h3');
    header.textContent = 'Backlinks';
    header.className = 'sidebar-section-header'; // Ensure this line is added/correct
    container.appendChild(header);

    // Create list container
    const listContainer = document.createElement('div');
    listContainer.className = 'backlinks-list';
    container.appendChild(listContainer);

    // Use searchAPI instead of direct fetch
    window.searchAPI.getBacklinks(pageName)
        .then(backlinks => {
            console.log('Received backlinks:', backlinks);
            
            // Handle both array and object formats
            const backlinksArray = Array.isArray(backlinks) ? backlinks : 
                                 (backlinks.results || backlinks.data || []);
            
            if (!backlinksArray || backlinksArray.length === 0) {
                listContainer.innerHTML = '<p>No backlinks found.</p>';
                return;
            }

            backlinksArray.forEach(link => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'backlink-item';
                item.dataset.pageName = link.page_name;
                
                // Create the link content with icon and name
                const icon = document.createElement('i');
                icon.dataset.feather = 'link';
                icon.className = 'backlink-icon';
                
                const contentDiv = document.createElement('div');
                contentDiv.className = 'backlink-content';
                
                const nameSpan = document.createElement('span');
                nameSpan.className = 'backlink-name';
                nameSpan.textContent = link.page_name;
                
                const snippetSpan = document.createElement('span');
                snippetSpan.className = 'backlink-snippet';
                snippetSpan.textContent = link.content_snippet || '';

                contentDiv.appendChild(nameSpan);
                contentDiv.appendChild(snippetSpan);
                
                item.appendChild(icon);
                item.appendChild(contentDiv);
                listContainer.appendChild(item);
            });
        })
        .catch(error => {
            console.error('Error fetching backlinks:', error);
            console.log('Container after error:', container.innerHTML);
            listContainer.innerHTML = '<p>Error loading backlinks.</p>';
        });
}

// Export the main UI object
export const ui = {
    displayNotes,
    addNoteElement,
    removeNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    handleNoteDrop,
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties,
    initializeDelegatedNoteEventListeners,
    renderTransclusion,
    domRefs,
    updatePageTitle,
    updatePageList,
    updateActivePageLink,
    renderPageInlineProperties,
    initPagePropertiesModal,
    updateSaveStatusIndicator,
    renderBreadcrumbs,
    focusOnNote,
    showAllNotes,
    showAllNotesAndLoadPage,
    getNestingLevel,
    getNoteAncestors,
    promptForPassword,
    calendarWidget,
    displayChildPages,
    displayChildPagesInSidebar,
    displayFavorites,
    toggleFavorite,
    displayBacklinksInSidebar
};

// Make ui available globally
if (typeof window !== 'undefined') {
    window.ui = ui;
}