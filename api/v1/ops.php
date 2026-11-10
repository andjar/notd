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

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\NoteOps;
use App\OpValidationException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed. Use POST.', 405);
    return;
}

$input = notd_json_input();
if (!isset($input['ops']) || !is_array($input['ops'])) {
    ApiResponse::error('Request body must be JSON: {"ops": [...]}', 400);
    return;
}
if (count($input['ops']) > 500) {
    ApiResponse::error('At most 500 ops per request.', 413);
    return;
}

try {
    $pdo = get_db_connection();
    $result = notd_write($pdo, static fn(PDO $pdo) => (new NoteOps($pdo))->apply($input['ops']));
    ApiResponse::success($result);
} catch (OpValidationException $e) {
    ApiResponse::error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('ops.php failed: ' . $e->getMessage());
    ApiResponse::error('Could not apply operations: ' . $e->getMessage(), notd_is_busy($e) ? 503 : 500);
}
