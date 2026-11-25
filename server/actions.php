<?php
// Actions: behaviour written as small PHP files, run when notes change.
//
//   // actions/movie-needs-rating.php
//   return ['on' => 'saved', 'match' => 'type:movie -has:rating',
//           'do' => fn(array $node, Actions $notd) => $notd->addTag($node, 'needs-rating')];
//
// on: 'saved' (created or edited), 'created', 'edited' or 'deleted'. match: a query
// the block must answer (optional). do: runs inside the same transaction as the
// change, and what it changes is part of it (undone with it, synced with it).
// Actions live in actions/ and in plugins/<name>/actions/. None run in safe mode.
// An action that throws is reported and skipped; the change itself goes ahead.

declare(strict_types=1);

const ACTIONS_DEPTH = 3;      // actions caused by actions caused by actions, and no further
const ACTIONS_PER_REQUEST = 500;

final class Actions
{
    /** @var list<array{name: string, on: string, match: ?string, do: callable}> */
    private static array $list = [];
    private static int $depth = 0;
    private static int $runs = 0;
    /** @var list<string> problems seen in this request, for tests and the log */
    public static array $problems = [];

    /** Reads every action file; called once per request after plugins are loaded. */
    public static function load(array $dirs): void
    {
        self::$list = [];
        foreach ($dirs as $name => $dir) {
            foreach (glob("$dir/*.php") ?: [] as $file) {
                $label = $name . '/' . basename($file, '.php');
                try {
                    $a = require $file;
                    if (!is_array($a) || !is_callable($a['do'] ?? null) || !in_array($a['on'] ?? 'saved', ['saved', 'created', 'edited', 'deleted'], true)) {
                        throw new RuntimeException('an action returns [on, match, do] with do a function');
                    }
                    self::$list[] = ['name' => $label, 'on' => $a['on'] ?? 'saved', 'match' => $a['match'] ?? null, 'do' => $a['do']];
                } catch (Throwable $e) {
                    self::problem("$label: " . $e->getMessage());
                }
            }
        }
        if (self::$list) {
            Ops::$listeners['actions'] = [self::class, 'onChange'];  // after the index, so match sees the new fields
        }
    }

    public static function onChange(PDO $pdo, string $op, string $id): void
    {
        $event = ['create' => 'created', 'edit' => 'edited', 'delete' => 'deleted'][$op] ?? null;
        if ($event === null) {
            return;
        }
        if (self::$depth >= ACTIONS_DEPTH) {
            self::problem("stopped after $op on $id: actions kept causing each other (more than " . ACTIONS_DEPTH . ' deep)');
            return;
        }
        $node = $event === 'deleted' ? ['id' => $id] : Nodes::get($pdo, $id);
        if ($node === null) {
            return;
        }
        foreach (self::$list as $a) {
            if ($a['on'] !== $event && !($a['on'] === 'saved' && $event !== 'deleted')) {
                continue;
            }
            if (++self::$runs > ACTIONS_PER_REQUEST) {
                self::problem('stopped: more than ' . ACTIONS_PER_REQUEST . ' action runs in one request');
                return;
            }
            self::$depth++;
            try {
                if ($a['match'] === null || $event === 'deleted' || self::matches($pdo, $a['match'], $id)) {
                    ($a['do'])($node, new self($pdo));
                    $node = Nodes::get($pdo, $id) ?? $node; // the next action sees what this one did
                }
            } catch (Throwable $e) {
                self::problem("{$a['name']}: " . $e->getMessage());
            } finally {
                self::$depth--;
            }
        }
    }

    public static function matches(PDO $pdo, string $query, string $id): bool
    {
        $c = Query::compile($query);
        $st = $pdo->prepare("SELECT 1 FROM nodes n WHERE n.id = ? AND ({$c['where']})");
        $st->execute([$id, ...$c['params']]);
        return (bool) $st->fetchColumn();
    }

    private static function problem(string $message): void
    {
        self::$problems[] = $message;
        if (PHP_SAPI !== 'cli' || getenv('NOTD_LOG_ACTIONS')) {
            error_log("notd: action $message");
        }
    }

    // ------------------------------------------------- what an action can do

    private function __construct(private readonly PDO $pdo)
    {
    }

    public function edit(string $id, string $text): void
    {
        Ops::apply($this->pdo, [['op' => 'edit', 'id' => $id, 'text' => $text]]);
    }

    /** Adds #tag to the block's first line unless it is already tagged. */
    public function addTag(array $node, string $tag): void
    {
        $text = Nodes::get($this->pdo, $node['id'])['text'] ?? '';
        if (in_array(['tag', title_key($tag)], parse_text($text)['links'], true)) {
            return;
        }
        $mark = preg_match('/^[\p{L}\p{N}_][\p{L}\p{N}_\/-]*$/u', $tag) ? "#$tag" : "#[[$tag]]";
        $nl = strpos($text, "\n");
        $this->edit($node['id'], $nl === false ? rtrim($text) . " $mark" : rtrim(substr($text, 0, $nl)) . " $mark" . substr($text, $nl));
    }

    /** Sets {key:: value} in the block's text (adds it to the first line, or replaces it). */
    public function setField(array $node, string $key, string $value): void
    {
        $text = Nodes::get($this->pdo, $node['id'])['text'] ?? '';
        $re = '/\{' . preg_quote($key, '/') . '::[^}\n]*\}/i';
        $field = '{' . $key . ':: ' . $value . '}';
        if (preg_match($re, $text)) {
            $next = (string) preg_replace($re, $field, $text, 1);
        } else {
            $nl = strpos($text, "\n");
            $next = $nl === false ? rtrim($text) . " $field" : rtrim(substr($text, 0, $nl)) . " $field" . substr($text, $nl);
        }
        if ($next !== $text) {
            $this->edit($node['id'], $next);
        }
    }

    /** Adds a block under a page or block; returns its id. */
    public function create(string $parentId, string $text): string
    {
        return Ops::apply($this->pdo, [['op' => 'create', 'parent' => $parentId, 'text' => $text]])['results'][0]['id'];
    }

    public function query(string $q): array
    {
        return Query::run($this->pdo, $q);
    }

    public function node(string $id): ?array
    {
        return Nodes::get($this->pdo, $id);
    }

    /** Runs a program after the change is saved: an argument list, no shell. See Jobs. */
    public function run(array $argv, ?string $key = null, int $timeout = 30, ?string $cwd = null): void
    {
        Jobs::run($this->pdo, $argv, $key, $timeout, $cwd);
    }

    /** Queues a job for a plugin's handler, after the change is saved (and $delay seconds of quiet). */
    public function queue(string $kind, array $payload = [], ?string $key = null, int $delay = 0): void
    {
        Jobs::queue($this->pdo, $kind, $payload, $key, $delay);
    }
}
