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

    public function __construct(public readonly string $method, public readonly array $query, private readonly string $raw)
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

final class Http
{
    public static function handle(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        try {
            $data = self::dispatch($method, $path, new Request($method, $_GET, (string) file_get_contents('php://input')));
            self::send(200, $data);
        } catch (HttpError $e) {
            self::send($e->status, ['error' => ['code' => $e->codeName, 'message' => $e->getMessage()]]);
        } catch (Throwable $e) {
            error_log('notd: ' . $e);
            self::send(500, ['error' => ['code' => 'internal', 'message' => 'Internal error; see the server log.']]);
        }
    }

    public static function dispatch(string $method, string $path, Request $request): mixed
    {
        if (!preg_match('#^/api/v1/([a-z][a-z0-9_-]*)$#', $path, $m)) {
            throw new HttpError(404, 'not_found', "No such path: $path");
        }
        $file = __DIR__ . '/api/' . $m[1] . '.php';
        if (!is_file($file)) {
            throw new HttpError(404, 'not_found', "No such endpoint: {$m[1]}");
        }
        $handlers = require $file;
        if (!isset($handlers[$method])) {
            throw new HttpError(405, 'method_not_allowed', "$method is not allowed here; use " . implode(' or ', array_keys($handlers)) . '.');
        }
        return $handlers[$method]($request);
    }

    private static function send(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
