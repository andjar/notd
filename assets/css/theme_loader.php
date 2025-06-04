<?php
require_once __DIR__ . '/../../config.php';
$theme = ACTIVE_THEME;
// Add a comment to help with debugging
echo "<!-- Loading theme: {$theme} -->\n";
echo "<link rel=\"stylesheet\" href=\"assets/css/themes/{$theme}.css\">\n";
?> 