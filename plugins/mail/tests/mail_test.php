<?php
require_once dirname(__DIR__, 3) . '/tests/php/support/server.php';
require_once dirname(__DIR__) . '/lib/mail.php';

const MAIL = __DIR__ . '/fixtures';

test('the parser reads encoded words, quoted-printable, several recipients and names with commas', function () {
    $m = MailMessage::parse(file_get_contents(MAIL . '/plain.eml'));
    eq('Blabærsyltetøy for møte', $m['subject']);
    eq(['name' => 'Kari Nordmann', 'email' => 'kari@example.no'], $m['from']);
    eq(['Anders', 'Doe, John'], array_column($m['to'], 'name'));
    eq('abc123@example.no', $m['message_id']);
    ok(str_contains($m['text'], 'Møtet er flyttet til fredag'), $m['text']);
});

test('an HTML-only message in Latin-1 becomes Markdown; a multipart one gives its plain text and attachments', function () {
    $h = MailMessage::parse(file_get_contents(MAIL . '/html.eml'));
    eq("## Update\n\nRead [the article](https://example.com/a) about **blåbær**.\n\n- One\n- Two", $h['text']);
    $m = MailMessage::parse(file_get_contents(MAIL . '/mixed.eml'));
    eq('Here is the list, and a chart.', $m['text']);
    eq(['biobank list.pdf', 'chart.png', 'tool.exe'], array_column($m['attachments'], 'name'));
});

test('a message becomes a folded note on today\'s journal, with its attachments stored, once', function () {
    $pdo = fresh_db();
    $r = Mail::add($pdo, file_get_contents(MAIL . '/mixed.eml'));
    eq([true, date('Y-m-d'), 'Biobank list'], [$r['added'], $r['page'], $r['subject']]);
    eq(['tool.exe: Files of type .exe are not accepted.'], $r['warnings']);
    $blocks = Read::page($pdo, date('Y-m-d'))['blocks'];
    eq("Email: Biobank list #mail\n{from:: signe@example.no} {date:: 2026-11-27}", $blocks[0]['text']);
    ok($blocks[0]['collapsed'], 'folded');
    eq("**From:** Signe <signe@example.no>\n**To:** <anders@example.no>\n**Date:** 2026-11-27 09:30\n\nHere is the list, and a chart.", $blocks[1]['text']);
    eq(1, preg_match('/^\[biobank list\.pdf\]\(file:[0-9a-f-]{36}\) !\[chart\.png\]\(file:[0-9a-f-]{36}\)\nNot stored: tool\.exe: /', $blocks[2]['text']), $blocks[2]['text']);
    eq($r['id'], $blocks[1]['parent']);

    $again = Mail::add($pdo, file_get_contents(MAIL . '/mixed.eml'));
    eq([false, $r['id']], [$again['added'], $again['id']]);
    eq(3, count(Read::page($pdo, date('Y-m-d'))['blocks']), 'nothing added the second time');

    $tagged = Query::run($pdo, 'tag:mail from:signe@example.no');
    eq([$r['id']], array_column($tagged['nodes'], 'id'), 'found by tag and sender');
});

test('another page can be given; something that is not mail is refused', function () {
    $pdo = fresh_db();
    eq('Inbox', Mail::add($pdo, file_get_contents(MAIL . '/plain.eml'), 'Inbox')['page']);
    eq(2, count(Read::page($pdo, 'Inbox')['blocks']));
    $e = throws(fn() => Mail::add($pdo, file_get_contents(MAIL . '/not-mail.eml')), HttpError::class);
    eq([422, 'not_mail'], [$e->status, $e->codeName]);
});

test('the command line tool: exit codes a mail server understands', function () {
    $data = sys_get_temp_dir() . '/notd-mailcli-' . bin2hex(random_bytes(4));
    mkdir($data);
    register_shutdown_function(fn() => remove_tree($data));
    $run = function (string $args, ?string $stdin = null) use ($data): array {
        $p = proc_open(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/mail.php') . " $args", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, ['NOTD_DATA_DIR' => $data] + getenv());
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    [$code, $out] = $run('', file_get_contents(MAIL . '/plain.eml'));
    eq(0, $code, $out);
    ok(str_contains($out, 'Added: Blabærsyltetøy'), $out);
    eq(0, $run(escapeshellarg(MAIL . '/plain.eml'))[0], 'there already is fine');
    eq(65, $run(escapeshellarg(MAIL . '/not-mail.eml'))[0]);
    eq(1, $run(escapeshellarg(MAIL . '/missing.eml'))[0]);
});
