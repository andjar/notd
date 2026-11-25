<?php
// The tasks plugin on the server: indexes a block's keyword as {status::todo}
// and the like, and answers the query filter task: (open, todo, doing,
// waiting, done, cancelled, any).

declare(strict_types=1);

return function (PluginApi $notd): void {
    $notd->indexer(function (string $text): array {
        return preg_match('/^(TODO|DOING|WAITING|DONE|CANCELLED) /', $text, $m) ? [['status', strtolower($m[1])]] : [];
    });
    $notd->filter('task', function (string $op, string $value): array {
        $states = match (strtolower($value)) {
            'open' => ['todo', 'doing', 'waiting'],
            'any', 'all' => ['todo', 'doing', 'waiting', 'done', 'cancelled'],
            'todo', 'doing', 'waiting', 'done', 'cancelled' => [strtolower($value)],
            default => throw new HttpError(400, 'bad_query', 'task: takes open, todo, doing, waiting, done, cancelled or any.'),
        };
        $in = implode(',', array_fill(0, count($states), '?'));
        $sql = "n.id IN (SELECT node_id FROM fields WHERE key = 'status' AND value IN ($in))";
        return [$op === '!=' ? "NOT $sql" : $sql, $states];
    });
};
