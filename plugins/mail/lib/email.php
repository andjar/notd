<?php
// A small parser for e-mail messages (RFC 5322 with MIME), without dependencies;
// carried over from notd 3's mail handler.
//
//     $mail = MailMessage::parse(file_get_contents('message.eml'));
//
// gives subject (decoded), from (['name', 'email'] or null), to and cc (lists of
// those), date (the Date header as written), message_id (without <>), text (the
// text/plain part, or the text/html part as Markdown, in UTF-8) and attachments
// (['name', 'type', 'data', 'inline']). Bodies in base64 or quoted-printable are
// decoded, every text is converted to UTF-8 from its charset, multipart messages
// are read to any depth, and of alternatives plain text is preferred.

declare(strict_types=1);

final class MailMessage
{
    /** Splits a raw message or MIME part into its header block and its body. */
    private static function split(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (preg_match("/\r?\n\r?\n/", $raw, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1];
            return [substr($raw, 0, $at), substr($raw, $at + strlen($m[0][0]))];
        }
        // Only headers (or only a body, if the first line is not a header).
        return preg_match('/^[A-Za-z0-9-]+:/', $raw) ? [$raw, ''] : ['', $raw];
    }

    /** Header block => [lower-case name => [values in order]], continuation lines unfolded. */
    private static function headers(string $block): array
    {
        $headers = [];
        $block = preg_replace("/\r?\n[ \t]+/", ' ', $block);
        foreach (preg_split("/\r?\n/", $block) as $line) {
            if (preg_match('/^([A-Za-z0-9!#$%&\'*+.^_`|~-]+):\s*(.*)$/', $line, $m)) {
                $headers[strtolower($m[1])][] = trim($m[2]);
            }
        }
        return $headers;
    }

    /** Text in a charset => UTF-8. Unknown or missing charsets: UTF-8 if valid, else Windows-1252. */
    private static function toUtf8(string $text, string $charset = ''): string
    {
        $charset = strtolower(trim($charset, " \t\"'"));
        if ($charset === '' || $charset === 'us-ascii' || $charset === 'utf-8' || $charset === 'utf8') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        if (in_array($charset, ['iso-8859-1', 'latin1', 'latin-1'], true)) {
            $charset = 'Windows-1252'; // a superset, and what mail clients really mean
        }
        $converted = false;
        try {
            if (in_array(strtolower($charset), array_map('strtolower', mb_list_encodings()), true)) {
                $converted = mb_convert_encoding($text, 'UTF-8', $charset);
            }
        } catch (\Throwable $e) {
            $converted = false;
        }
        if ($converted === false && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
        }
        if ($converted === false || $converted === null) {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return $converted;
    }

    /** Decodes RFC 2047 encoded words (=?charset?B?...?= and =?charset?Q?...?=) in a header value. */
    public static function decodeWords(string $value): string
    {
        // White space between two encoded words is not part of the text.
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value);
        $decoded = preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?]*)\?=/', static function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]); // charset*language
            if (strtoupper($m[2]) === 'B') {
                $bytes = base64_decode($m[3]);
            } else {
                $bytes = preg_replace_callback('/=([0-9A-Fa-f]{2})/', static fn($h) => chr(hexdec($h[1])), str_replace('_', ' ', $m[3]));
            }
            return self::toUtf8((string)$bytes, $charset);
        }, $value);
        return self::toUtf8((string)$decoded); // raw 8-bit headers happen too
    }

    /**
     * A structured header value: 'text/plain; charset="utf-8"' => ['text/plain', ['charset' => 'utf-8']].
     * RFC 2231 parameters (filename*=UTF-8''r%C3%A9sum%C3%A9.pdf, name*0=, name*1=) are joined and decoded.
     */
    private static function params(string $value): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $c = $value[$i];
            if ($c === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $quoted = !$quoted;
            }
            if ($c === ';' && !$quoted) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;
        $main = strtolower(trim(array_shift($parts)));
        $params = [];
        $continued = [];
        foreach ($parts as $part) {
            if (!preg_match('/^\s*([^=\s]+)\s*=\s*(.*?)\s*$/s', $part, $m)) {
                continue;
            }
            $name = strtolower($m[1]);
            $val = $m[2];
            if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
                $val = stripcslashes(substr($val, 1, -1));
            }
            if (preg_match('/^(.+?)\*(\d+)?(\*)?$/', $name, $p)) {
                // name*=charset'lang'value, or a numbered piece of a long value.
                $continued[$p[1]][(int)($p[2] ?? 0)] = [$val, !empty($p[3]) || !isset($p[2])];
                continue;
            }
            $params[$name] = self::decodeWords($val);
        }
        foreach ($continued as $name => $pieces) {
            ksort($pieces);
            $charset = '';
            $text = '';
            foreach ($pieces as $index => [$val, $encoded]) {
                if ($encoded) {
                    if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $e)) {
                        $charset = $e[1];
                        $val = $e[2];
                    }
                    $val = rawurldecode($val);
                }
                $text .= $val;
            }
            $params[$name] = self::toUtf8($text, $charset);
        }
        return [$main, $params];
    }

    /** "Kari <kari@x.no>, \"Doe, John\" <j@y.org>, c@z" => list of ['name', 'email']. */
    public static function addresses(string $value): array
    {
        $value = self::decodeWords($value);
        $items = [];
        $current = '';
        $quoted = false;
        $angle = 0;
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $c = $value[$i];
            if ($c === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $quoted = !$quoted;
            } elseif (!$quoted && $c === '<') {
                $angle++;
            } elseif (!$quoted && $c === '>') {
                $angle = max(0, $angle - 1);
            } elseif (!$quoted && !$angle && $c === ',') {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $items[] = $current;
        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.*?)<\s*([^<>\s]+@[^<>\s]+)\s*>/', $item, $m)) {
                $name = trim(trim($m[1]), '"');
                $out[] = ['name' => stripslashes($name), 'email' => $m[2]];
            } elseif (preg_match('/([^\s<>"(),]+@[^\s<>"(),]+)/', $item, $m)) {
                // "kari@x.no (Kari Nordmann)": the old way of giving a name.
                $name = preg_match('/\(([^)]*)\)/', $item, $c) ? trim($c[1]) : '';
                $out[] = ['name' => $name, 'email' => $m[1]];
            }
        }
        return $out;
    }

    /** HTML mail => readable Markdown: paragraphs, line breaks, lists, links, bold and italics. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('/\s+/', ' ', $html); // HTML white space is not significant
        $html = preg_replace_callback('#<a\b[^>]*href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)</a\s*>#is', static function ($m) {
            $url = html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(strip_tags($m[5]));
            if ($url === '' || stripos($url, 'javascript:') === 0) {
                return $text;
            }
            if (stripos($url, 'mailto:') === 0 && strcasecmp(substr($url, 7), html_entity_decode($text)) === 0) {
                return $text;
            }
            return $text === '' || html_entity_decode($text) === $url ? $url : '[' . $text . '](' . $url . ')';
        }, $html);
        $html = preg_replace('#<\s*br\s*/?>#i', "\n", $html);
        $html = preg_replace('#<\s*li\b[^>]*>#i', "\n- ", $html);
        $html = preg_replace('#<\s*h([1-6])\b[^>]*>#i', "\n\n## ", $html);
        $html = preg_replace('#</\s*(p|div|h[1-6]|ul|ol|table|tr|blockquote|pre)\s*>#i', "\n\n", $html);
        $html = preg_replace('#<\s*(p|div|ul|ol|table|tr|blockquote|pre)\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#</\s*(td|th)\s*>#i', ' ', $html);
        $html = preg_replace('#<\s*/?\s*(b|strong)\b[^>]*>#i', '**', $html);
        $html = preg_replace('#<\s*/?\s*(i|em)\b[^>]*>#i', '*', $html);
        $html = preg_replace('#<\s*hr\b[^>]*>#i', "\n\n---\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text); // &nbsp;
        $text = preg_replace('/\*\*\s*\*\*/', '', $text);
        $lines = array_map(static fn($l) => trim($l), explode("\n", $text));
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** A MIME entity's body, undone from its transfer encoding. */
    private static function decodeBody(string $body, string $encoding): string
    {
        switch (strtolower(trim($encoding))) {
            case 'base64':
                return (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
            case 'quoted-printable':
                return quoted_printable_decode(preg_replace("/\r\n/", "\n", $body));
            default:
                return $body;
        }
    }

    /** Walks a MIME entity, collecting text, HTML and attachments into $out. */
    private static function walk(string $raw, array &$out, int $depth = 0): void
    {
        [$block, $body] = self::split($raw);
        $headers = self::headers($block);
        [$type, $params] = self::params($headers['content-type'][0] ?? 'text/plain; charset=us-ascii');
        [$disposition, $dispParams] = self::params($headers['content-disposition'][0] ?? '');
        $encoding = $headers['content-transfer-encoding'][0] ?? '7bit';

        if (strpos($type, 'multipart/') === 0 && !empty($params['boundary']) && $depth < 20) {
            $boundary = preg_quote($params['boundary'], '/');
            $pieces = preg_split('/\r?\n?--' . $boundary . '(?:--)?[ \t]*(?:\r?\n|$)/', "\n" . $body);
            array_shift($pieces); // the preamble
            $parts = [];
            foreach ($pieces as $piece) {
                if (trim($piece) !== '') {
                    $parts[] = $piece;
                }
            }
            if ($type === 'multipart/alternative') {
                // The alternatives say the same thing: keep the first plain text, else the first HTML.
                $alt = ['text' => [], 'html' => [], 'attachments' => []];
                foreach ($parts as $part) {
                    self::walk($part, $alt, $depth + 1);
                }
                if ($alt['text']) {
                    $out['text'][] = $alt['text'][0];
                } elseif ($alt['html']) {
                    $out['html'][] = $alt['html'][0];
                }
                $out['attachments'] = array_merge($out['attachments'], $alt['attachments']);
                return;
            }
            foreach ($parts as $part) {
                self::walk($part, $out, $depth + 1);
            }
            return;
        }

        $name = $dispParams['filename'] ?? $params['name'] ?? '';
        $data = self::decodeBody($body, $encoding);
        $isText = $type === 'text/plain' || $type === 'text/html';
        if ($disposition === 'attachment' || ($name !== '' && !($isText && $disposition === 'inline')) || !$isText) {
            if ($type === 'message/rfc822' && $name === '') {
                $name = 'message.eml';
            }
            $out['attachments'][] = [
                'name' => $name !== '' ? basename(str_replace('\\', '/', $name)) : 'attachment',
                'type' => $type,
                'data' => $data,
                'inline' => $disposition === 'inline' || isset($headers['content-id']),
            ];
            return;
        }
        $text = self::toUtf8($data, $params['charset'] ?? '');
        if ($type === 'text/plain') {
            if (strtolower($params['format'] ?? '') === 'flowed') {
                // format=flowed (RFC 3676): a line ending in a space continues on the next
                // one, except the signature separator "-- ".
                $joined = '';
                foreach (preg_split("/\r?\n/", $text) as $line) {
                    $joined .= $line . (substr($line, -1) === ' ' && $line !== '-- ' ? '' : "\n");
                }
                $text = $joined;
            }
            $out['text'][] = str_replace("\r\n", "\n", $text);
        } else {
            $out['html'][] = $text;
        }
    }

    /** Parses a raw e-mail message. See the top of this file for what it returns. */
    public static function parse(string $raw): array
    {
        [$block] = self::split($raw);
        $headers = self::headers($block);
        $parts = ['text' => [], 'html' => [], 'attachments' => []];
        self::walk($raw, $parts);

        if ($parts['text']) {
            $text = implode("\n\n", array_map('trim', $parts['text']));
        } elseif ($parts['html']) {
            $text = implode("\n\n", array_map([self::class, 'htmlToText'], $parts['html']));
        } else {
            $text = '';
        }
        $from = self::addresses($headers['from'][0] ?? '');
        $to = [];
        foreach ($headers['to'] ?? [] as $value) {
            $to = array_merge($to, self::addresses($value));
        }
        $cc = [];
        foreach ($headers['cc'] ?? [] as $value) {
            $cc = array_merge($cc, self::addresses($value));
        }
        return [
            'subject' => trim(self::decodeWords($headers['subject'][0] ?? '')),
            'from' => $from[0] ?? null,
            'to' => $to,
            'cc' => $cc,
            'date' => trim($headers['date'][0] ?? ''),
            'message_id' => trim($headers['message-id'][0] ?? '', " <>"),
            'text' => trim(preg_replace("/[ \t]+\n/", "\n", str_replace("\r\n", "\n", $text))),
            'attachments' => $parts['attachments'],
        ];
    }
}
