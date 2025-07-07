<?php
require_once __DIR__ . '/../../config.php';
$theme = ACTIVE_THEME;
$themePath = APP_BASE_URL . '/assets/css/themes/' . $theme . '.css';
// Add a comment to help with debugging
echo "<!-- Loading theme: {$theme} from {$themePath} -->\n";
echo "<link rel=\"stylesheet\" href=\"{$themePath}\">\n";
echo "<link rel=\"stylesheet\" href=\"assets/css/components/header.css\">\n";
?> 