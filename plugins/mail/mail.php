<?php
// Adds e-mail messages to notd from the command line, or from a mail server.
//
//   php plugins/mail/mail.php message.eml [more.eml | message.msg | a folder of them ...]
//   php plugins/mail/mail.php < message.eml          (what a mail server does)
//   php plugins/mail/mail.php --page "Inbox" message.eml
//
// An .eml or an Outlook .msg. Each becomes a note on today's journal page (or --page), once: a message that
// is there already is left alone. The exit status is what mail servers expect:
//   0   added, or there already
//   65  not an e-mail message (EX_DATAERR)
//   75  notd is busy (the database is locked): try again later (EX_TEMPFAIL)
//   1   anything else

declare(strict_types=1);
PHP_SAPI === 'cli' || exit("Run this from the command line.\n");   // not over the web (phpdesktop serves every .php)

require dirname(__DIR__, 2) . '/server/core.php';
require_once __DIR__ . '/lib/mail.php';
Plugins::load(Db::get());   // other plugins' indexers and actions take part

$args = array_slice($argv, 1);
$page = null;
if (($i = array_search('--page', $args, true)) !== false) {
    $page = $args[$i + 1] ?? null;
    array_splice($args, $i, 2);
}
$messages = [];
foreach ($args as $arg) {
    if (is_dir($arg)) {
        $dir = rtrim($arg, '/\\');
        $messages = [...$messages, ...array_map(fn($f) => "$dir/$f", array_values(preg_grep('/\.(eml|msg)$/i', scandir($dir) ?: [])))];
    } elseif (is_file($arg)) {
        $messages[] = $arg;
    } else {
        fwrite(STDERR, "mail: no such file or folder: $arg\n");
        exit(1);
    }
}
$status = 0;
foreach ($args ? $messages : ['php://stdin'] as $file) {
    $name = $file === 'php://stdin' ? 'the message' : basename($file);
    try {
        $r = Mail::add(Db::get(), (string) file_get_contents($file), $page);
        echo ($r['added'] ? "Added" : "Already there") . ": {$r['subject']} ({$r['page']})\n";
        foreach ($r['warnings'] as $w) {
            echo "  not stored: $w\n";
        }
    } catch (HttpError $e) {
        fwrite(STDERR, "mail: $name: {$e->getMessage()}\n");
        $status = max($status, $e->codeName === 'not_mail' ? 65 : 1);
    } catch (PDOException $e) {
        fwrite(STDERR, "mail: $name: notd is busy ({$e->getMessage()}); try again later\n");
        $status = 75;
    }
}
exit($status);
