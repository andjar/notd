// extensions/attachment_dashboard/api_client.js

// IMPORTANT PREQUISITE:
// An `APP_BASE_URL` constant or global variable needs to be defined.
// This should be the base path of your application that leads to the /api directory.
// For example, if your API is at `http://yourdomain.com/yourapp/api/v1/...`,
// then APP_BASE_URL could be `/yourapp` or `http://yourdomain.com/yourapp`.
// If the application is served from the root, APP_BASE_URL could be an empty string `''`.
// Ensure this is configured correctly in the environment where this script is deployed.
// For this example, we'll assume it might be an empty string or a path like '/mykb'.
// const APP_BASE_URL = ''; // Example: Adjust as needed for your application structure.

const attachmentsAPI = {
    /**
     * Fetches a list of attachments from the server.
     * @param {object} params - Query parameters for the API.
     * @param {number} params.page - Page number for pagination.
     * @param {string} params.sort_by - Field to sort by.
     * @param {string} params.sort_order - Sort order ('asc' or 'desc').
     * @param {number} params.per_page - Number of items per page.
     * @param {string} [params.filter_by_name] - Optional. Filter by attachment name.
     * @param {string} [params.filter_by_type] - Optional. Filter by MIME type.
     * @returns {Promise<object>} A promise that resolves to the API response (parsed JSON).
     *                           On error, it throws an error or returns a structured error object.
     */
    getAllAttachments: async (params) => {
        // Resolve APP_BASE_URL. Fallback to empty string if not defined globally.
        const baseUrl = typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '';
        const endpoint = `${baseUrl}/api/v1/attachments.php`;

        const queryParams = new URLSearchParams();
        if (params) {
            Object.keys(params).forEach(key => {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    queryParams.append(key, params[key]);
                }
            });
        }

        const url = `${endpoint}?${queryParams.toString()}`;
        console.log(`Constructed API URL: ${url}`); // For debugging

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    // Add any other necessary headers, like 'Authorization' if your API requires it.
                    // 'Authorization': 'Bearer YOUR_TOKEN_HERE',
                }
            });

            if (!response.ok) {
                let errorData = { message: `HTTP error! Status: ${response.status}` };
                try {
                    // Try to parse a JSON error response from the API
                    const apiError = await response.json();
                    // Merge with default error if API provides more details
                    errorData = { ...errorData, ...apiError };
                } catch (e) {
                    // If response is not JSON or another error occurs, stick with the HTTP status
                    console.warn("Could not parse error response as JSON:", e);
                }
                // Throw an error that includes the status and message from the API if available
                const err = new Error(errorData.message);
                err.response = errorData; // Attach full error response
                err.status = response.status;
                throw err;
            }
            return await response.json(); // Parse and return the JSON response
        } catch (error) {
            console.error('Error in attachmentsAPI.getAllAttachments:', error.message, error.response || '');
            // Re-throw the error so it can be caught by the caller in main.js
            // Ensure the error object has a message property for main.js to display
            if (!error.message) {
                error.message = "An unknown error occurred during the API request.";
            }
            throw error;
        }
    },

    // Example stubs for future API methods:
    // uploadAttachment: async (formData) => {
    //     const baseUrl = typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '';
    //     const endpoint = `${baseUrl}/api/v1/attachments.php`;
    //     // Implementation for POST with multipart/form-data
    //     // Remember to include note_id in formData
    //     // return await fetch(endpoint, { method: 'POST', body: formData, headers: {...} });
    // },

    // deleteAttachment: async (attachmentId) => {
    //     const baseUrl = typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '';
    //     const endpoint = `${baseUrl}/api/v1/attachments.php`;
    //     const payload = { action: "delete", id: attachmentId };
    //     // Implementation for POST with application/json or x-www-form-urlencoded
    //     // return await fetch(endpoint, {
    //     //     method: 'POST',
    //     //     headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    //     //     body: JSON.stringify(payload)
    //     // });
    // }
};

export { attachmentsAPI };
