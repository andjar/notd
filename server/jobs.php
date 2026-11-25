<?php
// Jobs: effects outside notd (running a program, committing to git) happen
// after the change is saved, never inside it. A job is queued in the change's
// transaction, so it exists exactly when the change does, and runs after the
// response (or from `php tools/jobs.php`, for cron). Each job has a time
// limit, its output is kept, and a failed one is tried again, up to 3 times.
// A waiting job with the same key is replaced, so ten quick edits give one
// git commit, not ten.

declare(strict_types=1);

const JOBS_ATTEMPTS = 3;
const JOBS_KEEP = 200;          // finished jobs kept in the log

final class Jobs
{
    /** Plugins' job handlers by name: fn(array $payload): string (what to log). */
    public static array $handlers = [];
    private static bool $queued = false;

    /** Queues a program to run, as an argument list (no shell): ['git', 'commit', '-m', 'notes']. */
    public static function run(PDO $pdo, array $argv, ?string $key = null, int $timeout = 30, ?string $cwd = null): void
    {
        if (!$argv || !array_is_list($argv)) {
            throw new InvalidArgumentException('A program to run is a list: [program, argument, ...].');
        }
        self::queue($pdo, 'run', ['argv' => array_map('strval', $argv), 'timeout' => $timeout, 'cwd' => $cwd], $key);
    }

    /** Queues a job for a plugin's handler. */
    public static function queue(PDO $pdo, string $kind, array $payload, ?string $key = null): void
    {
        Db::write($pdo, function (PDO $pdo) use ($kind, $payload, $key): void {
            if ($key !== null) {
                $pdo->prepare("DELETE FROM jobs WHERE key = ? AND status = 'waiting'")->execute([$key]);
            }
            $pdo->prepare('INSERT INTO jobs (key, kind, payload, created) VALUES (?, ?, ?, ?)')
                ->execute([$key, $kind, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now_ms()]);
        });
        self::$queued = true;
    }

    /** True when this request queued a job (so the server runs the queue once it has answered). */
    public static function queuedNow(): bool
    {
        return self::$queued;
    }

    /** Runs waiting jobs, oldest first, for at most $seconds. Returns how many ran. */
    public static function work(PDO $pdo, float $seconds = 20.0): int
    {
        $until = microtime(true) + $seconds;
        $ran = 0;
        while (microtime(true) < $until) {
            $job = Db::write($pdo, function (PDO $pdo): ?array {
                $job = $pdo->query("SELECT * FROM jobs WHERE status = 'waiting' ORDER BY id LIMIT 1")->fetch();
                if (!$job) {
                    return null;
                }
                $pdo->prepare("UPDATE jobs SET status = 'running', started = ?, attempts = attempts + 1 WHERE id = ?")->execute([now_ms(), $job['id']]);
                return $job;
            });
            if ($job === null) {
                break;
            }
            [$ok, $output] = self::perform($job);
            $status = $ok ? 'done' : ((int) $job['attempts'] + 1 < JOBS_ATTEMPTS ? 'waiting' : 'failed');
            Db::write($pdo, function (PDO $pdo) use ($job, $status, $output): void {
                if ($status === 'waiting' && $job['key'] !== null) {   // a newer job with this key wins over a retry
                    $st = $pdo->prepare("SELECT 1 FROM jobs WHERE key = ? AND status = 'waiting' AND id <> ?");
                    $st->execute([$job['key'], $job['id']]);
                    if ($st->fetchColumn()) {
                        $status = 'failed';
                    }
                }
                $pdo->prepare('UPDATE jobs SET status = ?, finished = ?, output = ? WHERE id = ?')->execute([$status, now_ms(), substr($output, -4000), $job['id']]);
                $pdo->prepare("DELETE FROM jobs WHERE status IN ('done', 'failed') AND id NOT IN (SELECT id FROM jobs WHERE status IN ('done', 'failed') ORDER BY id DESC LIMIT " . JOBS_KEEP . ')')->execute();
            });
            $ran++;
            if (!$ok) {
                break; // try the rest (and this one) next time
            }
        }
        return $ran;
    }

    /** The job log, newest first. */
    public static function recent(PDO $pdo, int $limit = 50): array
    {
        $st = $pdo->prepare('SELECT id, key, kind, status, attempts, created, started, finished, output FROM jobs ORDER BY id DESC LIMIT ?');
        $st->execute([max(1, min($limit, JOBS_KEEP))]);
        return array_map(fn($j) => ['id' => (int) $j['id'], 'attempts' => (int) $j['attempts'], 'created' => (int) $j['created'],
            'started' => $j['started'] === null ? null : (int) $j['started'], 'finished' => $j['finished'] === null ? null : (int) $j['finished']] + $j, $st->fetchAll());
    }

    /** [ok, output] */
    private static function perform(array $job): array
    {
        $payload = json_decode($job['payload'], true) ?: [];
        try {
            if ($job['kind'] === 'run') {
                return self::exec($payload['argv'], (int) ($payload['timeout'] ?? 30), $payload['cwd'] ?? null);
            }
            $handler = self::$handlers[$job['kind']] ?? null;
            if ($handler === null) {
                return [false, "No handler for \"{$job['kind']}\" (is its plugin switched off?)"];
            }
            return [true, (string) $handler($payload)];
        } catch (Throwable $e) {
            return [false, get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /** Runs a program without a shell, with a time limit. */
    public static function exec(array $argv, int $timeout = 30, ?string $cwd = null): array
    {
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
        if (!is_resource($proc)) {
            return [false, 'Could not start ' . $argv[0]];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                proc_close($proc);
                return [$st['exitcode'] === 0, $out . ($st['exitcode'] === 0 ? '' : "\n(exit code {$st['exitcode']})")];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                proc_close($proc);
                return [false, $out . "\n(stopped after $timeout s)"];
            }
            usleep(20000);
        }
    }
}
