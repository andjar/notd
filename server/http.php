<?php
// HTTP plumbing: routes /api/v1/<name> to server/api/<name>.php and turns
// what the endpoint returns (or throws) into a JSON response.
//
// An endpoint file returns an array of handlers by method:
//   return ['GET' => fn(Request $r) => [...data...]];
// Errors are thrown as HttpError and come back as
//   {"error": {"code": "not_found", "message": "..."}}

declare(strict_types=1);

const NOTD_VERSION = '4.0.0-dev';
const NOTD_PROTOCOL = 1;

final class HttpError extends Exception
{
    public function __construct(public readonly int $status, public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }
}

final class Request
{
    private ?array $body = null;

    public function __construct(public readonly string $method, public readonly array $query, private readonly string $raw,
        public readonly array $files = [], public readonly array $form = [])
    {
    }

    /** A required query parameter. */
    public function string(string $name): string
    {
        $value = $this->query[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new HttpError(400, 'bad_request', "\"$name\" is missing.");
        }
        return $value;
    }

    /** The JSON request body as an array; an empty body is an empty array. */
    public function body(): array
    {
        if ($this->body === null) {
            if (trim($this->raw) === '') {
                return $this->body = [];
            }
            $data = json_decode($this->raw, true);
            if (!is_array($data)) {
                throw new HttpError(400, 'bad_json', 'The request body is not a JSON object.');
            }
            $this->body = $data;
        }
        return $this->body;
    }
}

/** A file to stream instead of JSON. */
final class FileResponse
{
    /** $code: a plugin's own file (script, style), shown inline and checked for changes on every load. */
    public function __construct(public readonly string $path, public readonly string $mime, public readonly string $name,
        public readonly string $etag, public readonly bool $code = false)
    {
    }

    public function send(): void
    {
        $inline = preg_match('#^(image/(png|jpeg|gif|webp|avif)|application/pdf|text/plain|audio/|video/)#', $this->mime) === 1;
        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '', '"') === $this->etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . $this->mime);
        header('Content-Length: ' . filesize($this->path));
        header('ETag: "' . $this->etag . '"');
        header('X-Content-Type-Options: nosniff');
        if ($this->code) {
            header('Cache-Control: no-cache');
            readfile($this->path);
            return;
        }
        header('Cache-Control: private, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($this->name));
        readfile($this->path);
    }
}

final class Http
{
    public static function handle(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = str_ends_with($script, '/index.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));       // notd installed in a sub-folder
        }
        if ($method === 'GET' && ($path === '/' || $path === '')) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-cache');
            $html = (string) file_get_contents(dirname(__DIR__) . '/client/index.html');
            $hashes = '';                                   // the inline import map is allowed by its hash
            preg_match_all('#<script type="importmap">(.*?)</script>#s', $html, $m);
            foreach ($m[1] as $js) {
                $hashes .= " 'sha256-" . base64_encode(hash('sha256', $js, true)) . "'";
            }
            header("Content-Security-Policy: default-src 'self'; script-src 'self'$hashes; img-src 'self' data: blob:; "
                . "style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
            echo $html;
            return;
        }
        try {
            if ($method === 'GET' && preg_match('#^/plugins/([a-z0-9][a-z0-9_-]*)/(.+)$#', $path, $m)) {
                require_once __DIR__ . '/core.php';
                Plugins::file($m[1], rawurldecode($m[2]))->send();
                return;
            }
            $data = self::dispatch($method, $path, new Request($method, $_GET, (string) file_get_contents('php://input'), $_FILES, $_POST));
            $data instanceof FileResponse ? $data->send() : self::send(200, $data);
            if (class_exists(Jobs::class, false) && (Jobs::queuedNow() || Jobs::due(Db::get()))) {
                self::finishResponse();
                Jobs::work(Db::get());                  // outside effects run once the answer is out
            }
        } catch (HttpError $e) {
            self::send($e->status, ['error' => ['code' => $e->codeName, 'message' => $e->getMessage()]]);
        } catch (Throwable $e) {
            error_log('notd: ' . $e);
            self::send(500, ['error' => ['code' => 'internal', 'message' => 'Internal error; see the server log.']]);
        }
    }

    public static function dispatch(string $method, string $path, Request $request): mixed
    {
        if (!preg_match('#^/api/v1/([a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)?)$#', $path, $m)) {
            throw new HttpError(404, 'not_found', "No such path: $path");
        }
        require_once __DIR__ . '/core.php';
        Auth::check($method, $m[1]);
        Plugins::load(Db::get());
        $file = __DIR__ . '/api/' . $m[1] . '.php';
        $handlers = str_contains($m[1], '.') ? (Plugins::$endpoints[$m[1]] ?? null) : (is_file($file) ? require $file : null);
        if ($handlers === null) {
            throw new HttpError(404, 'not_found', "No such endpoint: {$m[1]}");
        }
        if (!isset($handlers[$method])) {
            throw new HttpError(405, 'method_not_allowed', "$method is not allowed here; use " . implode(' or ', array_keys($handlers)) . '.');
        }
        return $handlers[$method]($request);
    }

    /** Lets the client go before slow work: closes the connection where the server allows it. */
    private static function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            flush();
        }
        ignore_user_abort(true);
    }

    private static function send(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
