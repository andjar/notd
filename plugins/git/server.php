<?php
// The git plugin: version history from the Markdown export.
//
// After changes stop for git_delay seconds (default 60), a job writes the
// export into a git repository (git_dir, default <data folder>/history) and
// commits whatever changed. Endpoints read a page's history and restore an
// old version through ordinary operations, so a restore is itself a change
// (and itself in the history).

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/lib/import.php';

final class GitHistory
{
    public static function dir(): string
    {
        return (string) Config::get('git_dir', Config::dataDir() . '/history');
    }

    /** [ok, output] of a git command in the history repository. */
    public static function git(array $args, int $timeout = 60): array
    {
        return Jobs::exec(['git', '-c', 'user.name=notd', '-c', 'user.email=notd@localhost', '-c', 'commit.gpgsign=false', ...$args], $timeout, self::dir());
    }

    /** The job: export, then commit what changed. */
    public static function commit(): string
    {
        $dir = self::dir();
        if (!is_dir("$dir/.git")) {
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create $dir");
            }
            [$ok, $out] = self::git(['init', '-q', '-b', 'main']);
            if (!$ok) {
                throw new RuntimeException("git init failed (is git installed?): $out");
            }
        }
        $s = Markdown::export(Db::get(), $dir);
        self::git(['add', '-A']);
        [$clean] = self::git(['diff', '--cached', '--quiet']);
        if ($clean) {
            return 'Nothing changed.';
        }
        [$ok, $out] = self::git(['commit', '-q', '-m', "notd: {$s['written']} written, {$s['deleted']} deleted"]);
        if (!$ok) {
            throw new RuntimeException("git commit failed: $out");
        }
        return trim(self::git(['log', '-1', '--format=%h %s'])[1]);
    }

    /** The file of a page in the export, relative to the repository. */
    public static function file(string $title): string
    {
        return Markdown::fileName(['title' => $title]);
    }

    /** Versions of a page, newest first: [['commit', 'time' (ms), 'message']]. */
    public static function history(string $title, int $limit = 50): array
    {
        if (!is_dir(self::dir() . '/.git')) {
            return [];
        }
        [$ok, $out] = self::git(['log', '-n', (string) max(1, min($limit, 500)), '--format=%H%x09%at%x09%s', '--', self::file($title)]);
        if (!$ok) {
            return [];
        }
        $out = trim($out);
        return $out === '' ? [] : array_map(function (string $line): array {
            [$c, $t, $m] = explode("\t", $line, 3) + ['', '0', ''];
            return ['commit' => $c, 'time' => (int) $t * 1000, 'message' => $m];
        }, explode("\n", $out));
    }

    /** The page's Markdown as it was in a commit. */
    public static function version(string $title, string $commit): string
    {
        if (!preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
            throw new HttpError(400, 'bad_request', 'A commit is 7 to 40 hexadecimal digits.');
        }
        [$ok, $out] = self::git(['show', "$commit:" . self::file($title)]);
        if (!$ok) {
            throw new HttpError(404, 'not_found', "No version of \"$title\" in $commit.");
        }
        return $out;
    }

    /** Replaces the page's text and blocks with an old version, in one batch. */
    public static function restore(PDO $pdo, string $title, string $commit): array
    {
        $page = Nodes::byTitle($pdo, $title);
        if ($page === null) {
            throw new HttpError(404, 'not_found', "No page \"$title\".");
        }
        $old = Import::parse(self::version($title, $commit), self::file($title));
        $ops = [];
        foreach (Nodes::children($pdo, $page['id']) as $child) {
            $ops[] = ['op' => 'delete', 'id' => $child['id']];
        }
        $ops[] = ['op' => 'edit', 'id' => $page['id'], 'text' => $old['text']];
        $taken = [];
        $add = function (array $blocks, string $parent) use (&$add, &$ops, &$taken): void {
            foreach ($blocks as $b) {
                $id = $b['id'] !== null && !isset($taken[$b['id']]) ? $b['id'] : new_id();
                $taken[$id] = true;
                $ops[] = ['op' => 'create', 'id' => $id, 'parent' => $parent, 'text' => $b['text'], 'collapsed' => $b['collapsed']];
                $add($b['children'], $id);
            }
        };
        $add($old['blocks'], $page['id']);
        return Db::write($pdo, function (PDO $pdo) use ($ops): array {
            // Blocks that kept their id are deleted first, then made again: delete ops run before the creates.
            return Ops::apply($pdo, $ops);
        });
    }
}

return function (PluginApi $notd): void {
    $notd->jobHandler('git.commit', fn(array $payload): string => GitHistory::commit());
    $notd->onChange(function (PDO $pdo, string $op, string $id): void {
        Jobs::queue($pdo, 'git.commit', [], 'git.commit', (int) Config::get('git_delay', '60'));
    });
    $title = fn(Request $r): string => $r->string('page');
    $notd->endpoint('history', ['GET' => fn(Request $r): array => GitHistory::history($title($r), (int) ($r->query['limit'] ?? 50))]);
    $notd->endpoint('version', ['GET' => fn(Request $r): array => ['markdown' => GitHistory::version($title($r), $r->string('commit'))]]);
    $notd->endpoint('restore', ['POST' => function (Request $r): array {
        $b = $r->body();
        if (!is_string($b['page'] ?? null) || !is_string($b['commit'] ?? null)) {
            throw new HttpError(400, 'bad_request', 'Send {"page": "...", "commit": "..."}.');
        }
        return GitHistory::restore(Db::get(), $b['page'], $b['commit']);
    }]);
    $notd->endpoint('commit', ['POST' => fn(Request $r): array => ['result' => GitHistory::commit()]]);
};
