<?php
// Include the main configuration file to make constants available.
require_once 'config.php';
require_once 'api/db_connect.php';

// --- Failsafe Redirect ---
// Get the page name from the URL.
$pageName = isset($_GET['page']) ? trim($_GET['page']) : null;

// If no page name is provided, redirect to default page
if (empty($pageName)) {
    $default_page_name = date('Y-m-d');
    $redirect_url = 'page.php?page=' . urlencode($default_page_name);
    header('Location: ' . $redirect_url, true, 302);
    exit;
}

// --- Database Connection for Server-Side Rendering ---
try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $pdo = null;
}

// --- Server-Side Data Fetching ---
$pageData = null;
$pageProperties = [];
$recentPages = [];
$favorites = [];
$childPages = [];
$backlinks = [];

if ($pdo) {
    // Get current page data
    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?) AND active = 1");
    $stmt->execute([$pageName]);
    $pageData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pageData) {
        // Get page properties
        $stmt = $pdo->prepare("SELECT name, value, weight FROM Properties WHERE page_id = ? AND active = 1 ORDER BY created_at ASC");
        $stmt->execute([$pageData['id']]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($properties as $prop) {
            $key = $prop['name'];
            if (!isset($pageProperties[$key])) {
                $pageProperties[$key] = [];
            }
            $pageProperties[$key][] = [
                'value' => $prop['value'],
                'internal' => (int)($prop['weight'] ?? 2) > 2
            ];
        }
        
        // Get recent pages (last 7 updated pages)
        $stmt = $pdo->prepare("SELECT id, name, updated_at FROM Pages WHERE active = 1 ORDER BY updated_at DESC LIMIT 7");
        $stmt->execute();
        $recentPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get favorites
        $stmt = $pdo->prepare("SELECT DISTINCT P.id, P.name, P.updated_at FROM Pages P JOIN Properties Prop ON P.id = Prop.page_id WHERE Prop.name = 'favorite' AND Prop.value = 'true' AND P.active = 1 ORDER BY P.updated_at DESC");
        $stmt->execute();
        $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get child pages (direct children only)
        $prefix = rtrim($pageName, '/') . '/';
        $stmt = $pdo->prepare("SELECT id, name, updated_at FROM Pages WHERE LOWER(name) LIKE LOWER(?) || '%' AND LOWER(name) != LOWER(?) AND SUBSTR(LOWER(name), LENGTH(LOWER(?)) + 1) NOT LIKE '%/%' AND active = 1 ORDER BY name ASC");
        $stmt->execute([$prefix, $pageName, $prefix]);
        $childPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get backlinks
        $stmt = $pdo->prepare("SELECT N.id as note_id, N.content, N.page_id, P.name as page_name FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id JOIN Pages P ON N.page_id = P.id WHERE Prop.name = 'links_to_page' AND Prop.value = ? GROUP BY N.id ORDER BY N.updated_at DESC LIMIT 10");
        $stmt->execute([$pageName]);
        $backlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --- Journal Page Creation ---
// Only create journal pages for date-based pages that don't exist
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageName) && $pdo) {
    try {
        // Check if page exists
        $stmt = $pdo->prepare("SELECT id FROM Pages WHERE name = ?");
        $stmt->execute([$pageName]);
        $pageId = $stmt->fetchColumn();
        
        if (!$pageId) {
            // Create the date-based page
            $pdo->beginTransaction();
            $insertPage = $pdo->prepare("INSERT INTO Pages (name, created_at, updated_at) VALUES (?, datetime('now'), datetime('now'))");
            $insertPage->execute([$pageName]);
            $pageId = $pdo->lastInsertId();
            
            // Add journal type property
            $insertProp = $pdo->prepare("INSERT INTO Properties (page_id, name, value, weight) VALUES (?, 'type', 'journal', 2)");
            $insertProp->execute([$pageId]);
            $pdo->commit();
        }
    } catch (PDOException $e) {
        error_log("Error creating journal page: " . $e->getMessage());
    }
}

// --- Frontend Configuration ---
// Pass relevant backend configuration settings to the frontend JavaScript.
// This uses the PROPERTY_WEIGHTS constant from config.php.
$renderInternal = PROPERTY_WEIGHTS[3]['visible_in_view_mode'] ?? false;
$showInternalInEdit = PROPERTY_WEIGHTS[3]['visible_in_edit_mode'] ?? true;

// --- Helper Functions for Rendering ---
function renderPageTitle($pageName) {
    if (strpos($pageName, '/') === false) {
        // No namespace, just return the page name
        return htmlspecialchars($pageName);
    }
    
    // Split by namespace separator
    $parts = explode('/', $pageName);
    $html = '';
    
    for ($i = 0; $i < count($parts); $i++) {
        if ($i > 0) {
            $html .= ' / ';
        }
        
        if ($i === count($parts) - 1) {
            // Last part (current page) - no link
            $html .= htmlspecialchars($parts[$i]);
        } else {
            // Build the namespace path up to this point
            $namespacePath = implode('/', array_slice($parts, 0, $i + 1));
            $html .= '<a href="page.php?page=' . urlencode($namespacePath) . '" class="namespace-link">' . htmlspecialchars($parts[$i]) . '</a>';
        }
    }
    
    return $html;
}

function renderPageProperties($properties, $renderInternal = false) {
    if (empty($properties)) return '';
    
    $html = '<ul class="page-properties-pills">';
    $hasVisibleProperties = false;
    
    foreach ($properties as $key => $instances) {
        foreach ($instances as $instance) {
            if ($instance['internal'] && !$renderInternal) continue;
            
            $hasVisibleProperties = true;
            if ($key === 'favorite' && strtolower($instance['value']) === 'true') {
                $html .= '<li class="pill pill-property"><span class="property-favorite"><i data-feather="star"></i></span></li>';
            } else {
                $html .= '<li class="pill pill-property"><span class="property-key">' . htmlspecialchars($key) . '</span><span class="property-separator">:</span><span class="property-value">' . htmlspecialchars($instance['value']) . '</span></li>';
            }
        }
    }
    
    if ($hasVisibleProperties) {
        $html .= '</ul>';
        return $html;
    }
    
    return '';
}

function renderRecentPages($pages, $currentPageName) {
    if (empty($pages)) {
        return '<div class="no-pages-message">No recent pages</div>';
    }
    
    $html = '<ul class="recent-pages-list">';
    foreach ($pages as $page) {
        $isActive = ($page['name'] === $currentPageName) ? ' active' : '';
        $html .= '<li><a href="page.php?page=' . urlencode($page['name']) . '" class="recent-page-link' . $isActive . '">';
        $html .= '<i data-feather="file-text" class="recent-page-icon"></i>';
        $html .= '<span class="recent-page-name">' . htmlspecialchars($page['name']) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

function renderFavorites($favorites, $currentPageName) {
    if (empty($favorites)) {
        return '<div class="no-items-message">No favorite pages yet.</div>';
    }
    
    $html = '<ul class="recent-pages-list">';
    foreach ($favorites as $page) {
        $isActive = ($page['name'] === $currentPageName) ? ' active' : '';
        $html .= '<li><a href="page.php?page=' . urlencode($page['name']) . '" class="recent-page-link' . $isActive . '">';
        $html .= '<i data-feather="star" class="recent-page-icon"></i>';
        $html .= '<span class="recent-page-name">' . htmlspecialchars($page['name']) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

function renderChildPages($childPages) {
    $html = '<h3>Child Pages</h3><ul class="child-page-list">';
    foreach ($childPages as $page) {
        $displayName = strpos($page['name'], '/') !== false ? 
            substr($page['name'], strrpos($page['name'], '/') + 1) : 
            $page['name'];
        $html .= '<li><a href="page.php?page=' . urlencode($page['name']) . '" class="child-page-link">' . htmlspecialchars($displayName) . '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

function renderChildPagesSidebar($childPages) {
    if (empty($childPages)) {
        return '';
    }
    
    $html = '<h3>Child Pages</h3><ul class="recent-pages-list">';
    foreach ($childPages as $page) {
        $displayName = strpos($page['name'], '/') !== false ? 
            substr($page['name'], strrpos($page['name'], '/') + 1) : 
            $page['name'];
        $html .= '<li><a href="page.php?page=' . urlencode($page['name']) . '" class="recent-page-link">';
        $html .= '<i data-feather="file-text" class="recent-page-icon"></i>';
        $html .= '<span class="recent-page-name">' . htmlspecialchars($displayName) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

function renderBacklinks($backlinks) {
    if (empty($backlinks)) {
        return '<div class="no-items-message">No backlinks found.</div>';
    }
    
    $html = '<ul class="recent-pages-list">';
    foreach ($backlinks as $link) {
        $snippet = substr($link['content'], 0, 100);
        if (strlen($link['content']) > 100) $snippet .= '...';
        
        $html .= '<li><a href="page.php?page=' . urlencode($link['page_name']) . '" class="recent-page-link">';
        $html .= '<i data-feather="link" class="recent-page-icon"></i>';
        $html .= '<span class="recent-page-name">' . htmlspecialchars($link['page_name']) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageName); ?> - notd</title>
    <link rel="icon" href="assets/images/notd.svg" type="image/svg+xml">

    <!-- Fonts and Core Styles -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/libs/font-ubuntu.css">
    <link rel="stylesheet" href="assets/libs/font-awesome.min.css">
    
    <!-- Theme and App Styles -->
    <?php include 'assets/css/theme_loader.php'; ?>
    <link rel="stylesheet" href="assets/css/style_modular.css">
    
    <!-- Libraries -->
    <script src="assets/libs/feather.min.js"></script>
    <script src="assets/libs/marked.min.js"></script>
    <script src="assets/libs/Sortable.min.js"></script>
    <script src="assets/libs/sjcl.js"></script>
    <script type="module" src="assets/js/app.js"></script>
    <script src="assets/libs/alpine.min.js" defer></script>
    
    <script>
         window.APP_CONFIG = window.APP_CONFIG || {};
        // Pass server-side configuration to JavaScript
        window.APP_CONFIG = {
            RENDER_INTERNAL_PROPERTIES: <?php echo json_encode($renderInternal); ?>,
            SHOW_INTERNAL_PROPERTIES_IN_EDIT_MODE: <?php echo json_encode($showInternalInEdit); ?>
        };
    </script>
</head>
<body>
    <div id="splash-screen" 
         x-data="splashScreen()" 
         x-show="show" 
         x-init="init()"
         @click="hideSplash()"
         x-transition:leave="transition ease-in duration-300" 
         x-transition:leave-start="opacity-100" 
         x-transition:leave-end="opacity-0">
        <div class="time-date-container">
            <div id="clock" class="clock" x-text="time"></div>
            <div id="date" class="date" x-text="date"></div>
        </div>
        <div id="splash-background-bubbles-canvas">
            <template x-for="bubble in bubbles" :key="bubble.id">
                <div class="splash-bubble" 
                     :style="`width: ${bubble.size}px; height: ${bubble.size}px; background-color: ${bubble.color}; left: ${bubble.x}px; top: ${bubble.y}px; opacity: ${bubble.opacity}; transform: scale(${bubble.scale});`">
                </div>
            </template>
        </div>
        <div id="splash-orb-container">
            <div id="splash-orb-inner-core">
                <div id="splash-orb-text-container">
                    <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 374.77 123.57" class="splash-logo-svg">
                    <defs>
                        <style>
                        .cls-4 {
                            font-family: Ubuntu-Light, Ubuntu, sans-serif;
                            font-size: 108px;
                            font-weight: 300;
                        }
                        </style>
                    </defs>
                    <text class="cls-4" transform="translate(27.36 94.68) scale(1.34 1)" fill="var(--ls-splash-orb-text-color)"><tspan x="0" y="0" xml:space="preserve">n   td</tspan></text>
                    <g>
                        <g>
                        <g>
                            <path class="cls-1" fill="var(--ls-splash-orb-text-color)" d="M197.81,58.41c-.19,1.35-.66,5.06-1.3,7.07-1.19,3.71-2.87,6.53-4.16,8.37-2.84,4.05-5.98,6.47-7.57,7.59-1.65,1.16-4.97,3.25-9.66,4.52-7.06,1.92-12.92.83-14.99.36-7.27-1.64-12.06-5.49-14.23-7.51-1.67-1.55-7.07-6.89-9.14-15.7-.45-1.89-1.19-5.85-.59-10.82.82-6.84,3.68-11.59,4.8-13.29,3.84-5.87,8.78-9,11.69-10.5,2.93-1.51,5.6-2.28,7.49-2.7-1.79.54-12.87,4.05-18.41,15.67-.48,1-6.12,13.38.38,26.2,1.09,2.16,4.93,9.06,13.28,13.12,9.44,4.59,21.53,4.05,30.63-2.63,9.49-6.96,11.4-17.41,11.77-19.76"/>
                            <path class="cls-1" fill="var(--ls-splash-orb-text-color)" d="M199.77,67.14c-.82,3.09-1.96,5.65-3.01,7.62-1.02,1.9-3.15,5.44-6.86,8.98-4.34,4.13-8.63,6.15-10.34,6.9-8.85,3.83-17.03,2.93-20.85,2.21-6.58-1.24-11.16-4-12.96-5.18-2.06-1.35-5.85-4.14-9.25-8.85-4.31-5.96-5.75-11.88-6.29-15.14-.58-3.51-1.21-10.61,1.88-18.65.92-2.39,3.65-8.74,9.96-14.18,4.28-3.69,8.44-5.44,9.69-5.93,3-1.2,5.95-1.85,5.97-1.79.02.05-1.8.54-4.11,1.57-.7.31-2.74,1.25-5.11,2.81-3.82,2.53-6.27,5.24-7.16,6.28-3.14,3.64-4.75,7.07-5.36,8.49-.5,1.15-2.13,5.08-2.62,10.53-.41,4.53.15,7.98.3,8.83.97,5.56,3.17,9.41,4.27,11.31,2.13,3.66,4.35,5.98,4.99,6.65.7.72,2.38,2.37,4.85,4.08,5.83,4.03,11.58,5.24,13.81,5.62,5.53.94,9.92.3,11.56,0,1.83-.32,6.5-1.31,11.65-4.44,4.51-2.75,7.31-5.88,8.5-7.32,1.11-1.34,5.02-6.28,6.8-13.43.44-1.79.74-3.66.81-3.64.09.02-.19,3.2-1.11,6.68Z"/>
                        </g>
                        <g>
                            <path class="cls-3" fill="var(--ls-splash-orb-text-color)" d="M114.7,85.96c0-.13.85-9.27,9.24-15.55,1.53-1.14,5.91-4.33,12.48-4.82,7.88-.59,13.58,3.11,15.49,4.49,1.82,1.32,6.62,5.19,8.79,12.22.5,1.62,2.32,8.15-.58,15.52-.6,1.52-2.36,5.55-6.48,9.14-4.4,3.84-8.94,4.84-8.92,4.91.03.08,6.34-1.08,11.69-6.16,1.1-1.04,4.27-4.25,6.16-9.53.86-2.41,2.73-8.81.22-16.26-2.13-6.33-6.29-10.07-8.41-11.71-2.1-1.61-6.83-4.72-13.59-5.13-7.72-.47-13.28,2.86-15.11,4.07,0,0-5.9,3.91-8.76,10.17-.61,1.34-1.26,3.34-1.63,4.73-.23.88-.43,1.63-.51,2.62-.03.41-.09,1.33-.1,1.33,0,0,0-.04,0-.05Z"/>
                            <path class="cls-3" fill="var(--ls-splash-orb-text-color)" d="M112.4,83.86s0-.04.01-.08c1.36-8.49,6.61-14.06,7.3-14.77,1.24-1.28,4.47-4.36,9.65-6.35,6.53-2.51,12.25-1.8,14.59-1.38,2.44.44,7.62,1.72,12.6,5.87,1.76,1.47,7.33,6.49,9.17,15.17,1.34,6.33.05,11.56-.83,14.21,0,0-3.14,9.38-11.45,14.53-1.59.98-3.15,1.67-3.15,1.67-1.89.83-3.4,1.21-3.39,1.25.01.04,1.59-.31,3.37-.94.8-.28,3.1-1.14,5.82-2.9,3.57-2.31,5.76-4.81,6.24-5.38.82-.96,3.27-3.96,4.96-8.59,2.52-6.92,1.67-12.91,1.37-14.67-.44-2.6-1.72-7.92-5.9-13.09-5.25-6.49-11.83-8.73-14.21-9.41-2.32-.67-7.64-1.88-14.14-.42-1.68.38-9.15,2.23-15.09,8.95-2.96,3.35-4.48,6.72-5.16,8.45-1.06,2.68-1.53,6.09-1.76,7.88"/>
                        </g>
                        </g>
                        <g>
                        <g>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M130.84,66.72c1.15-.39,2.42-.72,3.81-.93-.22-.68-.42-1.41-.61-2.17-1.35.3-2.57.69-3.65,1.11.12.6.27,1.27.45,1.99Z"/>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M163.98,89.61c-.73-.07-1.48-.17-2.26-.3,0,1.22-.09,2.54-.33,3.93.62.07,1.29.13,2,.17.25-1.08.48-2.37.59-3.8Z"/>
                        </g>
                        <g>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M165.98,83.68c.19,1.17.28,2.29.32,3.37.85.02,1.77,0,2.73-.07.03-1.22-.01-2.3-.08-3.19-.99.01-1.98-.02-2.97-.11Z"/>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M136.35,61c1.21-.12,2.34-.16,3.35-.14-.22-1-.38-1.97-.49-2.93-1.01.05-2.07.16-3.18.34.07,1.02.19,1.94.31,2.73Z"/>
                        </g>
                        <g>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M141.24,65.75c-.38-.9-.7-1.8-.97-2.69-1.22-.05-2.39,0-3.49.11.19.82.42,1.61.66,2.37,1.34-.04,2.6.04,3.79.21Z"/>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M163.69,83.4c-.96-.15-1.91-.35-2.84-.6.23.8.54,2.12.73,3.8.69.12,1.52.24,2.48.32-.03-1.12-.14-2.3-.36-3.53Z"/>
                        </g>
                        <g>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M130.02,62.42c1.23-.44,2.43-.76,3.58-1-.08-.49-.21-1.37-.3-2.55-.87.24-2.09.62-3.49,1.22.05.86.13,1.64.22,2.34Z"/>
                            <path class="cls-2" fill="var(--ls-splash-orb-text-color)" d="M168.83,89.75c-.79.03-1.65.04-2.58,0-.1,1.38-.29,2.62-.52,3.72.77,0,1.58-.03,2.42-.09.32-1.27.54-2.49.68-3.64Z"/>
                        </g>
                        </g>
                    </g>
                    </svg>
                    </div>
            </div>
            <div id="splash-orb-perimeter-dots">
                    <template x-for="dot in dots" :key="dot.id">
                        <div class="splash-orb-dot" 
                             :style="`opacity: ${dot.opacity}; transform: translate(${dot.x}px, ${dot.y}px) scale(${dot.scale});`">
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-container" x-data="sidebarComponent()" x-init="init()">
        <!-- Left Sidebar -->
        <div id="left-sidebar-outer">
            <button id="toggle-left-sidebar-btn" class="sidebar-toggle-btn left-toggle" @click="toggleLeft()">
                <i x-feather="leftIcon()"></i>
            </button>
            <div id="left-sidebar" class="sidebar left-sidebar" :class="{ 'collapsed': leftCollapsed }">
                <div class="sidebar-content">
                    <div class="app-header">
                        <a href="/" id="app-title" class="app-title">
                            <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 374.77 123.57" class="app-logo">
                                <defs>
                                    <style>
                                        .cls-4 {
                                            font-family: Ubuntu-Light, Ubuntu, sans-serif;
                                            font-size: 108px;
                                            font-weight: 300;
                                        }
                                    </style>
                                </defs>
                                <text class="cls-4" transform="translate(27.36 94.68) scale(1.34 1)" fill="var(--ls-primary-text-color)"><tspan x="0" y="0" xml:space="preserve">n   td</tspan></text>
                                <g>
                                    <g>
                                        <g>
                                            <path class="cls-1" fill="#dfc3a0" d="M197.81,58.41c-.19,1.35-.66,5.06-1.3,7.07-1.19,3.71-2.87,6.53-4.16,8.37-2.84,4.05-5.98,6.47-7.57,7.59-1.65,1.16-4.97,3.25-9.66,4.52-7.06,1.92-12.92.83-14.99.36-7.27-1.64-12.06-5.49-14.23-7.51-1.67-1.55-7.07-6.89-9.14-15.7-.45-1.89-1.19-5.85-.59-10.82.82-6.84,3.68-11.59,4.8-13.29,3.84-5.87,8.78-9,11.69-10.5,2.93-1.51,5.6-2.28,7.49-2.7-1.79.54-12.87,4.05-18.41,15.67-.48,1-6.12,13.38.38,26.2,1.09,2.16,4.93,9.06,13.28,13.12,9.44,4.59,21.53,4.05,30.63-2.63,9.49-6.96,11.4-17.41,11.77-19.76"/>
                                            <path class="cls-1" fill="#dfc3a0" d="M199.77,67.14c-.82,3.09-1.96,5.65-3.01,7.62-1.02,1.9-3.15,5.44-6.86,8.98-4.34,4.13-8.63,6.15-10.34,6.9-8.85,3.83-17.03,2.93-20.85,2.21-6.58-1.24-11.16-4-12.96-5.18-2.06-1.35-5.85-4.14-9.25-8.85-4.31-5.96-5.75-11.88-6.29-15.14-.58-3.51-1.21-10.61,1.88-18.65.92-2.39,3.65-8.74,9.96-14.18,4.28-3.69,8.44-5.44,9.69-5.93,3-1.2,5.95-1.85,5.97-1.79.02.05-1.8.54-4.11,1.57-.7.31-2.74,1.25-5.11,2.81-3.82,2.53-6.27,5.24-7.16,6.28-3.14,3.64-4.75,7.07-5.36,8.49-.5,1.15-2.13,5.08-2.62,10.53-.41,4.53.15,7.98.3,8.83.97,5.56,3.17,9.41,4.27,11.31,2.13,3.66,4.35,5.98,4.99,6.65.7.72,2.38,2.37,4.85,4.08,5.83,4.03,11.58,5.24,13.81,5.62,5.53.94,9.92.3,11.56,0,1.83-.32,6.5-1.31,11.65-4.44,4.51-2.75,7.31-5.88,8.5-7.32,1.11-1.34,5.02-6.28,6.8-13.43.44-1.79.74-3.66.81-3.64.09.02-.19,3.2-1.11,6.68Z"/>
                                        </g>
                                        <g>
                                            <path class="cls-3" fill="#78b4c4" d="M114.7,85.96c0-.13.85-9.27,9.24-15.55,1.53-1.14,5.91-4.33,12.48-4.82,7.88-.59,13.58,3.11,15.49,4.49,1.82,1.32,6.62,5.19,8.79,12.22.5,1.62,2.32,8.15-.58,15.52-.6,1.52-2.36,5.55-6.48,9.14-4.4,3.84-8.94,4.84-8.92,4.91.03.08,6.34-1.08,11.69-6.16,1.1-1.04,4.27-4.25,6.16-9.53.86-2.41,2.73-8.81.22-16.26-2.13-6.33-6.29-10.07-8.41-11.71-2.1-1.61-6.83-4.72-13.59-5.13-7.72-.47-13.28,2.86-15.11,4.07,0,0-5.9,3.91-8.76,10.17-.61,1.34-1.26,3.34-1.63,4.73-.23.88-.43,1.63-.51,2.62-.03.41-.09,1.33-.1,1.33,0,0,0-.04,0-.05Z"/>
                                            <path class="cls-3" fill="#78b4c4" d="M112.4,83.86s0-.04.01-.08c1.36-8.49,6.61-14.06,7.3-14.77,1.24-1.28,4.47-4.36,9.65-6.35,6.53-2.51,12.25-1.8,14.59-1.38,2.44.44,7.62,1.72,12.6,5.87,1.76,1.47,7.33,6.49,9.17,15.17,1.34,6.33.05,11.56-.83,14.21,0,0-3.14,9.38-11.45,14.53-1.59.98-3.15,1.67-3.15,1.67-1.89.83-3.4,1.21-3.39,1.25.01.04,1.59-.31,3.37-.94.8-.28,3.1-1.14,5.82-2.9,3.57-2.31,5.76-4.81,6.24-5.38.82-.96,3.27-3.96,4.96-8.59,2.52-6.92,1.67-12.91,1.37-14.67-.44-2.6-1.72-7.92-5.9-13.09-5.25-6.49-11.83-8.73-14.21-9.41-2.32-.67-7.64-1.88-14.14-.42-1.68.38-9.15,2.23-15.09,8.95-2.96,3.35-4.48,6.72-5.16,8.45-1.06,2.68-1.53,6.09-1.76,7.88"/>
                                        </g>
                                    </g>
                                    <g>
                                        <g>
                                            <path class="cls-2" fill="#5f8f9c" d="M130.84,66.72c1.15-.39,2.42-.72,3.81-.93-.22-.68-.42-1.41-.61-2.17-1.35.3-2.57.69-3.65,1.11.12.6.27,1.27.45,1.99Z"/>
                                            <path class="cls-2" fill="#5f8f9c" d="M163.98,89.61c-.73-.07-1.48-.17-2.26-.3,0,1.22-.09,2.54-.33,3.93.62.07,1.29.13,2,.17.25-1.08.48-2.37.59-3.8Z"/>
                                        </g>
                                        <g>
                                            <path class="cls-2" fill="#5f8f9c" d="M165.98,83.68c.19,1.17.28,2.29.32,3.37.85.02,1.77,0,2.73-.07.03-1.22-.01-2.3-.08-3.19-.99.01-1.98-.02-2.97-.11Z"/>
                                            <path class="cls-2" fill="#5f8f9c" d="M136.35,61c1.21-.12,2.34-.16,3.35-.14-.22-1-.38-1.97-.49-2.93-1.01.05-2.07.16-3.18.34.07,1.02.19,1.94.31,2.73Z"/>
                                        </g>
                                        <g>
                                            <path class="cls-2" fill="#5f8f9c" d="M141.24,65.75c-.38-.9-.7-1.8-.97-2.69-1.22-.05-2.39,0-3.49.11.19.82.42,1.61.66,2.37,1.34-.04,2.6.04,3.79.21Z"/>
                                            <path class="cls-2" fill="#5f8f9c" d="M163.69,83.4c-.96-.15-1.91-.35-2.84-.6.23.8.54,2.12.73,3.8.69.12,1.52.24,2.48.32-.03-1.12-.14-2.3-.36-3.53Z"/>
                                        </g>
                                        <g>
                                            <path class="cls-2" fill="#5f8f9c" d="M130.02,62.42c1.23-.44,2.43-.76,3.58-1-.08-.49-.21-1.37-.3-2.55-.87.24-2.09.62-3.49,1.22.05.86.13,1.64.22,2.34Z"/>
                                            <path class="cls-2" fill="#5f8f9c" d="M168.83,89.75c-.79.03-1.65.04-2.58,0-.1,1.38-.29,2.62-.52,3.72.77,0,1.58-.03,2.42-.09.32-1.27.54-2.49.68-3.64Z"/>
                                        </g>
                                    </g>
                                </g>
                            </svg>
                        </a>
                    </div>
                    <div class="sidebar-section">
                        <div id="calendar-widget" class="calendar-widget" x-data="calendarComponent()" x-init="init()">
                            <div class="calendar-header">
                                <button id="prev-month-btn" class="arrow-btn" @click="prevMonth()"><i data-feather="chevron-left"></i></button>
                                <span id="current-month-year" class="month-year-display" x-text="monthYear()"></span>
                                <button id="next-month-btn" class="arrow-btn" @click="nextMonth()"><i data-feather="chevron-right"></i></button>
                                <button id="today-btn" class="arrow-btn today-btn" @click="goToday()">Today</button>
                            </div>
                            <div id="calendar-days-grid" class="calendar-grid calendar-days">
                                <template x-for="(cell, index) in calendarCells" :key="index">
                                    <div
                                        :class="cell.weekday ? 'calendar-weekday' : 'calendar-day' + (cell.empty ? ' empty' : '') + (cell.today ? ' today' : '') + (cell.hasPage ? ' has-content' : '') + (cell.isCurrentPage ? ' current-page' : '')"
                                        x-text="cell.weekday ? cell.label : (cell.empty ? '' : cell.day)"
                                        @click="!cell.weekday && !cell.empty ? dayClick(cell) : null"
                                    ></div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="search-section">
                        <input type="text" id="global-search-input" placeholder="Search..." class="search-input">
                        <div id="search-results" class="search-results"></div>
                    </div>
                    <div class="sidebar-section">
                        <div class="recent-pages">
                            <h3>Recent Pages</h3>
                            <div id="page-list">
                                <?php echo renderRecentPages($recentPages, $pageName); ?>
                            </div>
                        </div>
                    </div>
                    <div class="sidebar-footer">
                        <button id="open-page-search-modal-btn" class="action-button full-width-button">
                            <i data-feather="search"></i> Search or Create Page
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div id="main-content" class="main-content">
            <div class="page-header-container">
                <h1 id="page-title" class="page-title">
                    <span class="page-title-content">
                        <?php echo renderPageTitle($pageName); ?>
                        <i id="page-properties-gear" class="page-title-gear" data-feather="settings" title="Page Properties"></i>
                    </span>
                </h1>
                <div class="page-header-actions">
                    <span class="favorite-toggle" id="favorite-toggle" title="Toggle favorite">☆</span>
                    <div class="page-properties">
                        <?php
                        // Render properties except 'favorite'
                        foreach ($pageProperties as $key => $instances) {
                            if ($key === 'favorite') continue;
                            foreach ($instances as $instance) {
                                if ($instance['internal'] && !$renderInternal) continue;
                                echo '<span>' . htmlspecialchars($key) . ':' . htmlspecialchars($instance['value']) . '</span> ';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div id="page-content" class="page-content" style="display: none;">
                <!-- Page content will be rendered here by JavaScript -->
            </div>
            <div id="note-focus-breadcrumbs-container"></div>
            <div id="notes-container" class="outliner" x-data="{ notes: [] }" x-init="initializeDragAndDrop()">
                <template x-for="note in notes" :key="note.id">
                    <div class="note-item"
                        x-data="noteComponent(note, 0)"
                        :data-note-id="note.id"
                        :style="`--nesting-level: ${nestingLevel}`"
                        :class="{ 'has-children': note.children && note.children.length > 0, 'collapsed': note.collapsed, 'encrypted-note': note.is_encrypted, 'decrypted-note': note.is_encrypted && note.content && !note.content.startsWith('{') }">

                        <div class="note-header-row">
                            <div class="note-controls">
                                <span class="note-collapse-arrow" x-show="note.children && note.children.length > 0" @click="toggleCollapse()" :data-collapsed="note.collapsed ? 'true' : 'false'">
                                    <i data-feather="chevron-right"></i>
                                </span>
                                <span class="note-bullet" :data-note-id="note.id"></span>
                            </div>
                            <div class="note-content-wrapper">
                                <div class="note-content rendered-mode"
                                    x-ref="contentDiv"
                                    :data-note-id="note.id"
                                    :data-raw-content="note.content"
                                    x-html="parseContent(note.content)"
                                    @click="editNote()"
                                    @blur="isEditing = false"
                                    @input="handleInput($event)"
                                    @paste="handlePaste($event)"
                                    @keydown="handleNoteKeyDown($event)">
                                </div>
                                <div class="note-attachments"></div>
                            </div>
                        </div>

                        <div class="note-children" :class="{ 'collapsed': note.collapsed }">
                            <template x-for="childNote in note.children" :key="childNote.id">
                                <div class="note-item"
                                    x-data="noteComponent(childNote, $parent.nestingLevel + 1)"
                                    :data-note-id="childNote.id"
                                    :style="`--nesting-level: ${$parent.nestingLevel + 1}`"
                                    :class="{ 'has-children': childNote.children && childNote.children.length > 0, 'collapsed': childNote.collapsed, 'encrypted-note': childNote.is_encrypted, 'decrypted-note': childNote.is_encrypted && childNote.content && !childNote.content.startsWith('{') }">

                                    <div class="note-header-row">
                                        <div class="note-controls">
                                            <span class="note-collapse-arrow" x-show="childNote.children && childNote.children.length > 0" @click="toggleCollapse()" :data-collapsed="childNote.collapsed ? 'true' : 'false'">
                                                <i data-feather="chevron-right"></i>
                                            </span>
                                            <span class="note-bullet" :data-note-id="childNote.id"></span>
                                        </div>
                                        <div class="note-content-wrapper">
                                            <div class="note-content rendered-mode"
                                                x-ref="contentDiv"
                                                :data-note-id="childNote.id"
                                                :data-raw-content="childNote.content"
                                                x-html="parseContent(childNote.content)"
                                                @click="editNote()"
                                                @blur="isEditing = false"
                                                @input="handleInput($event)"
                                                @paste="handlePaste($event)"
                                                @keydown="handleNoteKeyDown($event)">
                                            </div>
                                            <div class="note-attachments"></div>
                                        </div>
                                    </div>

                                    <div class="note-children" :class="{ 'collapsed': childNote.collapsed }">
                                        <!-- Recursive rendering of grand-children -->
                                        <template x-for="grandChildNote in childNote.children" :key="grandChildNote.id">
                                            <div class="note-item"
                                                x-data="noteComponent(grandChildNote, $parent.$parent.nestingLevel + 2)"
                                                :data-note-id="grandChildNote.id"
                                                :style="`--nesting-level: ${$parent.$parent.nestingLevel + 2}`"
                                                :class="{ 'has-children': grandChildNote.children && grandChildNote.children.length > 0, 'collapsed': grandChildNote.collapsed, 'encrypted-note': grandChildNote.is_encrypted, 'decrypted-note': grandChildNote.is_encrypted && grandChildNote.content && !grandChildNote.content.startsWith('{') }">

                                                <div class="note-header-row">
                                                    <div class="note-controls">
                                                        <span class="note-collapse-arrow" x-show="grandChildNote.children && grandChildNote.children.length > 0" @click="toggleCollapse()" :data-collapsed="grandChildNote.collapsed ? 'true' : 'false'">
                                                            <i data-feather="chevron-right"></i>
                                                        </span>
                                                        <span class="note-bullet" :data-note-id="grandChildNote.id"></span>
                                                    </div>
                                                    <div class="note-content-wrapper">
                                                        <div class="note-content rendered-mode"
                                                            x-ref="contentDiv"
                                                            :data-note-id="grandChildNote.id"
                                                            :data-raw-content="grandChildNote.content"
                                                            x-html="parseContent(grandChildNote.content)"
                                                            @click="editNote()"
                                                            @blur="isEditing = false"
                                                            @input="handleInput($event)"
                                                            @paste="handlePaste($event)"
                                                            @keydown="handleNoteKeyDown($event)">
                                                        </div>
                                                        <div class="note-attachments"></div>
                                                    </div>
                                                </div>

                                                <div class="note-children" :class="{ 'collapsed': grandChildNote.collapsed }">
                                                    <!-- Further nested children would go here, following the same pattern -->
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <button id="add-root-note-btn" class="action-button round-button" title="Add new note to page">
                <i data-feather="plus"></i>
            </button>
        </div>

        <!-- Right Sidebar -->
        <div id="right-sidebar-outer">
            <button id="toggle-right-sidebar-btn" class="sidebar-toggle-btn right-toggle" @click="toggleRight()">
                <i x-feather="rightIcon()"></i>
            </button>
            <div id="right-sidebar" class="sidebar right-sidebar" :class="{ 'collapsed': rightCollapsed }">
                <div class="sidebar-content">
                    <div id="right-sidebar-content">
                        <div class="sidebar-section">
                            <div class="favorites">
                                <h3>Favorites</h3>
                                <div id="favorites-container">
                                    <?php echo renderFavorites($favorites, $pageName); ?>
                                </div>
                            </div>
                        </div>
                        <div class="sidebar-section">
                            <div id="backlinks-container" class="backlinks-sidebar">
                                <div class="backlinks-header">
                                    <h3>Backlinks<?php echo !empty($backlinks) ? ' <span id="open-backlinks-modal-btn" class="backlinks-expand-text" title="View all backlinks">+</span>' : ''; ?></h3>
                                </div>
                                <div id="backlinks-list" class="backlinks-list">
                                    <?php echo renderBacklinks($backlinks); ?>
                                </div>
                            </div>
                        </div>
                        <div class="sidebar-section">
                            <div id="child-pages-sidebar" class="child-pages-sidebar">
                                <?php echo renderChildPagesSidebar($childPages); ?>
                            </div>
                        </div>
                        <div class="sidebar-section extensions-section">
                            <div id="extension-icons-container"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Save Status Indicator -->
        <div id="save-status-indicator" title="All changes saved"></div>
        <button id="toggle-splash-btn" class="action-button round-button absolute-bottom-right" title="Toggle Splash Screen">
            <i data-feather="pause-circle"></i>
        </button>
    </div>

    <!-- Password Modal for Encrypted Pages -->
    <div id="password-modal" class="generic-modal">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 class="generic-modal-title">Encrypted Page</h2>
                <button id="password-modal-close" class="modal-close-x" aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <p>This page is encrypted. Please enter the password to view it.</p>
            <input type="password" id="password-input" placeholder="Password" class="full-width-input">
            <div class="generic-modal-actions">
                <button id="password-submit" class="button">Decrypt</button>
                <button id="password-cancel" class="button button-secondary">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Encryption Password Modal -->
    <div id="encryption-password-modal" class="generic-modal">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 class="generic-modal-title">Set Encryption Password</h2>
                <button id="encryption-modal-close" class="modal-close-x" aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Enter a password to encrypt this page:</p>
                <input type="password" id="new-encryption-password" class="generic-modal-input-field" placeholder="New Password">
                <p>Confirm your password:</p>
                <input type="password" id="confirm-encryption-password" class="generic-modal-input-field" placeholder="Confirm Password">
                <p id="encryption-password-error" class="error-message" style="display: none;"></p>
            </div>
            <div class="generic-modal-actions">
                <button id="confirm-encryption-btn" class="button primary-button">Encrypt</button>
                <button id="cancel-encryption-btn" class="button secondary-button">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Page Properties Modal -->
    <div id="page-properties-modal" class="generic-modal">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 id="page-properties-modal-title" class="generic-modal-title">Page Properties</h2>
                <div class="modal-header-icons">
                    <button id="page-properties-modal-close" class="modal-close-x" aria-label="Close">
                        <i data-feather="x"></i>
                    </button>
                </div>
            </div>
            <div id="page-properties-list" class="page-properties-list"></div>
            <div class="generic-modal-actions">
                <button id="page-encryption-button" class="button" title="Encrypt Page">
                    Encrypt
                </button>
                <button id="save-page-content-btn" class="button">Save</button>
            </div>
        </div>
    </div>

    <div id="page-search-modal" class="generic-modal">
        <div class="generic-modal-content page-search-modal-styling">
            <input type="text" id="page-search-modal-input" class="generic-modal-input-field" placeholder="Type to search or create...">
            <ul id="page-search-modal-results" class="page-search-results-list"></ul>
            <div class="generic-modal-actions">
                <button id="page-search-modal-cancel" class="button secondary-button">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Note Search Modal -->
    <div id="note-search-modal" class="generic-modal">
        <div class="generic-modal-content page-search-modal-styling"> <!-- Reusing styling for now -->
            <input type="text" id="note-search-modal-input" class="generic-modal-input-field" placeholder="Search notes...">
            <ul id="note-search-modal-results" class="page-search-results-list"></ul> <!-- Reusing styling for now -->
            <div class="generic-modal-actions">
                <button id="note-search-modal-cancel" class="button secondary-button">Cancel</button>
            </div>
        </div>
    </div>
    
    <div id="image-viewer-modal" class="generic-modal image-viewer">
        <div class="generic-modal-content">
             <button id="image-viewer-modal-close" class="modal-close-x" aria-label="Close">
                <i data-feather="x"></i>
            </button>
            <img id="image-viewer-modal-img" src="" alt="Full size view">
        </div>
    </div>

    <!-- Backlinks Modal -->
    <div id="backlinks-modal" class="generic-modal">
        <div class="generic-modal-content">
            <div class="generic-modal-header">
                <h2 class="generic-modal-title">Backlinks to "<?php echo htmlspecialchars($pageName); ?>"</h2>
                <button id="backlinks-modal-close" class="modal-close-x" aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <div id="backlinks-modal-content" class="backlinks-modal-content">
                <!-- Backlinks will be populated here by JavaScript -->
            </div>
        </div>
    </div>


    <!-- Mobile Bottom Toolbar -->
    <div class="mobile-bottom-toolbar" id="mobile-bottom-toolbar">
        <button class="mobile-bottom-toolbar__btn" id="mobile-toggle-left-sidebar-btn" title="Open Menu">
            <i data-feather="menu"></i>
            <span class="mobile-bottom-toolbar__btn-label">Menu</span>
        </button>
        <button class="mobile-bottom-toolbar__btn" id="mobile-add-root-note-btn" title="Add Note">
            <i data-feather="plus"></i>
            <span class="mobile-bottom-toolbar__btn-label">Add</span>
        </button>
        <button class="mobile-bottom-toolbar__btn" id="mobile-toggle-right-sidebar-btn" title="Open Info">
            <i data-feather="info"></i>
            <span class="mobile-bottom-toolbar__btn-label">Info</span>
        </button>
    </div>
</body>
</html>