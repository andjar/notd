<?php
/**
 * Adds an e-mail message to today's journal page in notd, with its
 * attachments.
 *
 *     php mail_handler.php [api-url] < message.eml
 *
 * notd's address followed by /api comes from the argument, or "api_url" in
 * config.json next to this script (default http://localhost:8000/api).
 *
 * The message is read from standard input in its raw form (.eml), as mail
 * servers and clients hand it to a program. A message that was added before
 * (same Message-ID) is not added again.
 *
 * Exit status, as mail servers expect from a delivery program:
 *   0   added (or already there)
 *   65  the input is not a message (EX_DATAERR)
 *   75  notd could not be reached or was busy: try again later (EX_TEMPFAIL)
 *   1   notd refused the note
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/email_parser_utils.php';

const EX_DATAERR = 65;
const EX_TEMPFAIL = 75;

$config = json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true) ?: [];
$api = rtrim($argv[1] ?? $config['api_url'] ?? 'http://localhost:8000/api', '/') . '/v1';

/** One HTTP request. @return array{0: int, 1: ?array}|null status and decoded JSON; null if notd cannot be reached */
function notd_mail_http(string $url, ?string $body = null, string $contentType = 'application/json'): ?array
{
    $context = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => $body === null ? "Accept: application/json\r\n" : "Content-Type: $contentType\r\nAccept: application/json\r\n",
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false || empty($http_response_header)) {
        return null;
    }
    preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $m);
    return [(int)($m[1] ?? 0), json_decode($response, true)];
}

/** {key::value}: a value cannot hold "}" or a line break. */
function notd_mail_property(string $key, string $value, string $colons = '::'): string
{
    return '{' . $key . $colons . trim(str_replace(['}', "\r", "\n"], [')', ' ', ' '], $value)) . '}';
}

function notd_mail_person(?array $address): string
{
    if (!$address) {
        return '';
    }
    return $address['name'] !== '' ? $address['name'] . ' <' . $address['email'] . '>' : $address['email'];
}

function notd_mail_fail(string $message, int $status): void
{
    fwrite(STDERR, "mail handler: $message\n");
    exit($status);
}

// ------------------------------------------------------------------ read

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    notd_mail_fail('no message on standard input. Usage: php mail_handler.php [api-url] < message.eml', EX_DATAERR);
}
$mail = notd_parse_email($raw);
if (!$mail['from'] && $mail['subject'] === '' && $mail['text'] === '') {
    notd_mail_fail('the input does not look like an e-mail message', EX_DATAERR);
}

// Added before? Recognised by the Message-ID.
if ($mail['message_id'] !== '') {
    $sql = "SELECT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'email_message_id' AND P.value = '"
        . str_replace("'", "''", str_replace(['}', "\n"], [')', ' '], $mail['message_id'])) . "' LIMIT 1";
    $found = notd_mail_http("$api/query_notes.php", json_encode(['sql_query' => $sql]));
    if ($found === null || $found[0] >= 500) {
        notd_mail_fail("notd cannot be reached at $api", EX_TEMPFAIL);
    }
    if ($found[0] === 200 && !empty($found[1]['data'])) {
        echo "Already in notd: {$mail['message_id']}\n";
        exit(0);
    }
}

// ------------------------------------------------------------------ note

$subject = $mail['subject'] !== '' ? $mail['subject'] : '(no subject)';
$lines = ['## Email: ' . $subject, ''];
if ($mail['from']) {
    $lines[] = '**From:** ' . notd_mail_person($mail['from']);
}
if ($mail['to']) {
    $lines[] = '**To:** ' . implode(', ', array_map('notd_mail_person', $mail['to']));
}
if ($mail['cc']) {
    $lines[] = '**Cc:** ' . implode(', ', array_map('notd_mail_person', $mail['cc']));
}
// The time as the sender's clock showed it (the Date header carries its own offset).
$when = '';
if ($mail['date'] !== '') {
    try {
        $when = (new DateTime(preg_replace('/\s*\([^)]*\)\s*$/', '', $mail['date'])))->format('Y-m-d H:i');
    } catch (\Exception $e) {
        $when = '';
    }
}
if ($when !== '') {
    $lines[] = '**Date:** ' . $when;
}
if ($mail['text'] !== '') {
    $lines[] = '';
    $lines[] = $mail['text'];
}

$props = [notd_mail_property('tag', 'mail')];
if ($mail['from']) {
    $props[] = notd_mail_property('email_from_address', $mail['from']['email']);
    if ($mail['from']['name'] !== '') {
        $props[] = notd_mail_property('email_from_name', $mail['from']['name']);
    }
}
if ($mail['to']) {
    $props[] = notd_mail_property('email_to_address', $mail['to'][0]['email']);
    if ($mail['to'][0]['name'] !== '') {
        $props[] = notd_mail_property('email_to_name', $mail['to'][0]['name']);
    }
}
$props[] = notd_mail_property('email_subject', $subject);
if ($when !== '') {
    $props[] = notd_mail_property('email_date', $when);
}
if ($mail['message_id'] !== '') {
    $props[] = notd_mail_property('email_message_id', $mail['message_id'], ':::');
}
$lines[] = '';
$lines[] = implode(' ', $props);

$result = notd_mail_http("$api/append_to_page.php", json_encode(['page_name' => date('Y-m-d'), 'notes' => implode("\n", $lines)]));
if ($result === null || $result[0] >= 500) {
    notd_mail_fail("notd cannot be reached at $api" . ($result ? " (HTTP {$result[0]})" : ''), EX_TEMPFAIL);
}
$noteId = $result[1]['data']['appended_notes'][0]['id'] ?? null;
if ($result[0] !== 200 || $noteId === null) {
    notd_mail_fail('notd refused the note: ' . ($result[1]['message'] ?? "HTTP {$result[0]}"), 1);
}

// ----------------------------------------------------------- attachments

$stored = 0;
foreach ($mail['attachments'] as $attachment) {
    $name = preg_replace('/["\r\n\\\\]/', '_', $attachment['name']);
    if (strpos($name, '.') === false) {
        continue; // unnamed parts (signatures, calendar data) cannot be stored without a known file type
    }
    $boundary = '----notd-mail-' . bin2hex(random_bytes(8));
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"note_id\"\r\n\r\n$noteId\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"attachmentFile\"; filename=\"$name\"\r\n"
        . "Content-Type: {$attachment['type']}\r\n\r\n" . $attachment['data'] . "\r\n--$boundary--\r\n";
    $upload = notd_mail_http("$api/attachments.php", $body, "multipart/form-data; boundary=$boundary");
    if ($upload !== null && $upload[0] === 200) {
        $stored++;
    } else {
        fwrite(STDERR, "mail handler: attachment $name not stored: " . ($upload[1]['message'] ?? 'notd cannot be reached') . "\n");
    }
}

echo "Added to " . date('Y-m-d') . ": $subject" . ($stored ? " ($stored attachment" . ($stored > 1 ? 's' : '') . ')' : '') . "\n";
exit(0);
