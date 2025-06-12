<?php
/**
 * Application Entry Point
 *
 * This file's sole purpose is to determine the initial page to load
 * and redirect the browser to the main application shell (page.php)
 * with the correct page name as a URL parameter.
 *
 * The default initial page is today's journal page in 'YYYY-MM-DD' format.
 */

// Determine the initial page name.
// This logic should be minimal and match what the JavaScript expects.
$initial_page_name = date('Y-m-d');

// Construct the URL for the main application shell.
// We use urlencode() to ensure the page name is safely passed in the URL.
$redirect_url = 'page.php?page=' . urlencode($initial_page_name);

// Perform the redirect.
// HTTP 302 is a temporary redirect, which is appropriate here as the "home"
// page changes daily.
header('Location: ' . $redirect_url, true, 302);

// Ensure no further code is executed after the redirect header is sent.
exit;