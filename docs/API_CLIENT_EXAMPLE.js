/**
 * Notd Outliner API Client
 * 
 * A JavaScript client for interacting with the Notd Outliner API.
 * This example demonstrates common API operations and best practices.
 */

class NotdApiClient {
    constructor(baseUrl = '/api/v1') {
        this.baseUrl = baseUrl;
        this.defaultHeaders = {
            'Content-Type': 'application/json'
        };
    }

    /**
     * Make an HTTP request to the API
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        const config = {
            headers: { ...this.defaultHeaders, ...options.headers },
            ...options
        };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(`API Error: ${data.message || response.statusText}`);
            }

            return data;
        } catch (error) {
            console.error('API request failed:', error);
            throw error;
        }
    }

    /**
     * Health check
     */
    async ping() {
        return this.request('/ping');
    }

    /**
     * Page operations
     */
    async getPages(options = {}) {
        const params = new URLSearchParams();
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        if (options.exclude_journal) params.append('exclude_journal', 'true');
        
        return this.request(`/pages?${params.toString()}`);
    }

    async getRecentPages() {
        return this.request('/recent_pages');
    }

    async getPageByName(name) {
        return this.request(`/pages?name=${encodeURIComponent(name)}`);
    }

    async getPageById(id) {
        return this.request(`/pages?id=${id}`);
    }

    async createPage(name, content = null) {
        return this.request('/pages', {
            method: 'POST',
            body: JSON.stringify({ name, content })
        });
    }

    async updatePage(id, updates) {
        return this.request('/pages', {
            method: 'PUT',
            body: JSON.stringify({ id, ...updates })
        });
    }

    async deletePage(id) {
        return this.request('/pages', {
            method: 'DELETE',
            body: JSON.stringify({ id })
        });
    }

    /**
     * Note operations
     */
    async getNotesByPage(pageId, options = {}) {
        const params = new URLSearchParams();
        params.append('page_id', pageId);
        if (options.include_internal) params.append('include_internal', 'true');
        if (options.include_parent_properties) params.append('include_parent_properties', 'true');
        
        return this.request(`/notes?${params.toString()}`);
    }

    async getNoteById(id, options = {}) {
        const params = new URLSearchParams();
        params.append('id', id);
        if (options.include_internal) params.append('include_internal', 'true');
        if (options.include_parent_properties) params.append('include_parent_properties', 'true');
        
        return this.request(`/notes?${params.toString()}`);
    }

    async batchOperations(operations, options = {}) {
        return this.request('/notes', {
            method: 'POST',
            body: JSON.stringify({
                action: 'batch',
                operations,
                include_parent_properties: options.include_parent_properties || false
            })
        });
    }

    async createNote(pageId, content, options = {}) {
        return this.batchOperations([{
            type: 'create',
            payload: {
                page_id: pageId,
                content,
                parent_note_id: options.parent_note_id || null,
                order_index: options.order_index || 0,
                collapsed: options.collapsed || 0,
                client_temp_id: options.client_temp_id || null
            }
        }], options);
    }

    async updateNote(id, updates, options = {}) {
        return this.batchOperations([{
            type: 'update',
            payload: { id, ...updates }
        }], options);
    }

    async deleteNote(id) {
        return this.batchOperations([{
            type: 'delete',
            payload: { id }
        }]);
    }

    /**
     * Append notes to page (creates page if it doesn't exist)
     */
    async appendToPage(pageName, notes, options = {}) {
        return this.request('/append_to_page', {
            method: 'POST',
            body: JSON.stringify({
                page_name: pageName,
                notes: Array.isArray(notes) ? notes : [{ content: notes }]
            })
        });
    }

    /**
     * Property operations
     */
    async getProperties(entityType, entityId, includeHidden = false) {
        const params = new URLSearchParams();
        params.append('entity_type', entityType);
        params.append('entity_id', entityId);
        if (includeHidden) params.append('include_hidden', 'true');
        
        return this.request(`/properties?${params.toString()}`);
    }

    /**
     * Search operations
     */
    async search(query, options = {}) {
        const params = new URLSearchParams();
        params.append('q', query);
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        if (options.include_parent_properties) params.append('include_parent_properties', 'true');
        
        return this.request(`/search?${params.toString()}`);
    }

    async searchTasks(status = 'ALL', options = {}) {
        const params = new URLSearchParams();
        params.append('tasks', status);
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        if (options.include_parent_properties) params.append('include_parent_properties', 'true');
        
        return this.request(`/search?${params.toString()}`);
    }

    async searchBacklinks(pageName, options = {}) {
        const params = new URLSearchParams();
        params.append('backlinks_for_page_name', pageName);
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        if (options.include_parent_properties) params.append('include_parent_properties', 'true');
        
        return this.request(`/search?${params.toString()}`);
    }

    async searchFavorites(options = {}) {
        const params = new URLSearchParams();
        params.append('favorites', 'true');
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        
        return this.request(`/search?${params.toString()}`);
    }

    /**
     * Custom query operations
     */
    async queryNotes(sqlQuery, options = {}) {
        return this.request('/query_notes', {
            method: 'POST',
            body: JSON.stringify({
                sql_query: sqlQuery,
                page: options.page || 1,
                per_page: options.per_page || 20,
                include_properties: options.include_properties !== false
            })
        });
    }

    /**
     * Attachment operations
     */
    async uploadAttachment(noteId, file) {
        const formData = new FormData();
        formData.append('note_id', noteId);
        formData.append('attachmentFile', file);

        return this.request('/attachments', {
            method: 'POST',
            headers: {}, // Let browser set Content-Type for FormData
            body: formData
        });
    }

    async getAttachments(options = {}) {
        const params = new URLSearchParams();
        if (options.note_id) params.append('note_id', options.note_id);
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        if (options.sort_by) params.append('sort_by', options.sort_by);
        if (options.sort_order) params.append('sort_order', options.sort_order);
        if (options.filter_by_name) params.append('filter_by_name', options.filter_by_name);
        if (options.filter_by_type) params.append('filter_by_type', options.filter_by_type);
        
        return this.request(`/attachments?${params.toString()}`);
    }

    async deleteAttachment(attachmentId, noteId) {
        return this.request('/attachments', {
            method: 'POST',
            body: JSON.stringify({
                action: 'delete',
                attachment_id: attachmentId,
                note_id: noteId
            })
        });
    }

    /**
     * Template operations
     */
    async getTemplates(type = 'note') {
        return this.request(`/templates?type=${type}`);
    }

    async createTemplate(type, name, content) {
        return this.request('/templates', {
            method: 'POST',
            body: JSON.stringify({ type, name, content })
        });
    }

    async updateTemplate(type, currentName, content, newName = null) {
        return this.request('/templates', {
            method: 'POST',
            body: JSON.stringify({
                _method: 'PUT',
                type,
                current_name: currentName,
                new_name: newName,
                content
            })
        });
    }

    async deleteTemplate(type, name) {
        return this.request('/templates', {
            method: 'POST',
            body: JSON.stringify({
                _method: 'DELETE',
                type,
                name
            })
        });
    }

    /**
     * Extension operations
     */
    async getExtensions() {
        return this.request('/extensions');
    }

    /**
     * Webhook operations
     */
    async getWebhooks() {
        return this.request('/webhooks');
    }

    async createWebhook(webhookData) {
        return this.request('/webhooks', {
            method: 'POST',
            body: JSON.stringify(webhookData)
        });
    }

    async getWebhook(id) {
        return this.request(`/webhooks/${id}`);
    }

    async updateWebhook(id, updates) {
        return this.request(`/webhooks/${id}`, {
            method: 'PUT',
            body: JSON.stringify(updates)
        });
    }

    async deleteWebhook(id) {
        return this.request(`/webhooks/${id}`, {
            method: 'DELETE'
        });
    }

    async testWebhook(id) {
        return this.request(`/webhooks/${id}/test`, {
            method: 'POST'
        });
    }

    async getWebhookHistory(id, options = {}) {
        const params = new URLSearchParams();
        if (options.page) params.append('page', options.page);
        if (options.per_page) params.append('per_page', options.per_page);
        
        return this.request(`/webhooks/${id}/history?${params.toString()}`);
    }
}

// Usage Examples

async function exampleUsage() {
    const api = new NotdApiClient();

    try {
        // 1. Health check
        console.log('Checking API health...');
        const health = await api.ping();
        console.log('API is healthy:', health);

        // 2. Create a project page with notes
        console.log('Creating project page...');
        const projectData = await api.appendToPage('My Project', [
            {
                content: 'Project Overview {priority::high} {status::active}',
                order_index: 1
            },
            {
                content: 'TODO Research competitors {assignee::john}',
                order_index: 2
            },
            {
                content: 'DONE Setup development environment',
                order_index: 3
            }
        ]);
        console.log('Project created:', projectData);

        // 3. Get the page details
        const page = await api.getPageByName('My Project');
        console.log('Page details:', page);

        // 4. Get notes with properties
        const notes = await api.getNotesByPage(page.data.id, {
            include_internal: true,
            include_parent_properties: true
        });
        console.log('Notes with properties:', notes);

        // 5. Search for high priority items
        const highPriority = await api.search('priority::high');
        console.log('High priority items:', highPriority);

        // 6. Search for TODO tasks
        const todoTasks = await api.searchTasks('TODO');
        console.log('TODO tasks:', todoTasks);

        // 7. Add a new note using batch operations
        const batchResult = await api.batchOperations([
            {
                type: 'create',
                payload: {
                    page_id: page.data.id,
                    content: 'New note with {tags::important}',
                    order_index: 4
                }
            }
        ]);
        console.log('Batch operation result:', batchResult);

        // 8. Update a note
        if (notes.data.length > 0) {
            const updateResult = await api.updateNote(notes.data[0].id, {
                content: 'Updated content with {priority::critical}',
                order_index: 1
            });
            console.log('Update result:', updateResult);
        }

        // 9. Get properties for a note
        if (notes.data.length > 0) {
            const properties = await api.getProperties('note', notes.data[0].id, true);
            console.log('Note properties:', properties);
        }

        // 10. Create a template
        const template = await api.createTemplate('note', 'Meeting Template', 
            'Meeting: {{title}}\nDate: {{date}}\nAttendees: {{attendees}}\n\nAgenda:\n- \n\nAction Items:\n- ');
        console.log('Template created:', template);

        // 11. Get templates
        const templates = await api.getTemplates('note');
        console.log('Available templates:', templates);

        // 12. Search for backlinks
        const backlinks = await api.searchBacklinks('My Project');
        console.log('Backlinks to My Project:', backlinks);

        // 13. Custom query
        const customResults = await api.queryNotes(
            "SELECT id FROM Notes WHERE content LIKE '%important%'",
            { include_properties: true }
        );
        console.log('Custom query results:', customResults);

    } catch (error) {
        console.error('Example failed:', error);
    }
}

// Advanced usage examples

class NotdProjectManager {
    constructor(apiClient) {
        this.api = apiClient;
    }

    /**
     * Create a complete project structure
     */
    async createProject(projectName, description, tasks = []) {
        try {
            // Create project page
            const projectData = await this.api.appendToPage(projectName, [
                {
                    content: `Project: ${projectName} {type::project} {status::active}`,
                    order_index: 1
                },
                {
                    content: `Description: ${description}`,
                    order_index: 2
                }
            ]);

            const pageId = projectData.data.page.id;

            // Add tasks
            if (tasks.length > 0) {
                const taskOperations = tasks.map((task, index) => ({
                    type: 'create',
                    payload: {
                        page_id: pageId,
                        content: `TODO ${task} {priority::medium}`,
                        order_index: index + 3
                    }
                }));

                await this.api.batchOperations(taskOperations);
            }

            return projectData;
        } catch (error) {
            console.error('Failed to create project:', error);
            throw error;
        }
    }

    /**
     * Get project summary with task statistics
     */
    async getProjectSummary(projectName) {
        try {
            const page = await this.api.getPageByName(projectName);
            const notes = await this.api.getNotesByPage(page.data.id, { include_internal: true });

            const tasks = notes.data.filter(note => 
                note.content && note.content.match(/^(TODO|DOING|DONE|SOMEDAY|WAITING|CANCELLED)\s+/)
            );

            const taskStats = {
                total: tasks.length,
                todo: tasks.filter(t => t.content.startsWith('TODO')).length,
                doing: tasks.filter(t => t.content.startsWith('DOING')).length,
                done: tasks.filter(t => t.content.startsWith('DONE')).length,
                waiting: tasks.filter(t => t.content.startsWith('WAITING')).length,
                cancelled: tasks.filter(t => t.content.startsWith('CANCELLED')).length
            };

            return {
                page: page.data,
                taskStats,
                tasks
            };
        } catch (error) {
            console.error('Failed to get project summary:', error);
            throw error;
        }
    }

    /**
     * Find all high priority items across all projects
     */
    async findHighPriorityItems() {
        try {
            const results = await this.api.search('priority::high');
            return results.data.results;
        } catch (error) {
            console.error('Failed to find high priority items:', error);
            throw error;
        }
    }

    /**
     * Get all tasks assigned to a specific person
     */
    async getTasksByAssignee(assignee) {
        try {
            const results = await this.api.search(`assignee::${assignee}`);
            return results.data.results.filter(result => 
                result.content && result.content.match(/^(TODO|DOING|SOMEDAY|WAITING)\s+/)
            );
        } catch (error) {
            console.error('Failed to get tasks by assignee:', error);
            throw error;
        }
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { NotdApiClient, NotdProjectManager };
} 