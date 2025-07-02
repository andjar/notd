import { pageMetadataAPI } from '../api_client.js';
import { ui } from '../ui.js';

/**
 * Updates page metadata elements when navigating to a new page
 * This includes elements that are initially rendered server-side by PHP
 * @param {string} pageName - The name of the page to update metadata for
 */
export async function updatePageMetadata(pageName) {
    try {
        console.log('[DEBUG] Updating page metadata for:', pageName);
        const metadata = await pageMetadataAPI.getPageMetadata(pageName);
        console.log('[DEBUG] Received metadata:', metadata);
        
        // Update page title
        updatePageTitle(pageName);
        
        // Update page properties
        updatePageProperties(metadata.properties);
        
        // Update recent pages in sidebar
        updateRecentPages(metadata.recent_pages, pageName);
        
        // Update favorites in sidebar
        updateFavorites(metadata.favorites, pageName);
        
        // Update child pages
        updateChildPages(metadata.child_pages);
        
        // Update backlinks
        console.log('[DEBUG] Updating backlinks with:', metadata.backlinks);
        updateBacklinks(metadata.backlinks);
        
    } catch (error) {
        console.error('Error updating page metadata:', error);
        // Don't throw - this is non-critical functionality
    }
}

/**
 * Updates the page title
 * @param {string} pageName - The page name
 */
function updatePageTitle(pageName) {
    // Update document title
    document.title = `${pageName} - notd`;
    
    // Update page title in the DOM
    const titleContent = document.querySelector('.page-title-content');
    if (titleContent) {
        titleContent.textContent = pageName;
    }
}

/**
 * Updates the page properties display
 * @param {Object} properties - The page properties object
 */
function updatePageProperties(properties) {
    const container = document.getElementById('page-properties-container');
    if (!container) return;
    
    if (!properties || Object.keys(properties).length === 0) {
        container.style.display = 'none';
        return;
    }
    
    const RENDER_INTERNAL = window.APP_CONFIG?.RENDER_INTERNAL_PROPERTIES ?? false;
    let hasVisibleProperties = false;
    
    const fragment = document.createDocumentFragment();
    
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
    
    container.innerHTML = '';
    if (hasVisibleProperties) {
        container.appendChild(fragment);
        container.style.display = 'flex';
    } else {
        container.style.display = 'none';
    }
}

/**
 * Updates the recent pages list in the sidebar
 * @param {Array} recentPages - Array of recent page objects
 * @param {string} currentPageName - The current page name
 */
function updateRecentPages(recentPages, currentPageName) {
    const container = document.getElementById('page-list');
    if (!container) return;
    
    if (!recentPages || recentPages.length === 0) {
        container.innerHTML = '<div class="no-pages-message">No recent pages</div>';
        return;
    }
    
    const listContainer = document.createElement('ul');
    listContainer.className = 'recent-pages-list';
    
    recentPages.forEach((page) => {
        const listItem = document.createElement('li');
        const link = document.createElement('a');
        link.href = '#';
        link.dataset.pageName = page.name;
        link.className = 'recent-page-link';
        
        // Add active class if this is the current page
        if (page.name === currentPageName) {
            link.classList.add('active');
        }
        
        // Create the link content with icon and name
        const icon = document.createElement('i');
        icon.dataset.feather = 'file-text';
        icon.className = 'recent-page-icon';
        
        const nameSpan = document.createElement('span');
        nameSpan.className = 'recent-page-name';
        nameSpan.textContent = page.name;
        
        link.appendChild(icon);
        link.appendChild(nameSpan);
        listItem.appendChild(link);
        listContainer.appendChild(listItem);
        
        // Add click handler
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const pageName = event.currentTarget.dataset.pageName;
            window.loadPage(pageName);
        });
    });
    
    container.innerHTML = '';
    container.appendChild(listContainer);
    
    // Update feather icons
    if (typeof feather !== 'undefined') {
        feather.replace();
    }
}

/**
 * Updates the favorites list in the sidebar
 * @param {Array} favorites - Array of favorite page objects
 * @param {string} currentPageName - The current page name
 */
function updateFavorites(favorites, currentPageName) {
    const container = document.getElementById('favorites-container');
    if (!container) return;
    
    if (!favorites || favorites.length === 0) {
        container.innerHTML = '<p>No favorite pages yet.</p>';
        return;
    }
    
    const listContainer = document.createElement('ul');
    listContainer.className = 'favorites-list';
    
    favorites.forEach((page) => {
        const listItem = document.createElement('li');
        const link = document.createElement('a');
        link.href = '#';
        link.dataset.pageName = page.name;
        link.className = 'favorite-page-link';
        
        // Add active class if this is the current page
        if (page.name === currentPageName) {
            link.classList.add('active');
        }
        
        // Create the link content with icon and name
        const icon = document.createElement('i');
        icon.dataset.feather = 'star';
        icon.className = 'favorite-page-icon';
        
        const nameSpan = document.createElement('span');
        nameSpan.className = 'favorite-page-name';
        nameSpan.textContent = page.name;
        
        link.appendChild(icon);
        link.appendChild(nameSpan);
        listItem.appendChild(link);
        listContainer.appendChild(listItem);
        
        // Add click handler
        link.addEventListener('click', (e) => {
            e.preventDefault();
            window.loadPage(page.name);
        });
    });
    
    container.innerHTML = '';
    container.appendChild(listContainer);
    
    // Update feather icons
    if (typeof feather !== 'undefined') {
        feather.replace();
    }
}

/**
 * Updates the child pages display
 * @param {Array} childPages - Array of child page objects
 */
function updateChildPages(childPages) {
    const container = document.getElementById('child-pages-container');
    if (!container) return;
    
    if (!childPages || childPages.length === 0) {
        container.style.display = 'none';
        return;
    }
    
    container.style.display = 'block';
    container.innerHTML = '<h3>Child Pages</h3>';
    
    const list = document.createElement('ul');
    list.className = 'child-page-list';
    
    childPages.forEach(page => {
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = '#';
        const displayName = page.name.includes('/') ? 
            page.name.substring(page.name.lastIndexOf('/') + 1) : 
            page.name;
        link.textContent = displayName;
        link.className = 'child-page-link';
        link.dataset.pageName = page.name;
        
        // Add click handler
        link.addEventListener('click', (e) => {
            e.preventDefault();
            window.loadPage(page.name);
        });
        
        item.appendChild(link);
        list.appendChild(item);
    });
    
    container.appendChild(list);
}

/**
 * Updates the backlinks display in the sidebar
 * @param {Array} backlinks - Array of backlink objects
 */
function updateBacklinks(backlinks) {
    const container = document.getElementById('backlinks-list');
    if (!container) return;
    
    if (!backlinks || backlinks.length === 0) {
        container.innerHTML = '<p>No backlinks found.</p>';
        return;
    }
    
    const html = backlinks.map(link => {
        const snippet = link.content ? 
            (link.content.length > 100 ? link.content.substring(0, 100) + '...' : link.content) : '';
        
        return `
            <a href="#" class="backlink-item" data-page-name="${link.page_name}">
                <i data-feather="link" class="backlink-icon"></i>
                <div class="backlink-content">
                    <span class="backlink-name">${link.page_name}</span>
                    <span class="backlink-snippet">${snippet}</span>
                </div>
            </a>
        `;
    }).join('');
    
    container.innerHTML = html;
    
    // Add click handlers to backlinks
    container.querySelectorAll('.backlink-item').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const pageName = e.currentTarget.dataset.pageName;
            window.loadPage(pageName);
        });
    });
    
    // Update feather icons
    if (typeof feather !== 'undefined') {
        feather.replace();
    }
} 