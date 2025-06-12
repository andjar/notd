<?php
// FILE: page.php

require_once 'config.php';

// This file is now a simple HTML shell.
// All logic for loading/creating pages is handled by assets/js/app.js.
// The JavaScript will read the '?page=' parameter from the URL.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>notd</title> <!-- Title will be updated by JS -->
    <base href="/"> <!-- Set the base for all relative URLs, crucial for JS routing -->

    <!-- Stylesheets -->
    <link rel="icon" href="assets/css/icon-logo.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
    
    <!-- Theme Loading -->
    <?php include 'assets/css/theme_loader.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/icons.css">

    <!-- JavaScript Libraries -->
    <script src="assets/libs/feather.min.js"></script>
    <script src="assets/libs/marked.min.js"></script>
    <script src="assets/libs/Sortable.min.js"></script>
    <script src="assets/libs/sjcl.js"></script>
</head>
<body>
    <!-- Splash Screen -->
    <div id="splash-screen">
        <canvas id="splash-background-bubbles-canvas"></canvas>
        <div id="splash-orb-container">
            <div id="splash-orb-inner-core">
                <div id="splash-orb-perimeter-dots"></div>
                <div id="splash-orb-text-container">
                    <svg class="splash-logo-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg>
                    <div id="splash-orb-text">notd</div>
                </div>
            </div>
        </div>
        <div class="time-date-container">
            <div id="clock"></div>
            <div id="date"></div>
        </div>
    </div>

    <!-- Main App Container -->
    <div class="app-container">
        <!-- Left Sidebar -->
        <aside id="left-sidebar" class="sidebar left-sidebar collapsed">
            <div class="sidebar-content">
                <header class="app-header">
                    <a href="/" id="app-title-main" class="app-title">notd</a>
                </header>
                <section class="search-section">
                    <input type="search" id="global-search-input" placeholder="Filter pages..." class="search-input">
                    <div id="search-results" class="search-results"></div>
                </section>
                <nav class="recent-pages">
                    <ul id="page-list"></ul>
                </nav>
            </div>
            <footer class="sidebar-footer">
                <button id="open-page-search-modal-btn" class="button primary-button full-width-button">
                    <i data-feather="search"></i>  Find or Create Page
                </button>
            </footer>
        </aside>
        <button id="toggle-left-sidebar-btn" class="sidebar-toggle-btn" title="Show/Hide Left Sidebar">☰</button>

        <!-- Main Content -->
        <main id="main-content" class="main-content">
            <header class="page-header">
                <h1 id="page-title"></h1>
            </header>
            <div id="page-properties"></div>
            <div id="note-focus-breadcrumbs-container"></div>
            <div id="notes-container" class="outliner"></div>
            <button id="add-root-note-btn" class="action-button primary-button" title="Add a new note to the page">+</button>
        </main>

        <!-- Right Sidebar -->
        <aside id="right-sidebar" class="sidebar right-sidebar collapsed">
             <div class="sidebar-content">
                <section id="favorites-section">
                    <h3>Favorites</h3>
                    <div id="favorites-container"></div>
                </section>
                <section id="backlinks-section" class="backlinks-section">
                    <h4>Backlinks</h4>
                    <div id="backlinks-container"></div>
                </section>
                <section class="extensions-section">
                    <h4>Extensions</h4>
                    <div id="extension-icons-container"></div>
                </section>
            </div>
        </aside>
        <button id="toggle-right-sidebar-btn" class="sidebar-toggle-btn" title="Show/Hide Right Sidebar">☰</button>
    </div>

    <!-- Modals -->
    <div id="page-properties-modal" class="generic-modal">
        <div class="generic-modal-content">
            <header class="generic-modal-header">
                <h2 id="page-properties-modal-title" class="generic-modal-title">Page Properties</h2>
                <div class="modal-header-icons">
                     <button class="modal-icon-button" id="page-encryption-icon" title="Set page encryption"><i data-feather="key"></i></button>
                     <button class="modal-close-x" id="page-properties-modal-close" aria-label="Close modal"><i data-feather="x"></i></button>
                </div>
            </header>
            <div id="page-properties-list" class="page-properties-list"></div>
            <footer class="generic-modal-actions">
                <button class="button" id="add-page-property-btn">+ Add Property</button>
            </footer>
        </div>
    </div>
    <div id="page-search-modal" class="generic-modal">
        <div class="generic-modal-content page-search-modal-styling">
            <header class="generic-modal-header">
                <h2 class="generic-modal-title">Search or Create Page</h2>
                <button class="modal-close-x"><i data-feather="x"></i></button>
            </header>
            <input type="search" id="page-search-modal-input" class="generic-modal-input-field" placeholder="Type to search or create...">
            <ul id="page-search-modal-results" class="page-search-results-list"></ul>
            <footer class="generic-modal-actions">
                <button id="page-search-modal-cancel" class="button">Cancel</button>
            </footer>
        </div>
    </div>
    <div id="generic-confirm-modal" class="generic-modal">
        <!-- Content for generic confirm modal -->
    </div>
    <div id="generic-input-modal" class="generic-modal">
        <!-- Content for generic input modal -->
    </div>
    <div id="image-viewer-modal" class="generic-modal image-viewer-modal">
        <span class="image-viewer-close" id="image-viewer-modal-close">×</span>
        <img class="generic-modal-content" id="image-viewer-modal-img" src="" alt="Image Preview">
    </div>

    <!-- UI Indicators -->
    <div id="save-status-indicator" title="All changes saved" class="status-hidden"></div>
    <button id="toggle-splash-btn" title="Toggle Splash Screen"><i data-feather="coffee"></i></button>

    <!-- Main Application Scripts (ES Modules) -->
    <script type="module" src="assets/js/app.js"></script>
    <script src="assets/js/splash.js"></script>
</body>
</html>