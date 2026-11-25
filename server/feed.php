<?php
// The change feed: every change has a number (seq). A client remembers the last
// number it has seen and asks for what came after, to update other devices.

declare(strict_types=1);

const FEED_LIMIT_MAX = 2000;

final class Feed
{
    /**
     * Changes after $since, oldest first, at most one entry per node (its latest change in this
     * page), each with the node as it is now (null once deleted). 'next' is the seq to ask from
     * next time; 'more' says whether there is more to fetch right away.
     */
    public static function since(PDO $pdo, int $since, int $limit = 500): array
    {
        $limit = max(1, min($limit, FEED_LIMIT_MAX));
        $st = $pdo->prepare('SELECT seq, node_id, op, clock, at FROM changes WHERE seq > ? ORDER BY seq LIMIT ?');
        $st->execute([max(0, $since), $limit]);
        $rows = $st->fetchAll();
        $latest = [];
        foreach ($rows as $row) {
            unset($latest[$row['node_id']]);              // keep only the newest, in feed order
            $latest[$row['node_id']] = $row;
        }
        $changes = [];
        foreach ($latest as $row) {
            $node = $row['op'] === 'delete' ? null : Nodes::get($pdo, $row['node_id']);
            $changes[] = ['seq' => (int) $row['seq'], 'op' => $node === null ? 'delete' : $row['op'], 'id' => $row['node_id'],
                'clock' => $row['clock'], 'at' => (int) $row['at'], 'node' => $node];
        }
        return ['changes' => $changes, 'next' => $rows ? (int) end($rows)['seq'] : max(0, min($since, Ops::lastSeq($pdo))),
            'more' => count($rows) === $limit];
    }
}
