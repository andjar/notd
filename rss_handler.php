<?php

// --- Configuration & Setup ---
define('DEFAULT_CONFIG_PATH', 'rss_config.json');
define('DEFAULT_API_URL', 'http://localhost:8080/api'); // Replace with your notes app API

// --- Logging ---
$verbose = false;

function log_message($level, $message) {
    global $verbose;
    if ($level === 'DEBUG' && !$verbose) {
        return;
    }
    $timestamp = date('Y-m-d H:i:s');
    fwrite(($level === 'ERROR' || $level === 'WARNING' ? STDERR : STDOUT), "[$timestamp] [$level] $message\n");
}

// --- SimplePie Autoloader (adjust path if not using Composer) ---
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/SimplePie/autoloader.php')) { // Manual include path
    require_once __DIR__ . '/SimplePie/autoloader.php';
} else {
    log_message('ERROR', 'SimplePie library not found. Please install it via Composer or place it in a SimplePie directory.');
    exit(1);
}

use SimplePie\SimplePie;

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
    $query_endpoint = rtrim($api_url, '/') . '/query_notes.php'; // Assuming endpoint
    $params = ['internal_property_key' => 'rss_item_hash', 'internal_property_value' => $item_hash];

    $response = make_api_request($query_endpoint, 'GET', $params);

    if ($response['error'] || $response['http_code'] >= 400) {
        log_message('ERROR', "API request failed during duplicate check for hash $item_hash. HTTP Code: {$response['http_code']}. Error: {$response['error']}");
        return true; // Assume duplicate to be safe on error
    }

    $result = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message('ERROR', "Failed to decode JSON response from duplicate check for hash $item_hash: " . json_last_error_msg() . " - Response: {$response['body']}");
        return true; // Assume duplicate
    }
    return !empty($result); // True if duplicate found
}

function create_note($api_url, $title, $content_markdown, $feed_url, $item_link, $item_hash, $item_date) {
    $notes_endpoint = rtrim($api_url, '/') . '/notes.php'; // Assuming endpoint
    $note_data = [
        'title' => $title,
        'content' => $content_markdown,
        'internal_properties' => [
            'rss' => 'true',
            'rss_item_hash' => $item_hash,
            'rss_feed_url' => $feed_url,
            'rss_item_link' => $item_link,
            'published_date' => $item_date // Adding published date as internal property
        ]
    ];

    $response = make_api_request($notes_endpoint, 'POST', $note_data);

    if ($response['error'] || $response['http_code'] >= 300) { // Typically 201 for created, but accept 2xx
        log_message('ERROR', "API request failed during note creation for '$title'. HTTP Code: {$response['http_code']}. Error: {$response['error']}. Body: {$response['body']}");
        return false;
    }
    log_message('INFO', "Successfully created note: $title");
    return true;
}

function fetch_and_process_feed($api_url, $feed_url) {
    log_message('INFO', "Fetching RSS feed: $feed_url");

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
        $content_markdown = "Source: [$item_title]($item_link)\n";
        $content_markdown .= "Published: " . $item->get_date('F j, Y, g:i a') . "\n\n";
        $content_markdown .= "---\n\n";

        // Attempt to convert HTML content to Markdown (very basic)
        // For more robust conversion, consider a library like league/html-to-markdown
        $cleaned_html = strip_tags($content_html, '<p><br><a><h1><h2><h3><h4><h5><h6><strong><em><ul><ol><li><blockquote><code><pre><img>');
        $content_markdown .= $cleaned_html; // In a real scenario, use a proper HTML to Markdown converter

        if (create_note($api_url, $item_title, $content_markdown, $feed_url, $item_link, $item_hash, $item_date)) {
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
    $feed_urls_input = $options['f'] ?? $options['feeds'] ?? $config['feeds'] ?? [];
    
    // Ensure feeds is an array, even if a single string is passed via command line for --feeds
    $feed_urls = is_array($feed_urls_input) ? $feed_urls_input : [$feed_urls_input];


    if (empty($api_url)) {
        log_message('ERROR', "API URL is not configured. Please provide it via --api-url or in the config file.");
        exit(1);
    }
     // Normalize API URL (remove trailing slash)
    $api_url = rtrim($api_url, '/');


    if (empty($feed_urls)) {
        log_message('WARNING', "No RSS feed URLs provided. Nothing to process.");
        exit(0);
    }

    log_message('INFO', "Using API URL: $api_url");
    log_message('INFO', "Processing feed URLs: " . implode(', ', $feed_urls));

    $total_items_added = 0;
    $total_errors = 0;

    foreach ($feed_urls as $feed_url) {
        if (empty(trim($feed_url))) continue;
        $result = fetch_and_process_feed($api_url, $feed_url);
        $total_items_added += $result['processed'];
        $total_errors += $result['errors'];
    }

    log_message('INFO', "All feeds processed. Total new items added: $total_items_added. Total errors: $total_errors.");
    exit($total_errors > 0 ? 1 : 0);
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    log_message('ERROR', 'This script is intended for command-line execution only.');
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'This script is intended for command-line execution only.']);
}

?>
