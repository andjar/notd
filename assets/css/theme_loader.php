<?php
// Emits the stylesheet links for the active theme. Pages that are not in the
// application root set $assetBase first (e.g. '../../' from an extension).
require_once __DIR__ . '/../../config.php';
$assetBase = $assetBase ?? '';
$theme = preg_replace('/[^A-Za-z0-9_-]/', '', ACTIVE_THEME);
echo '<link rel="stylesheet" href="' . $assetBase . 'assets/css/themes/' . $theme . ".css\">\n";
echo '<link rel="stylesheet" href="' . $assetBase . "assets/css/components/header.css\">\n";
