<?php

// --- Configuration & Setup ---
define('DEFAULT_CONFIG_PATH', 'config.json');
define('DEFAULT_API_URL', 'http://localhost:8080/api'); // Replace with your notes app API

// --- Logging ---
$verbose = false;

function log_message($level, $message) {
    global $verbose;
    if ($level === 'DEBUG' && !$verbose) {
        return;
    }
    $timestamp = date('Y-m-d H:i:s');
    $output = "[$timestamp] [$level] $message\n";
    if (php_sapi_name() === 'cli') {
        // CLI: Use STDOUT/STDERR if defined, else echo
        if (defined('STDOUT') && ($level === 'INFO' || $level === 'DEBUG')) {
            fwrite(STDOUT, $output);
        } elseif (defined('STDERR') && ($level === 'ERROR' || $level === 'WARNING')) {
            fwrite(STDERR, $output);
        } else {
            echo $output;
        }
    } else {
        // Web: Only log errors/warnings to error_log, never echo
        if ($level === 'ERROR' || $level === 'WARNING') {
            error_log($output);
        }
        // Do not echo anything in web context
    }
}

// --- SimplePie Library Loading ---
$simplepie_path = __DIR__ . '/SimplePie.php';
if (!file_exists($simplepie_path)) {
    $error_msg = "SimplePie.php not found at: $simplepie_path";
    log_message('ERROR', $error_msg);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $error_msg . "\n");
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Required library not found']);
        exit(1);
    }
}
require_once $simplepie_path;
log_message('INFO', "Loaded SimplePie library from: $simplepie_path");

// --- Helper Functions ---

function generate_item_hash($item_id, $item_link, $item_title) {
    if (!empty($item_id)) {
        return hash('sha256', $item_id);
    }
    return hash('sha256', $item_link . $item_title);
}

function make_api_request($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init();
    $default_headers = ['Accept: application/json'];
    $all_headers = array_merge($default_headers, $headers);

    if ($method === 'POST' && $data !== null) {
        $payload = json_encode($data);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $all_headers[] = 'Content-Type: application/json';
        $all_headers[] = 'Content-Length: ' . strlen($payload);
    } elseif ($method === 'GET' && is_array($data)) {
        $url .= '?' . http_build_query($data);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $all_headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // For production, ensure this is true and CA paths are correct
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    log_message('DEBUG', "Making API request: $method $url" . ($payload ?? ''));

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        log_message('ERROR', "cURL Error for $method $url: $curl_error");
        return ['http_code' => 0, 'body' => null, 'error' => $curl_error];
    }

    log_message('DEBUG', "API Response ($method $url): HTTP $http_code - Body: " . substr($response_body, 0, 200) . (strlen($response_body) > 200 ? '...' : ''));
    return ['http_code' => $http_code, 'body' => $response_body, 'error' => null];
}

function check_duplicate($api_url, $item_hash) {
    $query_endpoint = rtrim($api_url, '/') . '/v1/query_notes.php';
    
    // Construct the SQL query.
    // IMPORTANT: Ensure $item_hash is safe. Hashes are generally safe, but for other values, parameter binding would be essential.
    // The Query Notes API itself should have strong validation against SQL injection.
    // Assuming 'rss_item_hash' is stored as an internal property.
    // Note: The specific table and column names (N.id, P.entity_id, P.entity_type, P.name, P.value, P.internal)
    // are based on common assumptions about the DB schema for notes and properties.
    // These might need adjustment if the actual schema differs.
    $sql_query = "SELECT N.id FROM Notes N JOIN Properties P ON N.id = P.entity_id WHERE P.entity_type = 'note' AND P.name = 'rss_item_hash' AND P.value = '" . SQLite3::escapeString($item_hash) . "' AND P.internal = 1 LIMIT 1";

    $payload = ['sql_query' => $sql_query];
    
    log_message('DEBUG', "Duplicate check query for hash $item_hash: $sql_query");
    $response = make_api_request($query_endpoint, 'POST', $payload);

    if ($response['error'] || $response['http_code'] >= 300) { // Consider 2xx as success
        log_message('ERROR', "API request failed during duplicate check for hash $item_hash. HTTP Code: {$response['http_code']}. Body: {$response['body']}. Error: {$response['error']}");
        return true; // Assume duplicate to be safe on error
    }

    $result_data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message('ERROR', "Failed to decode JSON response from duplicate check for hash $item_hash: " . json_last_error_msg() . " - Response: {$response['body']}");
        return true; // Assume duplicate
    }

    // Based on API_SPECIFICATION.md for query_notes.php:
    // Success: { "status": "success", "data": [ ...notes... ] }
    // Error:   { "status": "error", "message": "..." }
    if (isset($result_data['status']) && $result_data['status'] === 'success') {
        if (isset($result_data['data']) && is_array($result_data['data'])) {
            return !empty($result_data['data']); // True if 'data' array is not empty (duplicate found)
        } else {
            log_message('WARNING', "Duplicate check for hash $item_hash succeeded but 'data' field is missing or not an array. Response: {$response['body']}");
            return true; // Assume duplicate to be safe
        }
    } elseif (isset($result_data['status']) && $result_data['status'] === 'error') {
        log_message('ERROR', "Duplicate check API returned an error for hash $item_hash. Message: " . ($result_data['message'] ?? 'Unknown error') . ". Response: {$response['body']}");
        return true; // Assume duplicate
    } else {
        log_message('WARNING', "Duplicate check response for hash $item_hash has unknown structure. Response: {$response['body']}");
        return true; // Assume duplicate
    }
}

function create_note($api_url, $page_name, $title, $content_markdown, $feed_url, $item_link, $item_hash, $item_date) {
    // Changed $page_id to $page_name
    $append_endpoint = rtrim($api_url, '/') . '/v1/append_to_page.php'; // Changed endpoint

    // Append internal properties to content_markdown
    // Note: $content_markdown might already contain the title and basic structure.
    // This function will now append the machine-readable properties.
    $properties_markdown = "\n\n---\n"; // Start of properties block
    $properties_markdown .= "rss:: true\n";
    $properties_markdown .= "rss_item_hash:: " . $item_hash . "\n";
    $properties_markdown .= "rss_feed_url:: " . $feed_url . "\n";
    $properties_markdown .= "rss_item_link:: " . $item_link . "\n";
    $properties_markdown .= "published_date:: " . $item_date . "\n";

    $final_content = $content_markdown . $properties_markdown;

    // Payload for append_to_page.php
    $payload = [
        'page_name' => $page_name,
        'notes'     => $final_content // API handles string as single note content
    ];

    $response = make_api_request($append_endpoint, 'POST', $payload);

    // Handle response from append_to_page.php
    // Expected success: { "status": "success", "data": { "message": "...", "appended_notes": [...] } }
    if ($response['error'] || $response['http_code'] >= 300) {
        log_message('ERROR', "API request failed during note append for '$title' to page '$page_name'. HTTP Code: {$response['http_code']}. Error: {$response['error']}. Body: {$response['body']}");
        return false;
    }

    $responseData = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($responseData['status'])) {
        log_message('ERROR', "Failed to decode JSON response or status missing for note append '$title' to page '$page_name'. Body: {$response['body']}");
        return false;
    }

    if ($responseData['status'] === 'success' && isset($responseData['data']['appended_notes']) && !empty($responseData['data']['appended_notes'])) {
        log_message('INFO', "Successfully appended note for '$title' to page '$page_name'.");
        return true;
    } else {
        log_message('ERROR', "API call to append note for '$title' to page '$page_name' did not report success or appended_notes missing/empty. Status: " . ($responseData['status'] ?? 'N/A') . ". Message: " . ($responseData['message'] ?? $responseData['data']['message'] ?? 'N/A') . ". Body: {$response['body']}");
        return false;
    }
}

function fetch_and_process_feed($api_url, $feed_url, $page_name_for_feed) { // Changed $page_id_for_feed to $page_name_for_feed
    log_message('INFO', "Fetching RSS feed: $feed_url for page name: $page_name_for_feed");

    if (empty($page_name_for_feed)) {
        log_message('ERROR', "No page_name provided for feed $feed_url. Skipping.");
        return ['processed' => 0, 'errors' => 1];
    }

    $feed = new SimplePie();
    $feed->set_feed_url($feed_url);
    $feed->enable_cache(false); // Disable cache for this script, manage freshness by run frequency
    $feed->set_stupidly_fast(true); // Less parsing, faster
    $feed->set_timeout(20); // 20 seconds timeout for fetching feed

    if (!$feed->init()) {
        log_message('ERROR', "Error parsing feed $feed_url: " . $feed->error());
        return ['processed' => 0, 'errors' => 1];
    }

    $feed->handle_content_type(); // Ensure content is treated as HTML

    $processed_items = 0;
    $errors = 0;

    foreach ($feed->get_items() as $item) {
        $item_title = $item->get_title() ?: 'No Title';
        $item_link = $item->get_permalink();
        $item_id = $item->get_id(); // Unique ID provided by feed, often a permalink or GUID
        $item_date = $item->get_date('Y-m-d H:i:s') ?: date('Y-m-d H:i:s'); // Published date

        if (empty($item_link)) {
            log_message('WARNING', "Skipping item '$item_title' from $feed_url due to missing link.");
            $errors++;
            continue;
        }

        $item_hash = generate_item_hash($item_id, $item_link, $item_title);

        if (check_duplicate($api_url, $item_hash)) {
            log_message('DEBUG', "Skipping duplicate item: $item_title (hash: $item_hash)");
            continue;
        }

        // Construct Markdown content
        $content_html = $item->get_content() ?: $item->get_description() ?: '';
        // Basic HTML to Markdown conversion (can be improved with a library if needed)
        $content_markdown = "# " . $item_title . "\n\n"; // Add title as H1, ensures it's in the content
        $content_markdown .= "Source: [$item_title]($item_link)\n";
        $content_markdown .= "Published: " . $item->get_date('F j, Y, g:i a') . "\n\n";
        $content_markdown .= "---\n\n";

        // Attempt to convert HTML content to Markdown (very basic)
        // For more robust conversion, consider a library like league/html-to-markdown
        $cleaned_html = strip_tags($content_html, '<p><br><a><h1><h2><h3><h4><h5><h6><strong><em><ul><ol><li><blockquote><code><pre><img>');
        $content_markdown .= $cleaned_html; 

        // Pass page_name_for_feed, title is now part of content_markdown
        if (create_note($api_url, $page_name_for_feed, $item_title, $content_markdown, $feed_url, $item_link, $item_hash, $item_date)) {
            $processed_items++;
        } else {
            $errors++;
            log_message('ERROR', "Failed to create note for item: $item_title from $feed_url");
        }
    }

    log_message('INFO', "Finished processing feed: $feed_url. Added $processed_items new items. Encountered $errors errors.");
    return ['processed' => $processed_items, 'errors' => $errors];
}

// --- Main Execution ---
function main() {
    global $verbose;

    $options = getopt("c:a:f:v", ["config:", "api-url:", "feeds:", "verbose"]);

    if (isset($options['v']) || isset($options['verbose'])) {
        $verbose = true;
        log_message('DEBUG', "Verbose logging enabled.");
    }

    $config_path = $options['c'] ?? $options['config'] ?? DEFAULT_CONFIG_PATH;
    $config = [];

    if (file_exists($config_path)) {
        $json_content = file_get_contents($config_path);
        $config = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message('ERROR', "Error decoding JSON from $config_path: " . json_last_error_msg());
            $config = []; // Reset config on error
        } else {
            log_message('INFO', "Loaded configuration from $config_path");
        }
    } else {
        if ($config_path !== DEFAULT_CONFIG_PATH) {
             log_message('WARNING', "Configuration file not found at $config_path. Using defaults and command-line args.");
        } else {
            log_message('INFO', "Default configuration file " . DEFAULT_CONFIG_PATH . " not found. Using defaults and command-line args.");
        }
    }

    $api_url = $options['a'] ?? $options['api-url'] ?? $config['api_url'] ?? DEFAULT_API_URL;
    // Changed from default_page_id_for_rss to default_page_name_for_rss
    $default_page_name = $config['default_page_name_for_rss'] ?? null; 

    // Load feeds configuration: can be an array of URLs, or array of objects {url, page_name}
    $feeds_config_input = $config['feeds'] ?? [];

    // Command-line --feeds overrides config file's feeds.
    // Command-line feeds are treated as simple URLs; they will use default_page_name.
    $cmd_feed_urls_override = $options['f'] ?? $options['feeds'] ?? null;

    if ($cmd_feed_urls_override) {
        $feeds_config_input = is_array($cmd_feed_urls_override) ? $cmd_feed_urls_override : [$cmd_feed_urls_override];
        log_message('INFO', "Feed list overridden by command line arguments.");
        // Note: Command line feeds will use the default_page_name.
        // If specific page names are needed for cmd line feeds, a more complex arg parsing is required.
    }
    
    if (empty($api_url)) {
        log_message('ERROR', "API URL is not configured. Please provide it via --api-url or in the config file.");
        exit(1);
    }
    $api_url = rtrim($api_url, '/');

    if (empty($feeds_config_input)) {
        log_message('WARNING', "No RSS feed URLs provided in config or via --feeds. Nothing to process.");
        exit(0);
    }

    log_message('INFO', "Using API URL: $api_url");
    if ($default_page_name) { // Changed from default_page_id
        log_message('INFO', "Default Page Name for RSS items: $default_page_name");
    }

    $total_items_added = 0;
    $total_errors = 0;

    foreach ($feeds_config_input as $feed_entry) {
        $feed_url = null;
        $page_name_for_feed = $default_page_name; // Start with default, changed variable name

        if (is_string($feed_entry)) {
            $feed_url = $feed_entry; // Feed entry is a simple URL string
            // It will use $default_page_name
        } elseif (is_array($feed_entry) && isset($feed_entry['url'])) {
            $feed_url = $feed_entry['url']; // Feed entry is an object with 'url'
            // Changed from page_id to page_name for per-feed config
            if (isset($feed_entry['page_name'])) { 
                $page_name_for_feed = $feed_entry['page_name']; // Per-feed page_name overrides default
            }
        }

        if (empty(trim($feed_url))) {
            log_message('WARNING', "Empty feed URL found in configuration. Skipping.");
            continue;
        }
        
        // Replace <today> in page_name_for_feed with current date (Y-m-d)
        if (!empty($page_name_for_feed)) {
            $page_name_for_feed = str_replace('<today>', date('Y-m-d'), $page_name_for_feed);
        }
        
        // Check for page_name_for_feed, and use default_page_name_for_rss from config
        if (empty($page_name_for_feed)) {
            log_message('ERROR', "No page_name specified for feed $feed_url and no default_page_name_for_rss is set. Skipping feed.");
            $total_errors++;
            continue;
        }
        
        log_message('INFO', "Processing feed URL: $feed_url (Target Page Name: $page_name_for_feed)"); // Changed log
        $result = fetch_and_process_feed($api_url, $feed_url, $page_name_for_feed); // Pass page_name
        $total_items_added += $result['processed'];
        $total_errors += $result['errors'];
    }

    log_message('INFO', "All feeds processed. Total new items added: $total_items_added. Total errors: $total_errors.");
    exit($total_errors > 0 ? 1 : 0);
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    // Web context: set header and output JSON error only
    if (!headers_sent()) {
        http_response_code(403); // Forbidden
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'This script is intended for command-line execution only.']);
}

?>
