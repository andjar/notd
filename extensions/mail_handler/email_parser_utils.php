<?php

/**
 * Parses an email address header line (e.g., "From: John Doe <john.doe@example.com>")
 * and extracts the name and email address.
 *
 * @param string $header_line The header line string.
 * @return array An associative array with 'name' and 'email' keys.
 *               Returns ['name' => '', 'email' => 'address@example.com'] if no name is found.
 *               Returns ['name' => null, 'email' => null] if parsing fails.
 */
function parse_email_address(string $header_line): ?array
{
    // Remove the header key part, e.g., "From: "
    $value_part = preg_replace('/^\s*\w+:\s*/', '', $header_line);

    if (preg_match('/^(.*?)\s*<(.+?)>$/', $value_part, $matches)) {
        // Name and email in format "Name <email>"
        $name = trim($matches[1]);
        // Remove quotes from name if present
        $name = trim($name, '\'"');
        $email = trim($matches[2]);
        return ['name' => $name, 'email' => $email];
    } elseif (filter_var(trim($value_part), FILTER_VALIDATE_EMAIL)) {
        // Only email address present
        return ['name' => '', 'email' => trim($value_part)];
    }

    return ['name' => null, 'email' => null]; // Parsing failed
}

/**
 * Parses a subject header line (e.g., "Subject: Actual Subject")
 * and extracts the subject content.
 *
 * @param string $header_line The subject header line string.
 * @return string|null The extracted subject, or null if "Subject:" prefix is not found.
 */
function parse_subject(string $header_line): ?string
{
    if (preg_match('/^Subject:\s*(.*)/i', $header_line, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

/**
 * Parses the raw email content and extracts the body.
 * Assumes the body starts after the first double newline (CRLF or LF).
 * This version focuses on plain text emails.
 *
 * @param string $raw_email_content The full raw email content.
 * @return string The extracted email body. Returns an empty string if no body is found.
 */
function parse_email_body(string $raw_email_content): string
{
    // Normalize line endings to LF for easier processing
    $normalized_content = str_replace("\r\n", "\n", $raw_email_content);

    // Find the first double newline
    $header_body_separator = "\n\n";
    $separator_pos = strpos($normalized_content, $header_body_separator);

    if ($separator_pos !== false) {
        return trim(substr($normalized_content, $separator_pos + strlen($header_body_separator)));
    }

    return ''; // No body found or content is only headers
}

?>
