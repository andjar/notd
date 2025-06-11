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
</head>
<body>
    <div class="app-container kanban-app-container">
        <header class="kanban-header">
            <h1>Kanban Task Board</h1>
            <!-- Maybe a link back to the main app -->
            <a href="/" class="action-button">Back to Main App</a>
        </header>
        <main id="kanban-root" class="kanban-root">
            <!-- Kanban board will be rendered here by JavaScript -->
            <div class="loading-message">Loading Kanban board...</div>
        </main>
    </div>

    <!-- SCRIPTS -->
    <!-- Libraries -->
    <script src="../../assets/libs/feather.min.js"></script>
    <script src="../../assets/libs/Sortable.min.js"></script>
    <script src="../../assets/libs/sjcl.js"></script> <!-- If encryption/decryption is ever needed on this page -->


    <!-- Application-specific JavaScript -->
    <!-- utils.js might be needed by other scripts -->
    <script type="module" src="../../assets/js/utils.js"></script>
    
    <!-- API client is crucial -->
    <script type="module" src="../../assets/js/api_client.js"></script>
    
    <!-- Kanban-specific JS -->
    <!-- state.js might be needed if kanban-board uses it, or pass data directly -->
    <script type="module" src="./ui.js"></script>
    <script type="module" src="./main.js"></script>

    <script type="module">
        import { initializeKanban } from './main.js';

        // Initialize Feather Icons
        feather.replace();

        // Initialize the Kanban Board once the DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            initializeKanban();
        });
    </script>
</body>
</html>
