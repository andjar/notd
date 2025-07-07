<?php
require_once '../../config.php';
// Basic setup, similar to index.php if any common elements are needed,
// but largely independent.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kanban Task Board - notd</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
    <!-- Include existing global styles if necessary, or keep it minimal for independence -->
    <!-- For now, let's include the main style.css and a new kanban.css -->
    <?php require_once '../../assets/css/theme_loader.php'; // If theme consistency is desired ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="../../assets/css/components/icons.css">

    <!-- Libraries -->
    <script src="../../assets/libs/Sortable.min.js"></script>
    <!-- Alpine Sort Plugin -->
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/sort@latest/dist/cdn.min.js"></script>
    <!-- Alpine Core -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body>
    <div class="app-container kanban-app-container" x-data="kanbanApp()" x-init="init()">
        <header class="kanban-header">
            <h1>Kanban Task Board</h1>
            <div class="kanban-controls">
                <select id="board-selector" class="board-selector" x-model="currentBoardId" @change="changeBoard()">
                    <template x-for="board in config.boards" :key="board.id">
                        <option :value="board.id" x-text="board.label"></option>
                    </template>
                </select>
                <a href="/" class="action-button">Back to Main App</a>
            </div>
        </header>
        <main id="kanban-root" class="kanban-root" x-show="!isLoading && !errorMessage">
            <div x-show="isLoading" class="loading-message">Loading Kanban board...</div>
            <div x-show="errorMessage" class="error-message" x-text="errorMessage"></div>

            <template x-for="column in columns" :key="column.id">
                    <div class="kanban-column" :data-status-id="column.id" :data-status-matcher="column.statusMatcher">
                        <h3 class="kanban-column-title" x-text="column.title"></h3>
                        <div class="kanban-column-cards"
                             :id="'column-' + column.id"
                             x-sort="handleKanbanCardDrop"
                             x-sort:group="'kanbanCardsGroup'"
                             :data-column-id="column.id">
                            <template x-for="task in column.tasks" :key="task.id">
                                <div class="kanban-card"
                                     :data-note-id="task.id"
                                     :data-current-status="getNoteStatus(task)"
                                     x-sort:item="task.id">
                                    <span x-text="getCardDisplayContent(task.content, column.statusMatcher)"></span>
                                    <!-- Add more task details here if needed -->
                                </div>
                            </template>
                            <div x-show="column.tasks.length === 0" class="empty-column-message">
                                No tasks in this column.
                            </div>
                        </div>
                    </div>
                </template>
        </main>
    </div>

    <!-- Other SCRIPTS -->
    <script src="../../assets/libs/feather.min.js"></script>
    <!-- Application-specific JavaScript -->
    <script type="module" src="../../assets/js/utils.js"></script>
    
    <!-- Kanban-specific JS (ui.js, main.js, app.js) are replaced by the Alpine component below -->

    <script>
        // Load board configurations from config.json
        <?php
        $configPath = __DIR__ . '/config.json';
        $phpConfig = json_decode(file_get_contents($configPath), true);
        ?>
        window.kanbanConfig = <?php echo json_encode($phpConfig); ?>;
        window.configuredKanbanStates = <?php echo defined('TASK_STATES') ? json_encode(TASK_STATES) : json_encode(['TODO', 'DOING', 'DONE']); ?>;
        
        // Import API functions
        let notesAPI, searchAPI;
        let apiLoaded = false;
        
        // Load API functions asynchronously
        import('../../assets/js/api_client.js').then(module => {
            notesAPI = module.notesAPI;
            searchAPI = module.searchAPI;
            apiLoaded = true;
            console.log('API client loaded successfully');
        }).catch(error => {
            console.error('Failed to load API client:', error);
        });

        function kanbanApp() {
            return {
                config: window.kanbanConfig || { boards: [] },
                configuredKanbanStates: window.configuredKanbanStates || ['TODO', 'DOING', 'DONE'],
                currentBoardId: null,
                columns: [],
                allTasksRaw: [], // Raw tasks from API before assigning to columns
                isLoading: true,
                errorMessage: '',

                init() {
                    console.log("Kanban App Initializing...");
                    if (this.config.boards.length > 0) {
                        this.currentBoardId = this.config.boards[0].id;
                    } else {
                        this.errorMessage = "No boards configured.";
                        this.isLoading = false;
                        return;
                    }
                    this.generateColumns();
                    this.fetchTasksForCurrentBoard();
                    this.$watch('currentBoardId', () => this.fetchTasksForCurrentBoard());

                    // Initialize Feather Icons after Alpine is done with initial rendering
                    this.$nextTick(() => {
                        if (window.feather) {
                            window.feather.replace();
                        }
                    });
                },

                generateColumns() {
                    console.log('Generating columns with states:', this.configuredKanbanStates);
                    this.columns = this.configuredKanbanStates.map(state => ({
                        id: state.toLowerCase(),
                        title: state.charAt(0).toUpperCase() + state.slice(1).toLowerCase(),
                        statusMatcher: state.toUpperCase(),
                        tasks: []
                    }));
                    console.log('Generated columns:', this.columns.map(col => ({ id: col.id, statusMatcher: col.statusMatcher })));
                },

                getBoardConfig(boardId) {
                    return this.config.boards.find(b => b.id === boardId);
                },

                async fetchTasksForCurrentBoard() {
                    this.isLoading = true;
                    this.errorMessage = '';
                    this.columns.forEach(col => col.tasks = []); // Clear tasks from columns
                    const boardConfig = this.getBoardConfig(this.currentBoardId);
                    if (!boardConfig) {
                        this.errorMessage = `Configuration for board '${this.currentBoardId}' not found.`;
                        this.isLoading = false;
                        return;
                    }
                    const statusesForBoard = this.configuredKanbanStates;
                    try {
                        if (!apiLoaded || !searchAPI) {
                            this.errorMessage = 'Loading API...';
                            setTimeout(() => {
                                if (apiLoaded) {
                                    this.fetchTasksForCurrentBoard();
                                } else {
                                    this.errorMessage = 'Failed to load API client';
                                }
                            }, 1000);
                            return;
                        }
                        const response = await searchAPI.getTasks('ALL', { includeParentProps: true });
                        console.log('[FETCH] Raw API response:', response);
                        if (response && response.results) {
                            this.allTasksRaw = response.results.map(result => ({
                                id: result.note_id,
                                content: result.content,
                                status: result.status,
                                properties: result.properties || {},
                                parent_properties: result.parent_properties || {},
                                page_id: result.page_id,
                                page_name: result.page_name,
                                is_encrypted: (result.properties?.encrypted?.some(p => String(p.value).toLowerCase() === 'true') ||
                                               result.parent_properties?.encrypted?.some(p => String(p.value).toLowerCase() === 'true')) || false
                            }));
                            console.log('[FETCH] Processed tasks:', this.allTasksRaw);
                            let filteredTasks = this.allTasksRaw.filter(task => {
                                const taskStatus = this.getNoteStatus(task).toUpperCase();
                                const statusMatch = statusesForBoard.includes(taskStatus);
                                if (!statusMatch) return false;
                                if (boardConfig.filters && boardConfig.filters.length > 0) {
                                    return boardConfig.filters.every(filter => {
                                        const noteProp = task.properties?.[filter.name]?.[0]?.value;
                                        const parentProp = task.parent_properties?.[filter.name]?.[0]?.value;
                                        return noteProp === filter.value || parentProp === filter.value;
                                    });
                                }
                                return true;
                            });
                            this.distributeTasksToColumns(filteredTasks);
                        } else {
                            this.errorMessage = 'Failed to load tasks or tasks not found.';
                            console.warn('Search API returned unexpected format or no results:', response);
                        }
                    } catch (error) {
                        console.error('Error fetching tasks:', error);
                        this.errorMessage = `Error fetching tasks: ${error.message}`;
                    } finally {
                        this.isLoading = false;
                        this.$nextTick(() => {
                           if (window.feather) window.feather.replace();
                           // Alpine Sort plugin handles initialization via directives
                        });
                    }
                },

                handleKanbanCardDrop(itemKey, newPosition, targetEl, sourceEl) {
                    const noteId = parseInt(itemKey);
                    const targetColumnId = targetEl.dataset.columnId;

                    let taskToMove;
                    let sourceColumn;
                    let originalTaskIndex = -1;

                    // Find task and source column, and remove task from source
                    for (let col of this.columns) {
                        const taskIndex = col.tasks.findIndex(t => t.id === noteId);
                        if (taskIndex !== -1) {
                            taskToMove = col.tasks[taskIndex];
                            sourceColumn = col;
                            originalTaskIndex = taskIndex;
                            col.tasks.splice(taskIndex, 1);
                            break;
                        }
                    }

                    if (!taskToMove || !sourceColumn) {
                        console.error("Failed to find task or source column in Alpine data for Sortable move. Task ID:", noteId);
                        this.errorMessage = "Error processing card move. Data inconsistency. Please reload.";
                        // Potentially force a reload here if state is too broken
                        return;
                    }

                    const originalTask = { ...taskToMove }; // Shallow copy for potential rollback

                    const targetColumn = this.columns.find(c => c.id === targetColumnId);

                    if (!targetColumn) {
                        console.error("Failed to find target column in Alpine data. Target Column ID:", targetColumnId);
                        // Rollback: put task back in source column
                        sourceColumn.tasks.splice(originalTaskIndex, 0, originalTask);
                        this.errorMessage = "Error processing card move. Target column not found. Please reload.";
                        return;
                    }

                    // Optimistic UI update: Add task to target column
                    targetColumn.tasks.splice(newPosition, 0, taskToMove);

                    // Ensure reactivity by reassigning arrays if deeply nested changes aren't picked up
                    // This might be overly aggressive but ensures Alpine sees the change.
                    sourceColumn.tasks = [...sourceColumn.tasks];
                    targetColumn.tasks = [...targetColumn.tasks];


                    const oldStatus = sourceColumn.statusMatcher.toUpperCase();
                    const newStatus = targetColumn.statusMatcher.toUpperCase();

                    // If status hasn't changed (moved within the same column, or to another column with same status mapping)
                    // and we are not persisting intra-column order, then no backend update is needed.
                    if (oldStatus === newStatus) {
                        console.log(`Task ${noteId} moved but status remains ${newStatus}. No backend update needed unless order is persisted.`);
                        // If you were to persist order: get all task IDs in targetColumn.tasks and send to backend.
                        return;
                    }

                    console.log(`Task ${noteId} moved from status ${oldStatus} to ${newStatus}. Updating backend.`);

                    let newContent = this.syncContentWithStatus(taskToMove.content, newStatus);
                    taskToMove.content = newContent; // Update content in the moving task object

                    try {
                        if (!apiLoaded || !notesAPI) {
                            throw new Error('Notes API not loaded yet');
                        }

                        const batchOperation = {
                            type: 'update',
                            payload: { id: noteId, content: newContent }
                        };
                        notesAPI.batchUpdateNotes([batchOperation]).then(response => {
                            const results = response.results || response.data?.results;
                            const updateResult = results?.find(r => r.type === 'update' && r.note && r.note.id === noteId);

                            if (updateResult && updateResult.status === 'success') {
                                console.log(`Note ${noteId} updated to status ${newStatus} on backend.`);
                                // Update the task in Alpine data with the potentially modified data from backend
                                Object.assign(taskToMove, updateResult.note);
                                taskToMove.status = newStatus; // Explicitly set status if API response doesn't include it under 'status' field

                                // This ensures that if getNoteStatus relies on properties, they are fresh.
                                // And re-distribute just to be absolutely sure if the backend note object has changes
                                // that might affect its column placement according to getNoteStatus.
                                // However, this might be too much if the backend only confirms the content change.
                                // For now, let's assume the optimistic update is mostly correct.
                                this.columns.forEach(col => col.tasks = [...col.tasks]);


                            } else {
                                throw new Error('Update failed or no matching result in API response.');
                            }
                        }).catch(error => {
                             console.error(`Failed to update note ${noteId} to status ${newStatus}:`, error);
                            this.errorMessage = `Failed to update task: ${error.message}. Reverting move.`;

                            // Revert optimistic update
                            targetColumn.tasks.splice(targetColumn.tasks.findIndex(t => t.id === noteId), 1);
                            sourceColumn.tasks.splice(originalTaskIndex, 0, originalTask);

                            // Re-assign for reactivity
                            sourceColumn.tasks = [...sourceColumn.tasks];
                            targetColumn.tasks = [...targetColumn.tasks];
                        });

                    } catch (error) { // Catch sync errors from the try block (e.g. API not loaded)
                        console.error(`Immediate error before API call for note ${noteId} to status ${newStatus}:`, error);
                        this.errorMessage = `Failed to update task: ${error.message}. Reverting move.`;
                        // Revert
                        targetColumn.tasks.splice(targetColumn.tasks.findIndex(t => t.id === noteId), 1);
                        sourceColumn.tasks.splice(originalTaskIndex, 0, originalTask);
                        sourceColumn.tasks = [...sourceColumn.tasks];
                        targetColumn.tasks = [...targetColumn.tasks];
                    } finally {
                        this.$nextTick(() => {
                           if (window.feather) window.feather.replace();
                        });
                    }
                },

                getNoteStatus(note) {
                    // Check for direct status field first (from API response)
                    if (note.status) {
                        console.log(`[STATUS] Note ${note.id} has direct status field: ${note.status}`);
                        return note.status.toUpperCase();
                    }
                    // Then check properties
                    if (note.properties && note.properties.status) {
                        let rawStatus = '';
                        if (Array.isArray(note.properties.status) && note.properties.status.length > 0) {
                            rawStatus = typeof note.properties.status[0] === 'string' ? note.properties.status[0] : (note.properties.status[0].value || '');
                        } else if (typeof note.properties.status === 'string') {
                            rawStatus = note.properties.status;
                        } else if (note.properties.status.value && typeof note.properties.status.value === 'string') {
                            rawStatus = note.properties.status.value;
                        }
                        console.log(`[STATUS] Note ${note.id} status from properties: "${rawStatus}"`);
                        if (rawStatus && this.configuredKanbanStates.includes(rawStatus.toUpperCase())) {
                            return rawStatus.toUpperCase();
                        }
                    }
                    // Finally check content prefix
                    if (note.content) {
                        const statusKeywords = this.configuredKanbanStates.map(s => s.toUpperCase());
                        const statusRegex = new RegExp(`^(${statusKeywords.join('|')})\\s+`, 'i');
                        const match = note.content.match(statusRegex);
                        console.log(`[STATUS] Note ${note.id} content='${note.content}', regex=${statusRegex}, match=${match ? match[0] : 'none'}`);
                        if (match) {
                            console.log(`[STATUS] Note ${note.id} status from content: ${match[1].toUpperCase()}`);
                            return match[1].toUpperCase();
                        }
                    }
                    const defaultStatus = this.configuredKanbanStates[0]?.toUpperCase() || 'TODO';
                    console.log(`[STATUS] Note ${note.id} using default status: ${defaultStatus}`);
                    return defaultStatus;
                },

                getCardDisplayContent(noteContent, columnStatusMatcher) {
                    // Strips status prefix if present. columnStatusMatcher is the status of the column the card is in.
                    // This might not be needed if content is already pre-processed before storing in task object.
                    const statusKeywords = this.configuredKanbanStates.map(s => s.toUpperCase());
                    const statusRegex = new RegExp(`^(${statusKeywords.join('|')}):?\\s+`, 'i');
                    const match = noteContent.match(statusRegex);
                    if (match) {
                        // Check if the matched status is the one for the current column.
                        // If so, it's redundant. Otherwise, the card might be "miscategorized" but showing its true status.
                        // For simplicity now, always strip if any status prefix is found.
                        return noteContent.substring(match[0].length);
                    }
                    return noteContent;
                },

                syncContentWithStatus(content, newStatus) {
                    const statusKeywords = this.configuredKanbanStates.map(s => s.toUpperCase());
                    const statusRegex = new RegExp(`^(${statusKeywords.join('|')})\\s+`, 'i');
                    const match = content.match(statusRegex);
                    console.log(`[SYNC] syncContentWithStatus: content='${content}', newStatus='${newStatus}', regex=${statusRegex}, match=${match ? match[0] : 'none'}`);
                    let result;
                    if (match) {
                        result = newStatus + ' ' + content.substring(match[0].length);
                    } else {
                        result = newStatus + ' ' + content;
                    }
                    console.log(`[SYNC] Resulting content: '${result}'`);
                    return result;
                },

                distributeTasksToColumns(tasks) {
                    console.log(`Distributing ${tasks.length} tasks to columns`);
                    this.columns.forEach(col => col.tasks = []); // Clear existing
                    const seenTaskIds = new Set();
                    tasks.forEach(task => {
                        const taskStatus = this.getNoteStatus(task).toUpperCase();
                        console.log(`[DISTRIBUTE] Task ${task.id} has status: ${taskStatus}`);
                        // Check for duplication in all columns
                        let alreadyPresent = false;
                        this.columns.forEach(col => {
                            if (col.tasks.some(t => t.id === task.id)) {
                                alreadyPresent = true;
                                console.warn(`[DUPLICATE] Task ${task.id} already present in column ${col.statusMatcher}`);
                            }
                        });
                        if (seenTaskIds.has(task.id)) {
                            console.warn(`[DUPLICATE] Task ${task.id} already processed in this distribution pass.`);
                        }
                        seenTaskIds.add(task.id);
                        const targetColumn = this.columns.find(col => col.statusMatcher === taskStatus);
                        if (targetColumn) {
                            console.log(`[DISTRIBUTE] Placing task ${task.id} in column: ${targetColumn.statusMatcher}`);
                            targetColumn.tasks.push(task);
                        } else {
                            // Fallback: if status is not recognized, put in the first configured column
                            const fallbackColumn = this.columns[0];
                            if (fallbackColumn) {
                                console.warn(`[Kanban Alpine] No column for status: '${taskStatus}' (Note ID ${task.id}). Placing in '${fallbackColumn.title}'.`);
                                fallbackColumn.tasks.push(task);
                            } else {
                                console.error(`[Kanban Alpine] No column for status: '${taskStatus}' and no fallback column configured for note ID ${task.id}.`);
                            }
                        }
                    });
                    // Log final distribution
                    this.columns.forEach(col => {
                        const ids = col.tasks.map(t => t.id);
                        console.log(`[DISTRIBUTE] Column ${col.statusMatcher}: ${col.tasks.length} tasks, IDs: ${ids.join(', ')}`);
                    });
                },

                changeBoard() {
                    // This is automatically handled by $watch on currentBoardId,
                    // which calls fetchTasksForCurrentBoard.
                    // This function is here primarily for the @change binding clarity.
                    console.log("Board changed to:", this.currentBoardId);
                },

                moveTaskToCorrectColumn(task, targetStatus) {
                    // Find which column the task is currently in
                    let currentColumn = null;
                    let taskIndex = -1;
                    
                    for (let col of this.columns) {
                        taskIndex = col.tasks.findIndex(t => t.id === task.id);
                        if (taskIndex !== -1) {
                            currentColumn = col;
                            break;
                        }
                    }
                    
                    if (!currentColumn) {
                        console.error('Task not found in any column:', task.id);
                        return;
                    }
                    
                    const targetColumn = this.columns.find(col => col.statusMatcher === targetStatus);
                    if (!targetColumn) {
                        console.error(`Target column for status ${targetStatus} not found`);
                        return;
                    }
                    
                    // If the task is already in the correct column, do nothing
                    if (currentColumn.statusMatcher === targetStatus) {
                        console.log(`Task ${task.id} is already in the correct column (${targetStatus})`);
                        return;
                    }
                    
                    // Move the task to the target column
                    console.log(`Moving task ${task.id} from column ${currentColumn.statusMatcher} to ${targetStatus}`);
                    currentColumn.tasks.splice(taskIndex, 1);
                    targetColumn.tasks.push(task);
                },

                // initializeSortable() and initSortable() methods are removed as Alpine Sort handles this.
            };
        }
        
        // Make it global for Alpine x-data
        window.kanbanApp = kanbanApp;
        console.log('kanbanApp function defined and exposed globally');
    </script>
</body>
</html>
