import { attachmentsAPI } from './api_client.js';

document.addEventListener('DOMContentLoaded', () => {
    // DOM Element References
    const searchBar = document.getElementById('search-bar');
    const typeFilter = document.getElementById('type-filter');
    const tableBody = document.getElementById('attachments-table').getElementsByTagName('tbody')[0];
    const prevPageButton = document.getElementById('prev-page');
    const nextPageButton = document.getElementById('next-page');
    const pageInfoSpan = document.getElementById('page-info');
    const tableHeaders = document.querySelectorAll('#attachments-table th[data-sort]');

    // State Management
    let currentPage = 1;
    let sortBy = 'created_at';
    let sortOrder = 'desc';
    let searchTerm = '';
    let filterType = '';
    let perPage = 10; // Default items per page

    // Helper Functions
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        if (!bytes || isNaN(bytes)) return 'N/A'; // Handle null, undefined or NaN
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
            // Check if date is valid
            if (isNaN(date.getTime())) {
                return 'Invalid Date';
            }
            return date.toISOString().split('T')[0] + ' ' + date.toTimeString().split(' ')[0];
        } catch (e) {
            return 'Invalid Date';
        }
    }

    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    }

    // Main function to fetch and render attachments
    async function fetchAndRenderAttachments() {
        const params = {
            page: currentPage,
            sort_by: sortBy,
            sort_order: sortOrder,
            per_page: perPage,
        };
        if (searchTerm) {
            params.filter_by_name = searchTerm;
        }
        if (filterType) {
            params.filter_by_type = filterType;
        }

        console.log("Fetching with params:", params);

        try {
            const response = await attachmentsAPI.getAllAttachments(params);
            console.log("API Response:", response);

            if (!response || !response.data) {
                console.error('Invalid API response structure:', response);
                tableBody.innerHTML = '<tr><td colspan="5">Error: Invalid data received from server.</td></tr>';
                // Update pagination to show default/error state
                pageInfoSpan.textContent = 'Page 1 of 1';
                prevPageButton.disabled = true;
                nextPageButton.disabled = true;
                return;
            }
            
            const attachments = response.data;
            const pagination = response.pagination;

            tableBody.innerHTML = ''; // Clear existing rows

            if (attachments.length > 0) {
                attachments.forEach(att => {
                    const row = tableBody.insertRow();
                    
                    const nameCell = row.insertCell();
                    nameCell.textContent = att.name;
                    nameCell.setAttribute('data-label', 'Name');

                    const sizeCell = row.insertCell();
                    sizeCell.textContent = formatFileSize(att.size);
                    sizeCell.setAttribute('data-label', 'Size');
                    
                    const typeCell = row.insertCell();
                    typeCell.textContent = att.type;
                    typeCell.setAttribute('data-label', 'Type');

                    const dateCell = row.insertCell();
                    dateCell.textContent = formatDate(att.created_at);
                    dateCell.setAttribute('data-label', 'Date Uploaded');
                    
                    const linkCell = row.insertCell();
                    linkCell.setAttribute('data-label', 'Preview/Link');
                    const link = document.createElement('a');
                    link.href = att.url; // Already includes APP_BASE_URL from backend
                    link.textContent = 'View/Download';
                    link.target = '_blank';
                    linkCell.appendChild(link);

                    if (att.type && att.type.startsWith('image/')) {
                        const imgPreview = document.createElement('img');
                        imgPreview.src = att.url;
                        imgPreview.alt = att.name + " preview";
                        imgPreview.classList.add('attachment-preview-image'); // For styling
                        linkCell.appendChild(imgPreview);
                    }
                });
            } else {
                const row = tableBody.insertRow();
                const cell = row.insertCell();
                cell.colSpan = 5;
                cell.textContent = 'No attachments found matching your criteria.';
                cell.style.textAlign = 'center';
            }

            // Update pagination
            pageInfoSpan.textContent = `Page ${pagination.current_page} of ${pagination.total_pages || 1}`;
            prevPageButton.disabled = pagination.current_page <= 1;
            nextPageButton.disabled = pagination.current_page >= pagination.total_pages;

            // Dynamically populate type filter (basic version - only if not already populated)
            // This could be improved to gather all unique types from *all* pages,
            // or have a dedicated endpoint. For now, it adds types from the current page.
            if (typeFilter.options.length <= 1 && attachments.length > 0) { // Keep "All Types"
                const uniqueTypes = new Set(attachments.map(att => att.type).filter(Boolean));
                const existingOptions = new Set(Array.from(typeFilter.options).map(opt => opt.value));
                uniqueTypes.forEach(type => {
                    if (!existingOptions.has(type)) {
                        const option = document.createElement('option');
                        option.value = type;
                        option.textContent = type; // Or a more friendly name
                        typeFilter.appendChild(option);
                    }
                });
            }

        } catch (error) {
            console.error('Error fetching attachments:', error);
            tableBody.innerHTML = `<tr><td colspan="5">Error loading attachments: ${error.message || 'Unknown error'}. Check console for details.</td></tr>`;
            pageInfoSpan.textContent = 'Page 1 of 1';
            prevPageButton.disabled = true;
            nextPageButton.disabled = true;
        }
    }

    // Event Listeners
    searchBar.addEventListener('input', debounce(() => {
        searchTerm = searchBar.value.trim();
        currentPage = 1;
        fetchAndRenderAttachments();
    }, 300));

    typeFilter.addEventListener('change', () => {
        filterType = typeFilter.value;
        currentPage = 1;
        fetchAndRenderAttachments();
    });

    tableHeaders.forEach(header => {
        header.addEventListener('click', () => {
            const newSortBy = header.dataset.sort;
            if (sortBy === newSortBy) {
                sortOrder = sortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                sortBy = newSortBy;
                sortOrder = 'desc';
            }
            // Visual indicator for sorting (optional)
            tableHeaders.forEach(th => th.classList.remove('sort-asc', 'sort-desc'));
            header.classList.add(sortOrder === 'asc' ? 'sort-asc' : 'sort-desc');
            
            fetchAndRenderAttachments();
        });
    });

    prevPageButton.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            fetchAndRenderAttachments();
        }
    });

    nextPageButton.addEventListener('click', () => {
        // Check against total_pages which should be updated by fetchAndRenderAttachments
        // This requires pagination.total_pages to be available in a broader scope or re-fetched
        // For simplicity, the disabled state handles this, but for robustness, one might check here too.
        currentPage++;
        fetchAndRenderAttachments();
    });

    // Initial Load
    fetchAndRenderAttachments();
});
