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
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <a href="#" id="home-button" class="home-link">notd</a>
            <div class="calendar-panel">
                <div id="calendar"></div>
            </div>
            <div class="search-box">
                <input type="text" id="search" placeholder="Search...">
                <a href="#" id="advanced-search-link">Advanced search</a>
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
            <div id="backlinks-container" class="backlinks-container">
                <!-- Backlinks will be rendered here -->
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script src="js/app.js"></script>
</body>
</html> 