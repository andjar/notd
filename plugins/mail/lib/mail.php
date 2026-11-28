<?php
// Adds an e-mail message to the notebook: a block on today's journal page (or
// another page), "Email: <subject> #mail", with the sender and time as fields,
// and under it, folded away, the sender, recipients and time as written, the
// text, the attachments (stored as notd files) and any attached e-mails, each a
// note of the same kind. A message is an .eml file or an Outlook .msg.
//
// A message is added once: its block's id is worked out from its Message-ID
// (or, without one, from what it says), so handing it over again, from a mail
// server that retries or from a second drag and drop, changes nothing.

declare(strict_types=1);

require_once __DIR__ . '/email.php';
require_once __DIR__ . '/msg.php';

final class Mail
{
    /** Reads a message file: an Outlook .msg or an .eml, told apart by their first bytes. Throws HttpError 422 if it is neither. */
    public static function read(string $raw): array
    {
        if (!OutlookMsg::looksLike($raw)) {
            return MailMessage::parse($raw);
        }
        try {
            return OutlookMsg::parse($raw);
        } catch (RuntimeException $e) {
            throw new HttpError(422, 'not_mail', $e->getMessage());
        }
    }

    /** ['id', 'page', 'subject', 'added' => false when it was there already, 'warnings' => [...]]. Throws HttpError 422 for anything that is not a message. */
    public static function add(PDO $pdo, string $raw, ?string $page = null): array
    {
        $m = self::read($raw);
        $seed = OutlookMsg::looksLike($raw) ? json_encode([$m['subject'], $m['from'], $m['date'], $m['text']]) : $raw;
        return self::addParsed($pdo, $m, $m['message_id'] !== '' ? "<{$m['message_id']}>" : sha1((string) $seed), $page);
    }

    /**
     * Adds a message read by read() under $parent (a block, or by default the page $page, today's journal
     * if null), with $fields (['folder' => 'Inbox']) added to its fields. $seed gives its id.
     */
    public static function addParsed(PDO $pdo, array $m, string $seed, ?string $page = null, array $fields = [], ?string $parent = null): array
    {
        if ($m['from'] === null && $m['subject'] === '' && $m['date'] === '') {
            throw new HttpError(422, 'not_mail', 'This is not an e-mail message (it has no sender, subject or date).');
        }
        $id = self::id($seed);
        $subject = self::subject($m);
        $title = trim($page ?? '') ?: date('Y-m-d');
        $existing = Nodes::get($pdo, $id);
        if ($existing !== null) {
            return ['id' => $id, 'page' => Nodes::get($pdo, (string) $existing['page'])['title'] ?? $title, 'subject' => $subject, 'added' => false, 'warnings' => []];
        }
        $ops = [];
        if ($parent === null) {
            $target = Nodes::byTitle($pdo, $title);
            $parent = $target['id'] ?? new_id();
            if ($target === null) {
                $ops[] = ['op' => 'create', 'kind' => 'page', 'id' => $parent, 'title' => $title];
            }
        }
        $warnings = [];
        self::note($pdo, $ops, $warnings, $m, $id, $parent, $fields, 0);
        Ops::apply($pdo, $ops);
        return ['id' => $id, 'page' => $title, 'subject' => $subject, 'added' => true, 'warnings' => $warnings];
    }

    /** The ops for one message: its block, and under it the header and text, the attachments, and attached e-mails. */
    private static function note(PDO $pdo, array &$ops, array &$warnings, array $m, string $id, string $parent, array $extra, int $depth): void
    {
        $when = self::when($m['date']);
        $fields = array_filter([
            $m['from'] ? '{from:: ' . self::field($m['from']['email'] ?: $m['from']['name']) . '}' : '',
            $when ? "{date:: $when}" : '',
            ...array_map(fn($k, $v) => '{' . $k . ':: ' . self::field((string) $v) . '}', array_keys($extra), $extra),
        ]);
        $ops[] = ['op' => 'create', 'id' => $id, 'parent' => $parent, 'collapsed' => true,       // "Email: " so no subject starts a heading or a task
            'text' => self::label($m['class'] ?? 'IPM.Note', $depth) . ': ' . self::line(self::subject($m)) . ($depth === 0 ? ' #mail' : '')
                . ($fields ? "\n" . implode(' ', $fields) : '')];

        $head = array_filter([
            $m['from'] ? '**From:** ' . self::person($m['from']) : '',
            $m['to'] ? '**To:** ' . implode(', ', array_map([self::class, 'person'], $m['to'])) : '',
            $m['cc'] ? '**Cc:** ' . implode(', ', array_map([self::class, 'person'], $m['cc'])) : '',
            $when ? "**Date:** $when" : '',
        ]);
        $ops[] = ['op' => 'create', 'id' => self::id("$id/text"), 'parent' => $id, 'text' => trim(implode("\n", $head) . "\n\n" . $m['text'])];

        $links = [];
        $missed = [];
        foreach ($m['attachments'] as $a) {
            $tmp = tempnam(sys_get_temp_dir(), 'notd-mail');
            file_put_contents($tmp, $a['data']);
            try {
                $file = Files::store($pdo, $tmp, $a['name']);
                $name = str_replace(['[', ']'], '', $a['name']);
                $links[] = (str_starts_with((string) ($file['mime'] ?? ''), 'image/') ? '!' : '') . "[$name](file:{$file['id']})";
            } catch (HttpError $e) {
                $missed[] = "{$a['name']}: {$e->getMessage()}";
            } finally {
                @unlink($tmp);
            }
        }
        if ($links || $missed) {
            $ops[] = ['op' => 'create', 'id' => self::id("$id/files"), 'parent' => $id, 'text' => implode(' ', $links)
                . ($missed ? ($links ? "\n" : '') . 'Not stored: ' . implode('; ', array_map([self::class, 'line'], $missed)) : '')];
        }
        array_push($warnings, ...$missed);
        foreach ($m['embedded'] ?? [] as $i => $inner) {
            self::note($pdo, $ops, $warnings, $inner, self::id("$id/$i"), $id, [], $depth + 1);
        }
    }

    /** What kind of item an Outlook message class is, as the start of its block. */
    private static function label(string $class, int $depth): string
    {
        $kind = match (true) {
            str_starts_with($class, 'IPM.Appointment'), str_starts_with($class, 'IPM.Schedule.Meeting') => 'Meeting',
            str_starts_with($class, 'IPM.Contact') => 'Contact',
            str_starts_with($class, 'IPM.Task') => 'Outlook task',
            str_starts_with($class, 'IPM.StickyNote') => 'Outlook note',
            default => 'Email',
        };
        return $depth > 0 ? 'Attached ' . lcfirst($kind === 'Email' ? 'e-mail' : $kind) : $kind;
    }

    private static function subject(array $m): string
    {
        return trim(preg_replace('/\s+/', ' ', $m['subject']) ?? '') ?: '(no subject)';
    }

    /** The same id every time for the same message, in the UUID layout (version 8). */
    public static function id(string $seed): string
    {
        $h = sha1("mail\0$seed");
        $h[12] = '8';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /** The Date header on this notebook's clock (see the timezone setting), "2026-11-27 14:05", or '' when it cannot be read. */
    private static function when(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            $at = new DateTimeImmutable(preg_replace('/\s*\([^)]*\)\s*$/', '', $date) ?? $date);
            return $at->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
        } catch (Exception) {
            return '';
        }
    }

    private static function person(array $a): string
    {
        $name = self::line($a['name']);
        $quoted = str_contains($name, ',') ? "\"$name\"" : $name;
        if ($a['email'] === '' || strcasecmp($name, $a['email']) === 0) {
            return $a['email'] === '' ? $quoted : "<{$a['email']}>";
        }
        return $name === '' ? "<{$a['email']}>" : "$quoted <{$a['email']}>";
    }

    private static function line(string $s): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $s));
    }

    /** A field value: no closing brace and no line break. */
    private static function field(string $s): string
    {
        return trim(str_replace(['}', "\r", "\n"], [')', ' ', ' '], $s));
    }
}
