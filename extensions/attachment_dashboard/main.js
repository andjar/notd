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

    // Helper function to reset pagination controls in error scenarios
    function updatePaginationForError() {
        pageInfoSpan.textContent = 'Page 1 of 1';
        prevPageButton.disabled = true;
        nextPageButton.disabled = true;
    }

    // Helper Functions
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        if (!bytes || isNaN(bytes)) return 'N/A';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
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
        tableBody.innerHTML = '<tr><td colspan="5">Loading...</td></tr>'; // Optional: Loading indicator

        try {
            const response = await attachmentsAPI.getAllAttachments(params);
            console.log("API Response:", response);

            if (!response) {
                console.error('No response received from API client.');
                tableBody.innerHTML = '<tr><td colspan="5">Error: No response from server.</td></tr>';
                updatePaginationForError();
                return;
            }

            if (response.status === 'error') {
                console.error('API Error:', response.message, response.details || '');
                tableBody.innerHTML = `<tr><td colspan="5">Error: ${response.message || 'An unknown API error occurred.'}</td></tr>`;
                updatePaginationForError();
                return;
            }
            
            // Assuming success if status is not 'error' and data is present
            if (!response.data || !response.pagination) {
                console.error('Invalid API response structure (missing data or pagination):', response);
                tableBody.innerHTML = '<tr><td colspan="5">Error: Invalid data format received from server.</td></tr>';
                updatePaginationForError();
                return;
            }
            
            const attachments = response.data;
            const pagination = response.pagination;

            tableBody.innerHTML = ''; // Clear loading or previous error rows

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
                    link.href = att.url; 
                    link.textContent = 'View/Download';
                    link.target = '_blank';
                    linkCell.appendChild(link);

                    if (att.type && att.type.startsWith('image/')) {
                        const imgPreview = document.createElement('img');
                        imgPreview.src = att.url;
                        imgPreview.alt = att.name + " preview";
                        imgPreview.classList.add('attachment-preview-image');
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

            pageInfoSpan.textContent = `Page ${pagination.current_page} of ${pagination.total_pages || 1}`;
            prevPageButton.disabled = pagination.current_page <= 1;
            nextPageButton.disabled = pagination.current_page >= (pagination.total_pages || 1);

            if (typeFilter.options.length <= 1 && attachments.length > 0) {
                const uniqueTypes = new Set(attachments.map(att => att.type).filter(Boolean));
                const existingOptions = new Set(Array.from(typeFilter.options).map(opt => opt.value));
                uniqueTypes.forEach(type => {
                    if (!existingOptions.has(type)) {
                        const option = document.createElement('option');
                        option.value = type;
                        option.textContent = type;
                        typeFilter.appendChild(option);
                    }
                });
            }

        } catch (error) { // Catches errors from fetch operation itself or if api_client.js re-throws
            console.error('Error fetching attachments:', error.message, error.response || error);
            // Display the error message from the error object, which api_client.js should provide
            tableBody.innerHTML = `<tr><td colspan="5">Error: ${error.message || 'A client-side error occurred.'} Check console.</td></tr>`;
            updatePaginationForError();
        }
    }

    // Event Listeners (remain unchanged)
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
        // nextPageButton.disabled is updated after fetch, so direct check here is less reliable
        // The disabled state itself should prevent going beyond total_pages
        currentPage++;
        fetchAndRenderAttachments();
    });

    // Initial Load
    fetchAndRenderAttachments();
});
