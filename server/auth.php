<?php
// Who may talk to the server.
//
// Without a password, only requests from this computer are answered. With one
// (password_hash in config.local.php, set with tools/password.php), every
// endpoint but ping and login needs the login cookie. The cookie is signed
// with a secret kept in the data folder and the password hash, so changing the
// password logs every device out. POSTs from another site are refused.

declare(strict_types=1);

const AUTH_COOKIE = 'notd_login';
const AUTH_DAYS = 30;
const AUTH_MAX_FAILS = 5;
const AUTH_LOCK_SECONDS = 900;
const AUTH_OPEN = ['ping', 'login', 'logout'];

final class Auth
{
    /** Throws unless this request may reach $endpoint. */
    public static function check(string $method, string $endpoint): void
    {
        if ($method === 'POST' && !self::sameOrigin()) {
            throw new HttpError(403, 'cross_origin', 'Requests from another site are refused.');
        }
        if (in_array($endpoint, AUTH_OPEN, true) || self::status()['authenticated']) {
            return;
        }
        if (self::hash() === null) {
            throw new HttpError(403, 'password_required', 'notd answers other devices only once a password is set: run php tools/password.php on the server.');
        }
        throw new HttpError(401, 'login_required', 'Log in first.');
    }

    /** ['passwordSet', 'local', 'authenticated'] for this request. */
    public static function status(): array
    {
        $hash = self::hash();
        $local = self::isLocal();
        return ['passwordSet' => $hash !== null, 'local' => $local,
            'authenticated' => $hash === null ? $local : self::validCookie($_COOKIE[AUTH_COOKIE] ?? '', $hash)];
    }

    public static function login(PDO $pdo, string $password): void
    {
        $hash = self::hash();
        if ($hash === null) {
            throw new HttpError(400, 'no_password', 'No password is set.');
        }
        $key = 'login_fail:' . ($_SERVER['REMOTE_ADDR'] ?? '?');
        $st = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $st->execute([$key]);
        [$fails, $since] = array_map('intval', explode('|', (string) ($st->fetchColumn() ?: '0|0')));
        if (time() - $since > AUTH_LOCK_SECONDS) {
            [$fails, $since] = [0, time()];
        }
        if ($fails >= AUTH_MAX_FAILS) {
            throw new HttpError(429, 'too_many_attempts', 'Too many wrong passwords; try again in 15 minutes.');
        }
        if (!password_verify($password, $hash)) {
            $pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')->execute([$key, ($fails + 1) . "|$since"]);
            throw new HttpError(401, 'wrong_password', 'Wrong password.');
        }
        $pdo->prepare('DELETE FROM meta WHERE key = ?')->execute([$key]);
        $expires = time() + AUTH_DAYS * 86400;
        self::setCookie($expires . '.' . self::mac((string) $expires, $hash), $expires);
    }

    public static function logout(): void
    {
        self::setCookie('', time() - 3600);
    }

    private static function hash(): ?string
    {
        $hash = Config::get('password_hash');
        return $hash === null || $hash === '' ? null : $hash;
    }

    /** From this computer, and not passed on by a proxy (which would make every request look local). */
    private static function isLocal(): bool
    {
        $addr = $_SERVER['REMOTE_ADDR'] ?? '';
        $proxied = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_FORWARDED']) || isset($_SERVER['HTTP_X_REAL_IP']);
        return !$proxied && in_array($addr, ['127.0.0.1', '::1'], true);
    }

    private static function sameOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && $origin !== 'null') {
            return strcasecmp((string) parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ":$p" : ''), $_SERVER['HTTP_HOST'] ?? '') === 0;
        }
        return ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'cross-site';
    }

    private static function validCookie(string $cookie, string $hash): bool
    {
        [$expires, $mac] = array_pad(explode('.', $cookie, 2), 2, '');
        return ctype_digit($expires) && (int) $expires > time() && hash_equals(self::mac($expires, $hash), $mac);
    }

    private static function mac(string $data, string $hash): string
    {
        $file = Config::dataDir() . '/secret';
        if (!is_file($file)) {
            file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
            chmod($file, 0600);
        }
        return hash_hmac('sha256', $data, trim((string) file_get_contents($file)) . $hash);
    }

    private static function setCookie(string $value, int $expires): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(AUTH_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    }
}
