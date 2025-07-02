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
    <link rel="stylesheet" href="../../assets/css/icons.css">
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
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
        <main id="kanban-root" class="kanban-root">
            <div x-show="isLoading" class="loading-message">Loading Kanban board...</div>
            <div x-show="errorMessage" class="error-message" x-text="errorMessage"></div>

            <div class="kanban-board-active" x-show="!isLoading && !errorMessage">
                <template x-for="column in columns" :key="column.id">
                    <div class="kanban-column" :data-status-id="column.id" :data-status-matcher="column.statusMatcher">
                        <h3 class="kanban-column-title" x-text="column.title"></h3>
                        <div class="kanban-column-cards" :id="'column-' + column.id" x-init="initSortable($el, column.statusMatcher)">
                            <template x-for="task in column.tasks" :key="task.id">
                                <div class="kanban-card" :data-note-id="task.id" :data-current-status="getNoteStatus(task)" draggable="true">
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
            </div>
        </main>
    </div>

    <script>
      // Load board configurations from config.json
      <?php
      $configPath = __DIR__ . '/config.json';
      $phpConfig = json_decode(file_get_contents($configPath), true);
      ?>
      window.kanbanConfig = <?php echo json_encode($phpConfig); ?>;
      window.configuredKanbanStates = <?php echo defined('TASK_STATES') ? json_encode(TASK_STATES) : json_encode(['TODO', 'DOING', 'DONE']); ?>;
    </script>

    <!-- SCRIPTS -->
    <!-- Libraries -->
    <script src="../../assets/libs/feather.min.js"></script>
    <script src="../../assets/libs/Sortable.min.js"></script>
    <!-- sjcl.js not directly used by Kanban from what's visible, can be removed if not needed for other global functions -->
    <!-- <script src="../../assets/libs/sjcl.js"></script> -->


    <!-- Application-specific JavaScript -->
    <!-- utils.js might be needed by other scripts, keeping for now -->
    <script type="module" src="../../assets/js/utils.js"></script>
    
    <!-- API client is crucial -->
    <script type="module" src="../../assets/js/api_client.js"></script>
    
    <!-- Kanban-specific JS (ui.js, main.js, app.js) are replaced by the Alpine component below -->

    <script type="module">
        import { notesAPI, searchAPI } from '../../assets/js/api_client.js';

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
                    this.columns = this.configuredKanbanStates.map(state => ({
                        id: state.toLowerCase(),
                        title: state.charAt(0).toUpperCase() + state.slice(1).toLowerCase(),
                        statusMatcher: state.toUpperCase(),
                        tasks: []
                    }));
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

                    const statusesForBoard = this.configuredKanbanStates; // Assuming all configured states are relevant for any board for now
                                                                    // Could be refined with boardConfig.statuses if defined

                    try {
                        const response = await searchAPI.getTasks('ALL', { includeParentProps: true });
                        if (response && response.results) {
                            this.allTasksRaw = response.results.map(result => ({
                                id: result.note_id,
                                content: result.content,
                                properties: result.properties || {},
                                parent_properties: result.parent_properties || {},
                                page_id: result.page_id,
                                page_name: result.page_name,
                                is_encrypted: (result.properties?.encrypted?.some(p => String(p.value).toLowerCase() === 'true') ||
                                               result.parent_properties?.encrypted?.some(p => String(p.value).toLowerCase() === 'true')) || false
                            }));

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
                        });
                    }
                },

                getNoteStatus(note) {
                    // Simplified from ui.js for now - primarily uses property if available
                    // then content prefix. Assumes `configuredKanbanStates` is available.
                    let status = this.configuredKanbanStates[0]?.toUpperCase() || 'TODO'; // Default
                    const statusKeywords = this.configuredKanbanStates.map(s => s.toUpperCase());

                    if (note.properties && note.properties.status) {
                        let rawStatus = '';
                        if (Array.isArray(note.properties.status) && note.properties.status.length > 0) {
                            rawStatus = typeof note.properties.status[0] === 'string' ? note.properties.status[0] : (note.properties.status[0].value || '');
                        } else if (typeof note.properties.status === 'string') {
                            rawStatus = note.properties.status;
                        } else if (note.properties.status.value && typeof note.properties.status.value === 'string') {
                            rawStatus = note.properties.status.value;
                        }
                        if (rawStatus && statusKeywords.includes(rawStatus.toUpperCase())) {
                            return rawStatus.toUpperCase();
                        }
                    }

                    if (note.content) {
                        const statusRegex = new RegExp(`^(${statusKeywords.join('|')}):?\\s+`, 'i');
                        const match = note.content.match(statusRegex);
                        if (match) {
                            return match[1].toUpperCase();
                        }
                    }
                    // If note has a status property but it's not in configuredKanbanStates, it might fall here.
                    // Or if it has no status at all.
                    // For now, we return the default. A more robust solution might be needed.
                    return status;
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

                distributeTasksToColumns(tasks) {
                    this.columns.forEach(col => col.tasks = []); // Clear existing
                    tasks.forEach(task => {
                        const taskStatus = this.getNoteStatus(task).toUpperCase();
                        const targetColumn = this.columns.find(col => col.statusMatcher === taskStatus);
                        if (targetColumn) {
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
                },

                changeBoard() {
                    // This is automatically handled by $watch on currentBoardId,
                    // which calls fetchTasksForCurrentBoard.
                    // This function is here primarily for the @change binding clarity.
                    console.log("Board changed to:", this.currentBoardId);
                },

                initSortable(el, columnStatusMatcher) {
                    new Sortable(el, {
                        group: 'kanban-cards',
                        animation: 150,
                        ghostClass: 'kanban-ghost',
                        chosenClass: 'kanban-chosen',
                        dragClass: 'kanban-drag',
                        onEnd: async (evt) => {
                            const itemEl = evt.item;
                            const noteId = parseInt(itemEl.dataset.noteId);
                            const oldStatus = itemEl.dataset.currentStatus.toUpperCase(); // Status the card HAD

                            const targetColumnEl = itemEl.closest('.kanban-column');
                            const newStatus = targetColumnEl.dataset.statusMatcher.toUpperCase(); // Status of the column it was DROPPED INTO

                            // Find the task in Alpine's data model
                            let taskToMove;
                            let sourceColumn;
                            let targetColumn = this.columns.find(c => c.statusMatcher === newStatus);

                            for (let col of this.columns) {
                                taskToMove = col.tasks.find(t => t.id === noteId);
                                if (taskToMove) {
                                    sourceColumn = col;
                                    break;
                                }
                            }

                            if (!taskToMove || !sourceColumn || !targetColumn) {
                                console.error("Failed to find task or columns in Alpine data for Sortable move.");
                                // Revert DOM change by Sortable as a basic measure
                                evt.from.appendChild(itemEl);
                                this.errorMessage = "Error processing card move. Data inconsistency.";
                                return;
                            }

                            // If dropped in the same column at the same position, do nothing.
                            // SortableJS handles visual reordering within same list automatically if not cancelling.
                            // We only care about inter-column moves or status changes.
                            if (sourceColumn.statusMatcher === newStatus && evt.oldIndex === evt.newIndex) {
                                 console.log("Task moved within the same column, no status change needed.");
                                 // Update task order in Alpine data if necessary (complex, requires knowing exact old/new index in data)
                                 // For now, assume API doesn't care about intra-column order, or it's handled by other means.
                                return;
                            }


                            // Optimistic UI update: Move task in Alpine data model
                            // This will cause Alpine to re-render, which might conflict with Sortable's DOM change.
                            // We need to ensure Sortable's change is either undone or Alpine's re-render is compatible.

                            // Remove from old column's tasks array
                            const taskIndex = sourceColumn.tasks.findIndex(t => t.id === noteId);
                            if (taskIndex > -1) {
                                sourceColumn.tasks.splice(taskIndex, 1);
                            }
                            // Add to new column's tasks array
                            // Sortable's evt.newDraggableIndex refers to the DOM. We'll just push for now.
                            // For precise positioning based on drop, this would need more logic.
                            targetColumn.tasks.push(taskToMove);


                            // Update task on backend
                            let newContent = taskToMove.content;
                            const statusKeywords = this.configuredKanbanStates.map(s => s.toUpperCase());
                            const statusRegex = new RegExp(`^(${statusKeywords.join('|')}):?\\s+`, 'i');
                            const match = taskToMove.content.match(statusRegex);

                            if (match) {
                                newContent = newStatus + " " + taskToMove.content.substring(match[0].length);
                            } else {
                                newContent = newStatus + " " + taskToMove.content;
                            }

                            try {
                                const batchOperation = {
                                    type: 'update',
                                    payload: { id: noteId, content: newContent }
                                };
                                const response = await notesAPI.batchUpdateNotes([batchOperation]);
                                const results = response.data?.results || response.results;
                                const updateResult = results?.find(r => r.type === 'update' && r.note && r.note.id === noteId);

                                if (updateResult && updateResult.status === 'success') {
                                    // Update taskToMove with data from server response
                                    Object.assign(taskToMove, updateResult.note);
                                    itemEl.dataset.currentStatus = newStatus; // Reflect new status on the DOM element for future drags
                                    console.log(`Note ${noteId} updated to status ${newStatus}.`);
                                    // Alpine's reactivity should handle re-rendering based on taskToMove changes.
                                } else {
                                    throw new Error('Update failed or no matching result in API response.');
                                }
                            } catch (error) {
                                console.error(`Failed to update note ${noteId} to status ${newStatus}:`, error);
                                this.errorMessage = `Failed to update task: ${error.message}. Reverting move.`;

                                // Revert optimistic UI update in Alpine data
                                targetColumn.tasks.pop(); // Remove from new column
                                sourceColumn.tasks.splice(taskIndex, 0, taskToMove); // Add back to old column at original position

                                // SortableJS might have already moved the item in the DOM.
                                // We need to tell Sortable to cancel or manually move it back.
                                // This can be tricky. A full re-fetch or more complex state management might be safer.
                                // For now, this revert is in the data. The DOM might be briefly inconsistent.
                                // A simple way to force DOM consistency is to tell sortable to cancel:
                                // evt.from.appendChild(itemEl); // This is a common way to revert.
                                // However, this might trigger another onEnd if not careful.
                                // The safest is often to let Alpine re-render from the corrected data.
                            } finally {
                                // Ensure feather icons are updated if content changes affect them
                                this.$nextTick(() => {
                                   if (window.feather) window.feather.replace();
                                });
                            }
                        }
                    });
                }
            };
        }
        window.kanbanApp = kanbanApp; // Make it global for Alpine x-data
    </script>
</body>
</html>
