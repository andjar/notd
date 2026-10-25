<?php
/**
 * POST /api/v1/ops.php — the only write endpoint for notes.
 *
 * Request:  {"ops": [ {op_id, type, id, ...}, ... ]}
 *   create   {op_id, type:"create", id, page_id, parent_id|null, after|null, sort_key?, content?, collapsed?}
 *   move     {op_id, type:"move",   id, parent_id|null, after|null, sort_key?}
 *   edit     {op_id, type:"edit",   id, content}
 *   collapse {op_id, type:"collapse", id, collapsed}
 *   delete   {op_id, type:"delete", id}            (removes the whole subtree)
 *
 * Response: {"status":"success","data":{results, notes, deleted, warnings}}
 *   notes    = authoritative state of every note the batch touched
 *   deleted  = ids removed by the batch (including descendants)
 *   warnings = ops skipped because their target vanished (the client resyncs)
 *
 * All ops run in order inside one transaction: the batch applies completely
 * or not at all. Already-applied op_ids are skipped (safe to retry).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../NoteOps.php';

use App\ApiResponse;
use App\NoteOps;
use App\OpValidationException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed. Use POST.', 405);
    return;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['ops']) || !is_array($input['ops'])) {
    ApiResponse::error('Request body must be JSON: {"ops": [...]}', 400);
    return;
}
if (count($input['ops']) > 500) {
    ApiResponse::error('At most 500 ops per request.', 413);
    return;
}

$attempts = 0;
while (true) {
    $attempts++;
    $pdo = get_db_connection();
    $inTx = false;
    try {
        // IMMEDIATE takes the write lock up front, so two concurrent batches
        // serialize instead of failing halfway with SQLITE_BUSY.
        $pdo->exec('BEGIN IMMEDIATE');
        $inTx = true;
        $result = (new NoteOps($pdo))->apply($input['ops']);
        $pdo->exec('COMMIT');
        $inTx = false;
        ApiResponse::success($result);
        return;
    } catch (OpValidationException $e) {
        if ($inTx) $pdo->exec('ROLLBACK');
        ApiResponse::error($e->getMessage(), 400);
        return;
    } catch (Throwable $e) {
        if ($inTx) $pdo->exec('ROLLBACK');
        $busy = $e instanceof PDOException && (in_array((int)($e->errorInfo[1] ?? 0), [5, 6], true)
            || stripos($e->getMessage(), 'database is locked') !== false);
        if ($busy && $attempts < 5) {
            usleep(100000 * $attempts);
            continue;
        }
        error_log('ops.php failed: ' . $e->getMessage());
        ApiResponse::error('Could not apply operations: ' . $e->getMessage(), $busy ? 503 : 500);
        return;
    }
}
