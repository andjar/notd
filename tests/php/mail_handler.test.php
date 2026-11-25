<?php
/**
 * Tests for the mail handler (extensions/mail_handler): the MIME parser on
 * its own, and the handler end to end against a throw-away notd server.
 *
 *     php tests/php/mail_handler.test.php
 */

require_once __DIR__ . '/../../extensions/mail_handler/email_parser_utils.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $what, $got = null): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  FAIL: $what" . ($got === null ? '' : "\n    got: " . var_export($got, true)) . "\n";
    }
}
/** A message with CRLF line endings, as it travels. */
function eml(string $text): string
{
    return str_replace("\n", "\r\n", $text);
}

// ------------------------------------------------------------- the parser

echo "parser\n";
$m = notd_parse_email(eml("From: Kari Nordmann <kari@example.com>\nTo: me@example.com\nSubject: Plain\nDate: Sun, 15 Nov 2026 10:00:00 +0100\nMessage-ID: <abc.123@example.com>\n\nHello,\nsee you.\n"));
check($m['from'] === ['name' => 'Kari Nordmann', 'email' => 'kari@example.com'], 'from', $m['from']);
check($m['to'] === [['name' => '', 'email' => 'me@example.com']], 'to', $m['to']);
check([$m['subject'], $m['text'], $m['message_id']] === ['Plain', "Hello,\nsee you.", 'abc.123@example.com'], 'plain message', $m);

$m = notd_parse_email(eml("From: =?UTF-8?Q?J=C3=B8rgen_=C3=85s?= <jorgen@example.no>\nSubject: =?UTF-8?B?w4ZibGVza2l2ZXI=?= =?UTF-8?B?IG9nIGLDpnI=?=\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: quoted-printable\n\nR=C3=B8dgr=C3=B8t med fl=C3=B8te =E2=80=93 en lang linje som er =\nbrutt med myk linjeskift.\n"));
check($m['from']['name'] === 'Jørgen Ås', 'Q-encoded name', $m['from']);
check($m['subject'] === 'Æbleskiver og bær', 'B-encoded subject in two words', $m['subject']);
check($m['text'] === 'Rødgrøt med fløte – en lang linje som er brutt med myk linjeskift.', 'quoted-printable UTF-8 body, soft line break', $m['text']);

$m = notd_parse_email(eml("From: a@example.com\nSubject: Latin\nContent-Type: text/plain; charset=ISO-8859-1\nContent-Transfer-Encoding: 8bit\n\nK\xE6re venn, bl\xE5b\xE6r\n"));
check($m['text'] === 'Kære venn, blåbær', 'ISO-8859-1 to UTF-8', $m['text']);

$alt = "From: a@example.com\nSubject: Alternative\nMIME-Version: 1.0\nContent-Type: multipart/alternative; boundary=\"b1\"\n\nThis is a preamble.\n--b1\nContent-Type: text/plain; charset=utf-8\n\nThe plain version.\n--b1\nContent-Type: text/html; charset=utf-8\n\n<p>The <b>HTML</b> version.</p>\n--b1--\n";
$m = notd_parse_email(eml($alt));
check($m['text'] === 'The plain version.', 'alternative: plain text preferred, preamble ignored', $m['text']);

$html = base64_encode('<html><head><style>p{color:red}</style></head><body><h1>News</h1><p>Read <a href="https://example.com/a?x=1&amp;y=2">the article</a> and <strong>reply</strong>.</p><ul><li>one</li><li>two</li></ul><p>Write to <a href="mailto:x@example.com">x@example.com</a>&nbsp;today.</p></body></html>');
$m = notd_parse_email(eml("From: news@example.com\nSubject: HTML only\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n" . chunk_split($html, 76, "\n")));
check($m['text'] === "## News\n\nRead [the article](https://example.com/a?x=1&y=2) and **reply**.\n\n- one\n- two\n\nWrite to x@example.com today.", 'HTML only: base64, converted to Markdown', $m['text']);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$mixed = "From: \"Doe, John\" <john@example.com>\nTo: \"Smith, Ann\" <ann@example.com>, bob@example.com (Bob B)\nCc: =?ISO-8859-1?Q?Ren=E9?= <rene@example.fr>\nSubject: Report attached\nMessage-ID: <mixed.1@example.com>\nContent-Type: multipart/mixed; boundary=outer\n\n--outer\nContent-Type: multipart/alternative; boundary=inner\n\n--inner\nContent-Type: text/plain; charset=us-ascii; format=flowed\n\nHere is the report \nand a picture.\n\n-- \nJohn\n--inner\nContent-Type: text/html\n\n<p>Here is the report and a picture.</p>\n--inner--\n\n--outer\nContent-Type: image/png; name=\"chart.png\"\nContent-Disposition: attachment; filename=\"chart.png\"\nContent-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($png), 76, "\n") . "--outer\nContent-Type: application/pdf\nContent-Disposition: attachment;\n filename*=UTF-8''r%C3%A9sum%C3%A9.pdf\nContent-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($pdf), 76, "\n") . "--outer--\n";
$m = notd_parse_email(eml($mixed));
check($m['from'] === ['name' => 'Doe, John', 'email' => 'john@example.com'], 'quoted name with a comma', $m['from']);
check($m['to'] === [['name' => 'Smith, Ann', 'email' => 'ann@example.com'], ['name' => 'Bob B', 'email' => 'bob@example.com']], 'several recipients, comment names', $m['to']);
check($m['cc'] === [['name' => 'René', 'email' => 'rene@example.fr']], 'Cc, ISO-8859-1 encoded word', $m['cc']);
check($m['text'] === "Here is the report and a picture.\n\n--\nJohn", 'nested multipart, format=flowed joined, signature line kept', $m['text']);
$names = array_column($m['attachments'], 'name');
check($names === ['chart.png', 'résumé.pdf'], 'attachments, RFC 2231 file name', $names);
check(($m['attachments'][0]['data'] ?? '') === $png && ($m['attachments'][1]['data'] ?? '') === $pdf, 'attachment bytes decoded');

$m = notd_parse_email("not an e-mail at all");
check($m['from'] === null && $m['subject'] === '', 'text that is not a message', $m);

// ------------------------------------------------------ the handler, end to end

echo "handler\n";
$dir = sys_get_temp_dir() . '/notd-mail-' . bin2hex(random_bytes(4));
mkdir($dir);
$port = 18000 + random_int(0, 999);
$root = realpath(__DIR__ . '/../..');
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$root/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, $root, array_merge(getenv(), ['NOTD_DB_PATH' => "$dir/db.sqlite"]));
$api = "http://127.0.0.1:$port/api";
for ($i = 0; $i < 50 && @file_get_contents("$api/v1/ping.php") === false; $i++) {
    usleep(100000);
}
$handler = static function (string $input, string $apiUrl) use ($root): array {
    $proc = proc_open([PHP_BINARY, "$root/extensions/mail_handler/mail_handler.php", $apiUrl], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
    fwrite($p[0], $input);
    fclose($p[0]);
    $out = stream_get_contents($p[1]) . stream_get_contents($p[2]);
    return [proc_close($proc), trim($out)];
};
$get = static fn(string $path) => json_decode((string)file_get_contents("$api/v1/$path"), true)['data'] ?? null;

[$status, $out] = $handler(eml($mixed), $api);
check($status === 0, 'a message is added', $out);
$page = $get('pages.php?name=' . date('Y-m-d'));
$notes = $get('notes.php?page_id=' . $page['id']);
$note = null;
foreach ($notes as $n) {
    if (strpos($n['content'], 'Report attached') !== false) {
        $note = $n;
    }
}
check($note !== null, 'on today\'s journal page');
$content = $note['content'] ?? '';
check(strpos($content, "## Email: Report attached\n\n**From:** Doe, John <john@example.com>\n**To:** Smith, Ann <ann@example.com>, Bob B <bob@example.com>\n**Cc:** René <rene@example.fr>\n\nHere is the report and a picture.") === 0, 'the note text', $content);
check(isset($note['properties']['tag'], $note['properties']['email_from_address'], $note['properties']['email_subject']), 'with properties', array_keys($note['properties'] ?? []));
[$status] = $handler(eml("From: x@example.com\nSubject: Timed\nDate: Sun, 15 Nov 2026 09:12:00 +0100 (CET)\nMessage-ID: <timed.1@example.com>\n\nbody"), $api);
$timed = array_values(array_filter($get('notes.php?page_id=' . $page['id']), static fn($n) => strpos($n['content'], 'Timed') !== false));
check($status === 0 && strpos($timed[0]['content'] ?? '', '**Date:** 2026-11-15 09:12') !== false, 'the date as the sender wrote it, comment ignored', $timed[0]['content'] ?? '');
$atts = $note ? $get('attachments.php?note_id=' . $note['id']) : [];
check(array_column($atts, 'name') === ['résumé.pdf', 'chart.png'] || array_column($atts, 'name') === ['chart.png', 'résumé.pdf'], 'its attachments are stored on the note', array_column($atts, 'name'));

[$status, $out] = $handler(eml($mixed), $api);
$again = array_filter($get('notes.php?page_id=' . $page['id']), static fn($n) => strpos($n['content'], 'Report attached') !== false);
check($status === 0 && strpos($out, 'Already in notd') !== false && count($again) === 1, 'the same message is not added twice', $out);

[$status, $out] = $handler('', $api);
check($status === 65, 'no input: EX_DATAERR', [$status, $out]);
[$status, $out] = $handler(eml("From: x@example.com\nSubject: Later\n\nbody"), 'http://127.0.0.1:1/api');
check($status === 75, 'notd unreachable: EX_TEMPFAIL, so the mail server tries again', [$status, $out]);

foreach ($atts as $a) { // the server stores uploads in notd's own folder
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['action' => 'delete', 'attachment_id' => $a['id']])]]);
    @file_get_contents("$api/v1/attachments.php", false, $ctx);
}
proc_terminate($server);
proc_close($server);
array_map('unlink', glob("$dir/*"));
rmdir($dir);

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
