<?php
/**
 * POST /api/v1/query_notes.php  {"sql_query": "...", "page"?: 1, "per_page"?: 20, "include_properties"?: true}
 *
 * Runs a SQL query whose first column is a note id and returns those notes,
 * in the order the query produced them. This is what SQL{...} blocks use.
 *
 * The query runs on a read-only connection, so it can never change data.
 * Unless ADVANCED_QUERY_MODE is on it must also have one of three shapes and
 * stay within the Notes and Properties tables.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\DataManager;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed. Use POST.', 405);
    return;
}
$input = notd_json_input();
if (!isset($input['sql_query']) || !is_string($input['sql_query']) || trim($input['sql_query']) === '') {
    ApiResponse::error('Missing sql_query parameter.', 400);
    return;
}
$sqlQuery = trim($input['sql_query']);

if (!defined('ADVANCED_QUERY_MODE') || ADVANCED_QUERY_MODE !== true) {
    $allowedPatterns = [
        '/^SELECT\s+id\s+FROM\s+Notes\s+WHERE\s+/i',
        '/^SELECT\s+(DISTINCT\s+)?N\.id\s+FROM\s+Notes\s+N\s+JOIN\s+Properties\s+P\s+ON\s+N\.id\s*=\s*P\.note_id\s+WHERE\s+/i',
        '/^SELECT\s+id\s+FROM\s+Notes\s+WHERE\s+id\s+IN\s*\(\s*SELECT\s+note_id\s+FROM\s+Properties\s+WHERE\s+/i',
    ];
    $matches = false;
    foreach ($allowedPatterns as $pattern) {
        $matches = $matches || preg_match($pattern, $sqlQuery);
    }
    if (!$matches) {
        ApiResponse::error('Query must be one of the allowed patterns.', 400);
        return;
    }
    if (strpos($sqlQuery, ';') !== false) {
        ApiResponse::error('Query must not contain semicolons.', 400);
        return;
    }
    $forbidden = 'INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|EXEC|CREATE|ATTACH|DETACH|REPLACE|PRAGMA|UNION|INTERSECT|EXCEPT|'
        . 'Webhooks|WebhookEvents|Attachments|AppliedOps|Pages|sqlite_\w+|load_extension';
    if (preg_match('/\b(' . $forbidden . ')\b/i', $sqlQuery)) {
        ApiResponse::error('Query contains forbidden SQL keywords.', 400);
        return;
    }
    $allowedColumns = '/\b(N|P|Notes|Properties)\.(id|content|page_id|parent_note_id|created_at|updated_at|sort_key|version|collapsed|active|internal|note_id|name|value|weight)\b/i';
    if (preg_match('/\b(N|P|Notes|Properties)\.\w+/i', preg_replace($allowedColumns, '', $sqlQuery))) {
        ApiResponse::error('Query references unauthorized columns.', 400);
        return;
    }
}

get_db_connection(); // makes sure the database and its schema exist
try {
    $readOnly = defined('PDO::SQLITE_ATTR_OPEN_FLAGS');
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ] + ($readOnly ? [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY] : []));
    $pdo->exec('PRAGMA query_only = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = max(1, min(100, (int)($input['per_page'] ?? 20)));

    $total = (int)$pdo->query('SELECT COUNT(*) FROM (' . $sqlQuery . ')')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM (' . $sqlQuery . ') LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));

    // Full notes, in the order the query returned the ids.
    $byId = [];
    $dataManager = new DataManager($pdo);
    foreach ($dataManager->withPageNames($dataManager->getNotesByIds($ids, true)) as $note) {
        if (($input['include_properties'] ?? true) === false) {
            unset($note['properties']);
        }
        $byId[(string)$note['id']] = $note;
    }
    $notes = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $notes[] = $byId[$id];
            unset($byId[$id]);
        }
    }

    ApiResponse::success($notes, 200, ['pagination' => [
        'current_page' => $page,
        'per_page' => $perPage,
        'total_items' => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]]);
} catch (PDOException $e) {
    ApiResponse::error('The query could not be run: ' . $e->getMessage(), 400);
}
