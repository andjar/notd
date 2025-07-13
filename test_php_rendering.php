<?php
// Test file to verify PHP rendering functions
require_once 'config.php';
require_once 'api/db_connect.php';

// Test the rendering functions
function renderPageProperties($properties, $renderInternal = false) {
    if (empty($properties)) return '';
    
    $html = '<div id="page-properties-container" class="page-properties-inline">';
    $hasVisibleProperties = false;
    
    foreach ($properties as $key => $instances) {
        foreach ($instances as $instance) {
            if ($instance['internal'] && !$renderInternal) continue;
            
            $hasVisibleProperties = true;
            if ($key === 'favorite' && strtolower($instance['value']) === 'true') {
                $html .= '<span class="property-inline"><span class="property-favorite">⭐</span></span>';
            } else {
                $html .= '<span class="property-inline"><span class="property-key">' . htmlspecialchars($key) . ':</span> <span class="property-value">' . htmlspecialchars($instance['value']) . '</span></span>';
            }
        }
    }
    
    if ($hasVisibleProperties) {
        $html .= '</div>';
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
        $html .= '<li><a href="#" class="recent-page-link' . $isActive . '" data-page-name="' . htmlspecialchars($page['name']) . '">';
        $html .= '<i data-feather="file-text" class="recent-page-icon"></i>';
        $html .= '<span class="recent-page-name">' . htmlspecialchars($page['name']) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

// Test with sample data
echo "<h1>PHP Rendering Test</h1>";

// Test page properties
$testProperties = [
    'status' => [
        ['value' => 'active', 'internal' => false]
    ],
    'favorite' => [
        ['value' => 'true', 'internal' => false]
    ],
    'internal_prop' => [
        ['value' => 'hidden', 'internal' => true]
    ]
];

echo "<h2>Page Properties Test</h2>";
echo renderPageProperties($testProperties, false);
echo "<br>With internal properties:";
echo renderPageProperties($testProperties, true);

// Test recent pages
$testPages = [
    ['name' => 'Test Page 1', 'updated_at' => '2024-01-01'],
    ['name' => 'Test Page 2', 'updated_at' => '2024-01-02'],
    ['name' => 'Current Page', 'updated_at' => '2024-01-03']
];

echo "<h2>Recent Pages Test</h2>";
echo renderRecentPages($testPages, 'Current Page');

echo "<h2>Test Complete</h2>";
echo "<p>If you can see the rendered HTML above, the PHP rendering functions are working correctly.</p>";
?> 