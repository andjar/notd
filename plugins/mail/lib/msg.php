<?php
// Reading Outlook .msg files (MS-OXMSG): what Outlook for Windows hands over
// when an e-mail is dragged out of it. A .msg is a compound file (cfb.php)
// holding the message's properties, one storage per recipient and one per
// attachment; an attached e-mail is a message storage inside an attachment.
//
//     $mail = OutlookMsg::parse(file_get_contents('message.msg'));
//
// gives the same shape as MailMessage::parse (email.php), plus 'class' (the
// Outlook item type, such as IPM.Note) and 'embedded' (attached e-mails, in
// the same shape). Where the message kept the headers it arrived with, the
// sender, recipients, date and Message-ID come from those, as written.

declare(strict_types=1);

require_once __DIR__ . '/cfb.php';
require_once __DIR__ . '/email.php';

final class OutlookMsg
{
    private const UNICODE = 0x001F;
    private const STRING8 = 0x001E;
    private const BINARY = 0x0102;

    public static function parse(string $data): array
    {
        return self::message(new Cfb($data), 0, 32, 0);
    }

    /** True for the first bytes of a compound file (a .msg, whatever its name). */
    public static function looksLike(string $data): bool
    {
        return str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    }

    /** The message in storage $storage; $header is the size of its property stream's header (32 at the top, 24 when attached). */
    private static function message(Cfb $cfb, int $storage, int $header, int $depth): array
    {
        $p = new MsgProperties($cfb, $storage, $header, null);
        // 8-bit strings are in the message's Windows code page: PidTagMessageCodepage, else the one of its
        // locale (PidTagMessageLocaleId). The Internet code page is what it was sent in, for the HTML.
        $p->codepage = $p->int(0x3FFD) ?? self::ansi($p->int(0x3FF1));
        $headers = MailMessage::headersOf($p->string(0x007D) ?? '');   // PidTagTransportMessageHeaders

        $from = MailMessage::addresses($headers['from'][0] ?? '')[0] ?? null;
        if ($from === null) {
            $email = $p->string(0x5D01) ?? $p->string(0x5D02) ?? self::smtp($p->string(0x0C1F), $p->string(0x0C1E))
                ?? self::smtp($p->string(0x0065), $p->string(0x0064));
            $name = $p->string(0x0C1A) ?? $p->string(0x0042) ?? '';
            $from = $email !== null || $name !== '' ? ['name' => $name, 'email' => $email ?? ''] : null;
        }
        $to = $cc = [];
        foreach ($headers['to'] ?? [] as $v) {
            $to = [...$to, ...MailMessage::addresses($v)];
        }
        foreach ($headers['cc'] ?? [] as $v) {
            $cc = [...$cc, ...MailMessage::addresses($v)];
        }
        $attachments = $embedded = [];
        foreach ($cfb->children($storage) as $name => $entry) {
            if (str_starts_with($name, '__RECIP_VERSION1.0_') && !isset($headers['to'])) {
                $r = new MsgProperties($cfb, $entry, 8, $p->codepage);
                $who = ['name' => $r->string(0x3001) ?? '', 'email' => $r->string(0x39FE) ?? self::smtp($r->string(0x3003), $r->string(0x3002)) ?? ''];
                $type = $r->int(0x0C15);                                    // PidTagRecipientType: 1 To, 2 Cc, 3 Bcc
                if ($type === 2) {
                    $cc[] = $who;
                } elseif ($type !== 3) {
                    $to[] = $who;
                }
            } elseif (str_starts_with($name, '__ATTACH_VERSION1.0_')) {
                $a = new MsgProperties($cfb, $entry, 8, $p->codepage);
                $inner = $cfb->children($entry)['__SUBSTG1.0_3701000D'] ?? null;
                if ($inner !== null && $depth < 3) {                          // an attached e-mail
                    $embedded[] = self::message($cfb, $inner, 24, $depth + 1);
                    continue;
                }
                $data = $a->binary(0x3701);                                 // PidTagAttachDataBinary
                if ($data === null) {
                    continue;                                               // a link to a file elsewhere, or OLE: nothing to keep
                }
                $name = $a->string(0x3707) ?? $a->string(0x3704) ?? $a->string(0x3001) ?? 'attachment';
                $attachments[] = ['name' => basename(str_replace('\\', '/', $name)) ?: 'attachment', 'type' => strtolower($a->string(0x370E) ?? ''),
                    'data' => $data, 'inline' => (bool) ($a->int(0x7FFE) ?? 0) || ($a->string(0x3712) ?? '') !== ''];
            }
        }

        $text = $p->string(0x1000) ?? '';                                       // PidTagBody
        if (trim($text) === '') {
            $html = $p->binary(0x1013) ?? $p->string(0x1013);                  // PidTagHtml
            $text = $html !== null ? MailMessage::htmlToText(self::utf8($html, $p->int(0x3FDE) ?? $p->codepage)) : '';
        }
        $time = $p->time(0x0039) ?? $p->time(0x0E06) ?? $p->time(0x3007);   // submitted, delivered, created
        return [
            'class' => $p->string(0x001A) ?? 'IPM.Note',
            'subject' => trim($p->string(0x0037) ?? MailMessage::decodeWords($headers['subject'][0] ?? '')),
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'date' => trim($headers['date'][0] ?? '') ?: ($time === null ? '' : $time->format(DATE_RFC2822)),
            'message_id' => trim($p->string(0x1035) ?? $headers['message-id'][0] ?? '', " <>\t\r\n"),
            'text' => trim(preg_replace("/[ \t]+\n/", "\n", str_replace("\r\n", "\n", $text)) ?? $text),
            'attachments' => $attachments,
            'embedded' => $embedded,
        ];
    }

    /** An address only when it is an Internet one (not an Exchange directory name). */
    private static function smtp(?string $email, ?string $type): ?string
    {
        return $email !== null && (strtoupper($type ?? '') === 'SMTP' || str_contains($email, '@')) ? $email : null;
    }

    /** The Windows code page of a locale (an LCID), for messages that do not say theirs. */
    private static function ansi(?int $lcid): ?int
    {
        if ($lcid === null) {
            return null;
        }
        $lang = $lcid & 0x3FF;
        return match (true) {
            $lang === 0x11 => 932,                                             // Japanese
            $lang === 0x04 => in_array($lcid, [0x0404, 0x0C04, 0x1404], true) ? 950 : 936,   // Chinese: traditional, simplified
            $lang === 0x12 => 949,                                             // Korean
            in_array($lang, [0x05, 0x0E, 0x15, 0x18, 0x1B, 0x24, 0x1C], true) || $lcid === 0x041A => 1250,   // Central European
            in_array($lang, [0x19, 0x22, 0x23, 0x02, 0x2F, 0x3F, 0x40, 0x44, 0x50], true) || $lcid === 0x0C1A => 1251,   // Cyrillic
            $lang === 0x08 => 1253, $lang === 0x1F => 1254, $lang === 0x0D => 1255, $lang === 0x01 => 1256,
            in_array($lang, [0x25, 0x26, 0x27], true) => 1257, $lang === 0x2A => 1258, $lang === 0x1E => 874,
            default => 1252,
        };
    }

    /** Bytes in a Windows code page as UTF-8. */
    public static function utf8(string $bytes, ?int $codepage): string
    {
        if ($codepage === 65001 || $codepage === null && mb_check_encoding($bytes, 'UTF-8')) {
            return mb_scrub($bytes, 'UTF-8');
        }
        $names = [1252 => 'Windows-1252', 28591 => 'Windows-1252', 28605 => 'ISO-8859-15', 20127 => 'ASCII', 1251 => 'Windows-1251',
            1254 => 'Windows-1254', 932 => 'CP932', 936 => 'CP936', 949 => 'UHC', 950 => 'CP950', 50220 => 'ISO-2022-JP',
            50221 => 'ISO-2022-JP', 51932 => 'EUC-JP', 20932 => 'EUC-JP', 20866 => 'KOI8-R', 21866 => 'KOI8-U', 28592 => 'ISO-8859-2'];
        if (isset($names[$codepage ?? 1252])) {
            return mb_convert_encoding($bytes, 'UTF-8', $names[$codepage ?? 1252]);
        }
        $out = function_exists('iconv') ? @iconv("CP$codepage", 'UTF-8//IGNORE', $bytes) : false;   // 1250, 1253, 1255…: iconv knows them
        return $out !== false ? $out : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }
}

/** The properties of one storage: fixed-size values from its property stream, the rest from __substg1.0_ streams. */
final class MsgProperties
{
    private array $fixed = [];
    private array $streams;

    public function __construct(private Cfb $cfb, int $storage, int $header, public ?int $codepage)
    {
        $this->streams = $cfb->children($storage);
        $entry = $this->streams['__PROPERTIES_VERSION1.0'] ?? null;
        $bytes = $entry !== null ? $cfb->stream($entry) : '';
        for ($i = $header; $i + 16 <= strlen($bytes); $i += 16) {
            $tag = unpack('V', $bytes, $i)[1];
            $this->fixed[$tag] = substr($bytes, $i + 8, 8);
        }
    }

    public function string(int $id): ?string
    {
        $s = $this->raw($id, 0x001F);
        if ($s !== null) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
        } elseif (($s = $this->raw($id, 0x001E)) !== null) {
            $s = OutlookMsg::utf8($s, $this->codepage);
        }
        return $s === null ? null : rtrim($s, "\0");
    }

    public function binary(int $id): ?string
    {
        return $this->raw($id, 0x0102);
    }

    /** A 16- or 32-bit integer or a boolean. */
    public function int(int $id): ?int
    {
        foreach ([0x0003 => 'V', 0x0002 => 'v', 0x000B => 'v'] as $type => $format) {
            if (isset($this->fixed[($id << 16) | $type])) {
                return unpack($format, $this->fixed[($id << 16) | $type])[1];
            }
        }
        return null;
    }

    /** A time (FILETIME: 100 ns since 1601, UTC) on this server's clock. */
    public function time(int $id): ?DateTimeImmutable
    {
        $v = $this->fixed[($id << 16) | 0x0040] ?? null;
        if ($v === null) {
            return null;
        }
        $ticks = unpack('P', $v)[1];
        if ($ticks <= 0) {
            return null;
        }
        $at = new DateTimeImmutable('@' . (intdiv($ticks, 10_000_000) - 11_644_473_600));
        return $at->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function raw(int $id, int $type): ?string
    {
        $entry = $this->streams[sprintf('__SUBSTG1.0_%04X%04X', $id, $type)] ?? null;
        return $entry !== null && $this->cfb->type($entry) === Cfb::STREAM ? $this->cfb->stream($entry) : null;
    }
}
