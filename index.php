<?php
// Check if the database exists, if not run init.php
$dbPath = __DIR__ . '/db/notes.db';
if (!file_exists($dbPath)) {
    include __DIR__ . '/db/init.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>notd</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/libs/marked.min.js"></script>
    <script src="js/libs/highlight.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
</head>
<body>
    <button id="sidebar-toggle" class="sidebar-toggle" aria-label="Toggle Sidebar"></button>
    <button id="right-sidebar-toggle" class="sidebar-toggle" aria-label="Toggle Right Sidebar"></button>
    <div class="container">
        <div class="sidebar">
            <a href="#" id="home-button" class="home-link">notd</a>
            <div class="calendar-panel">
                <div id="calendar"></div>
            </div>
            <div class="search-box">
                <input type="text" id="search" placeholder="Search...">
                <div class="search-links">
                    <a href="#" id="advanced-search-link">Advanced search</a>
                    <a href="#" id="toolbar-toggle">Hide toolbar</a>
                </div>
            </div>
            <div class="recent-pages">
                <ul id="recent-pages-list"></ul>
            </div>
            <button id="new-page" class="btn-primary">Add New Page</button>
        </div>
        <div class="main-content">
            <div class="page-header">
                <h1 id="page-title"></h1>
                <div id="page-properties"></div>
            </div>
            <div id="outline-container">
                <!-- Notes will be rendered here -->
            </div>
            <button id="new-note" class="btn-primary" onclick="createNote()">Add New Note</button>
            
            <div id="backlinks-section" class="backlinks-section">
                <button id="backlinks-toggle" class="backlinks-toggle-button" aria-expanded="false">
                    <span class="toggle-arrow">▶</span> Backlinks
                </button>
                <div id="backlinks-container" class="backlinks-container" style="display: none;">
                    <!-- Backlinks will be rendered here by renderBacklinks -->
                    <!-- Placeholder for "Showing X out of Y" and "Load More" will be added in a later step -->
                </div>
            </div>
        </div>
        <div class="right-sidebar collapsed">
            <div class="sql-query-container">
                <h3>Custom Query</h3>
                <div class = "sql-query-button-container">
                    <button id="edit-query-btn" class="btn-secondary" aria-label="Edit Query">✎</button>
                    <button id="run-sql-query" class="btn-secondary">Run Query</button>
                </div>
                <div class="query-frequency-container"></div>
            </div>
            <div id="right-sidebar-notes-content">
                <!-- Notes fetched by the query will be displayed here -->
            </div>
        </div>
    </div>
    <script src="js/libs/Sortable.min.js"></script>
    <script src="js/state.js"></script>
    <script src="js/utils.js"></script>
    <script src="js/api.js"></script>
    <script src="js/ui.js"></script>
    <script src="js/render.js"></script>
    <script src="js/app.js"></script>
</body>
</html> 