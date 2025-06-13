<?php

// --- 1. Include Utilities ---
require_once __DIR__ . '/email_parser_utils.php';

// --- 2. Configuration ---
// Define the base URL for the application's API.
// In a production environment, this might be dynamically determined or set in a config file.
define('API_BASE_URL', 'http://localhost/api/v1'); // Adjust if your local setup uses a different URL or port

// --- External Mail Fetching Mechanism ---
// This script assumes that raw email content is provided to it.
// The actual mechanism for fetching emails (e.g., via IMAP, POP3, or a mail server pipe)
// is external to this script.

// --- 3. Email Input ---
error_log("Email handler script started.");

$raw_email_content = '';
$input_source = '';

// Try reading from php://stdin first
$stdin_content = file_get_contents('php://stdin');
if (!empty($stdin_content)) {
    $raw_email_content = $stdin_content;
    $input_source = 'php://stdin';
    error_log("Reading email content from php://stdin.");
} else {
    // Fallback to a test file if stdin is empty
    $test_email_file = __DIR__ . '/../sample_email.txt'; // Assumes sample_email.txt is in the parent directory
    if (file_exists($test_email_file)) {
        $raw_email_content = file_get_contents($test_email_file);
        $input_source = $test_email_file;
        error_log("php://stdin was empty. Reading email content from test file: " . $test_email_file);
    } else {
        error_log("Error: php://stdin is empty and test file '{$test_email_file}' not found. Exiting.");
        exit(1); // Exit if no email content can be obtained
    }
}

if (empty($raw_email_content)) {
    error_log("Error: No email content could be read from '{$input_source}'. Exiting.");
    exit(1);
}

// --- 4. Parse Email Headers ---
$from_name = '';
$from_email = '';
$to_name = '';
$to_email = '';
$subject = '';

// Split headers from the body for easier header parsing
// Normalize line endings to LF first
$normalized_content_for_headers = str_replace("\r\n", "\n", $raw_email_content);
$header_end_pos = strpos($normalized_content_for_headers, "\n\n");
$header_block = ($header_end_pos !== false) ? substr($normalized_content_for_headers, 0, $header_end_pos) : $normalized_content_for_headers;
$header_lines = explode("\n", $header_block);

foreach ($header_lines as $line) {
    if (stripos($line, 'From:') === 0) {
        $parsed_from = parse_email_address($line);
        if ($parsed_from && $parsed_from['email']) {
            $from_name = $parsed_from['name'] ?? '';
            $from_email = $parsed_from['email'];
        } else {
            error_log("Warning: Could not parse 'From' header: " . $line);
        }
    } elseif (stripos($line, 'To:') === 0) {
        // Note: Emails can have multiple "To" recipients. This simple parser takes the first one.
        // A more robust solution would handle multiple recipients.
        $parsed_to = parse_email_address($line);
        if ($parsed_to && $parsed_to['email']) {
            $to_name = $parsed_to['name'] ?? '';
            $to_email = $parsed_to['email'];
        } else {
            error_log("Warning: Could not parse 'To' header: " . $line);
        }
    } elseif (stripos($line, 'Subject:') === 0) {
        $parsed_subj = parse_subject($line);
        if ($parsed_subj !== null) {
            $subject = $parsed_subj;
        } else {
            error_log("Warning: Could not parse 'Subject' header: " . $line);
        }
    }
}

if (empty($from_email)) {
    error_log("Error: 'From' email address is mandatory and could not be parsed. Exiting.");
    // In a real scenario, might quarantine the email or send a bounce.
    exit(1);
}
if (empty($subject)) {
    $subject = "(No Subject)"; // Default subject if none is found
    error_log("Warning: Email subject is empty. Using default '(No Subject)'.");
}


// --- 5. Parse Email Body ---
$email_body = parse_email_body($raw_email_content);
if (empty($email_body)) {
    error_log("Warning: Email body is empty or could not be parsed.");
    // $email_body will be an empty string, which is acceptable for note creation
}

// --- 6. Determine Target Page Name (Today's Date) ---
$todays_date_string = date('Y-m-d');
// $page_id logic removed. $todays_date_string will be used as page_name.
error_log("Target page name for note: " . $todays_date_string);

// --- 7. Prepare Note Content ---
$note_title = "Email: " . $subject;
$note_content = "## " . $note_title . "\n\n";
$note_content .= "**From:** " . (!empty($from_name) ? $from_name . " <" . $from_email . ">" : $from_email) . "\n";
if (!empty($to_email)) {
    $note_content .= "**To:** " . (!empty($to_name) ? $to_name . " <" . $to_email . ">" : $to_email) . "\n";
}
$note_content .= "\n--- Email Body ---\n" . $email_body . "\n\n";
$note_content .= "---\n"; // Separator for properties
$note_content .= "tag:: mail\n";
$note_content .= "email_from_name:: " . $from_name . "\n";
$note_content .= "email_from_address:: " . $from_email . "\n";
if (!empty($to_email)) {
    if (!empty($to_name)) {
        $note_content .= "email_to_name:: " . $to_name . "\n";
    }
    $note_content .= "email_to_address:: " . $to_email . "\n";
}
$note_content .= "email_subject:: " . $subject . "\n";

// --- 8. Create Note via API ---
// Changed to use append_to_page.php
$notes_api_url = API_BASE_URL . '/append_to_page.php'; 
$post_data = [
    'page_name' => $todays_date_string, // Use the date string as the page name
    'notes'     => $note_content      // The 'notes' key should contain the actual content
];

error_log("Attempting to append note to page: " . $todays_date_string . " via URL: " . $notes_api_url);

try {
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($post_data),
            'ignore_errors' => true // To get error response body
        ]
    ];
    $context = stream_context_create($options);
    $note_response_json = file_get_contents($notes_api_url, false, $context);

    // Check HTTP response code from $http_response_header (available magically)
    $status_line = $http_response_header[0]; // e.g., "HTTP/1.1 201 Created"
    preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
    $status_code = $match[1] ?? null;

    if ($status_code >= 200 && $status_code < 300) { // Success codes (200, 201, etc.)
        $note_response_data = json_decode($note_response_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON response from append_to_page API. Error: " . json_last_error_msg() . ". Response: " . $note_response_json);
        }
        // Updated success check for append_to_page.php response structure
        if (isset($note_response_data['status']) && $note_response_data['status'] === 'success') {
            $appended_note_id = 'N/A';
            if (!empty($note_response_data['data']['appended_notes']) && isset($note_response_data['data']['appended_notes'][0]['id'])) {
                $appended_note_id = $note_response_data['data']['appended_notes'][0]['id'];
            }
            error_log("Successfully appended note. Appended Note ID: " . $appended_note_id . ". Subject: '" . $subject . "'");
            echo "Email processed and note created successfully. Appended Note ID: " . $appended_note_id . "\n";
        } else {
            $api_message = $note_response_data['message'] ?? ($note_response_data['data']['message'] ?? 'No specific message');
            throw new Exception("Append_to_page API returned status not 'success' or unexpected response. Status: " . ($note_response_data['status'] ?? 'N/A') . ". Message: " . $api_message . ". Response: " . $note_response_json);
        }
    } else {
         throw new Exception("Append_to_page API request failed with HTTP status: {$status_code}. Response: " . $note_response_json);
    }

} catch (Exception $e) {
    error_log("Error appending note: " . $e->getMessage());
    // In a real system, might attempt to retry or save the email to a failed queue
    exit(1); // Exit with error
}

error_log("Email handler script finished.");
exit(0); // Success

?>
