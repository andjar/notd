// Asynchronously fetch all page names for the suggestion cache
fetchAllPages(); 

// Add event listener for the sidebar page list
if (ui.domRefs.pageListContainer) {
    ui.domRefs.pageListContainer.addEventListener('click', (e) => {
        const pageLink = e.target.closest('a[data-page-name]');
        if (pageLink) {
            e.preventDefault();
            window.loadPage(pageLink.dataset.pageName);
        }
    });
}

// Determine the initial page to load from URL or default
const urlParams = new URLSearchParams(window.location.search);
const initialPageName = urlParams.get('page') || getInitialPage(); 