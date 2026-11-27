<?php
// Adds an e-mail message to the notebook: a block on today's journal page (or
// another page), "Email: <subject> #mail", with the sender and date as fields,
// and under it, folded away, the sender, recipients and date as written, the
// text, and the attachments, stored as notd files.
//
// A message is added once: its block's id is worked out from its Message-ID
// (or, without one, from the whole message), so handing it over again, from a
// mail server that retries or from a second drag and drop, changes nothing.

declare(strict_types=1);

require_once __DIR__ . '/email.php';

final class Mail
{
    /** ['id', 'page', 'subject', 'added' => false when it was there already, 'warnings' => [...]]. Throws HttpError 422 for anything that is not a message. */
    public static function add(PDO $pdo, string $raw, ?string $page = null): array
    {
        $m = MailMessage::parse($raw);
        if ($m['from'] === null && $m['subject'] === '' && $m['date'] === '') {
            throw new HttpError(422, 'not_mail', 'This is not an e-mail message (it has no sender, subject or date).');
        }
        $id = self::id($m['message_id'] !== '' ? "<{$m['message_id']}>" : sha1($raw));
        $subject = trim(preg_replace('/\s+/', ' ', $m['subject']) ?? '') ?: '(no subject)';
        $title = trim($page ?? '') ?: date('Y-m-d');
        $existing = Nodes::get($pdo, $id);
        if ($existing !== null) {
            return ['id' => $id, 'page' => Nodes::get($pdo, (string) $existing['page'])['title'] ?? $title, 'subject' => $subject, 'added' => false, 'warnings' => []];
        }

        $ops = [];
        $target = Nodes::byTitle($pdo, $title);
        $pageId = $target['id'] ?? new_id();
        if ($target === null) {
            $ops[] = ['op' => 'create', 'kind' => 'page', 'id' => $pageId, 'title' => $title];
        }
        $when = self::when($m['date']);
        $fields = array_filter([
            $m['from'] ? '{from:: ' . self::field($m['from']['email']) . '}' : '',
            $when ? '{date:: ' . substr($when, 0, 10) . '}' : '',
        ]);
        $ops[] = ['op' => 'create', 'id' => $id, 'parent' => $pageId, 'collapsed' => true,
            'text' => 'Email: ' . self::line($subject) . ' #mail' . ($fields ? "\n" . implode(' ', $fields) : '')];   // "Email: " so no subject starts a heading or a task

        $head = array_filter([
            $m['from'] ? '**From:** ' . self::person($m['from']) : '',
            $m['to'] ? '**To:** ' . implode(', ', array_map([self::class, 'person'], $m['to'])) : '',
            $m['cc'] ? '**Cc:** ' . implode(', ', array_map([self::class, 'person'], $m['cc'])) : '',
            $when ? "**Date:** $when" : '',
        ]);
        $ops[] = ['op' => 'create', 'parent' => $id, 'text' => trim(implode("\n", $head) . "\n\n" . $m['text'])];

        $warnings = [];
        $links = [];
        foreach ($m['attachments'] as $a) {
            $tmp = tempnam(sys_get_temp_dir(), 'notd-mail');
            file_put_contents($tmp, $a['data']);
            try {
                $file = Files::store($pdo, $tmp, $a['name']);
                $name = str_replace(['[', ']'], '', $a['name']);
                $links[] = (str_starts_with((string) ($file['mime'] ?? ''), 'image/') ? '!' : '') . "[$name](file:{$file['id']})";
            } catch (HttpError $e) {
                $warnings[] = "{$a['name']}: {$e->getMessage()}";
            } finally {
                @unlink($tmp);
            }
        }
        if ($links || $warnings) {
            $ops[] = ['op' => 'create', 'parent' => $id, 'text' => implode(' ', $links)
                . ($warnings ? ($links ? "\n" : '') . 'Not stored: ' . implode('; ', array_map([self::class, 'line'], $warnings)) : '')];
        }
        Ops::apply($pdo, $ops);
        return ['id' => $id, 'page' => $title, 'subject' => $subject, 'added' => true, 'warnings' => $warnings];
    }

    /** The same id every time for the same message, in the UUID layout (version 8). */
    public static function id(string $seed): string
    {
        $h = sha1("mail\0$seed");
        $h[12] = '8';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /** The Date header as the sender's clock showed it, "2026-11-27 14:05", or '' when it cannot be read. */
    private static function when(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable(preg_replace('/\s*\([^)]*\)\s*$/', '', $date) ?? $date))->format('Y-m-d H:i');
        } catch (Exception) {
            return '';
        }
    }

    private static function person(array $a): string
    {
        $name = self::line($a['name']);
        return $name === '' ? "<{$a['email']}>" : (str_contains($name, ',') ? "\"$name\"" : $name) . " <{$a['email']}>";
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
