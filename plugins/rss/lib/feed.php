<?php
// Reading a feed: fetching it over HTTP(S) and turning RSS 2.0, RSS 1.0 (RDF)
// or Atom into a title and a list of items. Elements are found by their local
// name, so the namespaces feeds actually use (dc:date, content:encoded, Atom
// in an RSS channel) all work. No external entities are ever loaded.

declare(strict_types=1);

final class FeedReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** The body at an http(s) address, following redirects, or an exception saying why not. */
    public static function get(string $url, int $timeout = 15): string
    {
        if (!preg_match('~^https?://[^\s/]+~i', $url)) {
            throw new RuntimeException('A feed address starts with http:// or https://.');
        }
        $agent = 'notd/4 (feed reader)';
        if (ini_get('allow_url_fopen')) {          // the stream wrapper uses the system's certificates, on Windows too
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 5,
                'user_agent' => $agent, 'header' => "Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*\r\n", 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
            $status = self::status(function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []));
            if ($body === false || $status === 0) {
                throw new RuntimeException('Could not reach ' . parse_url($url, PHP_URL_HOST) . '.');
            }
        } elseif (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => $agent, CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]
                + (defined('CURLSSLOPT_NATIVE_CA') ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA] : []));
            $body = curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $error = curl_error($c);
            if ($body === false) {
                throw new RuntimeException('Could not reach ' . parse_url($url, PHP_URL_HOST) . ": $error");
            }
            $body = substr((string) $body, 0, self::MAX_BYTES);
        } else {
            throw new RuntimeException('PHP cannot fetch web pages here: turn on allow_url_fopen or the curl extension.');
        }
        if ($status >= 400) {
            throw new RuntimeException("The server answered $status.");
        }
        return (string) $body;
    }

    /** The status of the last response in a list of headers (after redirects). */
    private static function status(array $headers): int
    {
        $status = 0;
        foreach ($headers as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /** ['title' => string, 'items' => list of ['id', 'title', 'link', 'published' (Y-m-d H:i on this server's clock, or ''), 'summary'] in the feed's order]. */
    public static function parse(string $xml): array
    {
        $doc = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $root = $ok ? $doc->documentElement : null;
        if ($root === null || !in_array($root->localName, ['rss', 'RDF', 'feed'], true)) {
            throw new RuntimeException('This is not an RSS or Atom feed.');
        }
        $channel = $root->localName === 'feed' ? $root : (self::child($root, 'channel') ?? $root);
        $items = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el->localName === 'item' || $el->localName === 'entry') {
                $items[] = self::item($el);
            }
        }
        return ['title' => self::text(self::child($channel, 'title')), 'items' => array_values(array_filter($items, fn($i) => $i['id'] !== ''))];
    }

    private static function item(DOMElement $el): array
    {
        $link = '';
        foreach (self::children($el, 'link') as $l) {
            $href = $l->getAttribute('href');
            $rel = $l->getAttribute('rel') ?: 'alternate';
            if ($href !== '' && $rel === 'alternate') {
                $link = $href;
                break;
            }
            if ($href === '' && trim($l->textContent) !== '') {
                $link = trim($l->textContent);
                break;
            }
        }
        $published = '';
        foreach (['pubDate', 'published', 'updated', 'date', 'issued'] as $name) {
            $t = self::text(self::child($el, $name));
            $ts = $t === '' ? false : strtotime($t);
            if ($ts !== false) {
                $published = date('Y-m-d H:i', $ts);
                break;
            }
        }
        $summary = '';
        foreach (['description', 'summary', 'encoded', 'content'] as $name) {
            $summary = self::plain(self::child($el, $name)?->textContent ?? '');
            if ($summary !== '') {
                break;
            }
        }
        $title = self::plain(self::child($el, 'title')?->textContent ?? '');
        $guid = self::text(self::child($el, 'guid')) ?: self::text(self::child($el, 'id')) ?: $link ?: $el->getAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about');
        return ['id' => $guid ?: ($title !== '' ? sha1($title . $published) : ''), 'title' => $title, 'link' => $link, 'published' => $published, 'summary' => $summary];
    }

    /** HTML or text as one line of plain text. */
    public static function plain(string $s): string
    {
        $s = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $s) ?? $s;
        $s = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/div|/li)\b[^>]*>~i', ' ', $s) ?? $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private static function text(?DOMElement $el): string
    {
        return $el === null ? '' : trim($el->textContent);
    }

    private static function child(DOMElement $el, string $name): ?DOMElement
    {
        return self::children($el, $name)[0] ?? null;
    }

    /** @return list<DOMElement> */
    private static function children(DOMElement $el, string $name): array
    {
        $out = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $name) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
