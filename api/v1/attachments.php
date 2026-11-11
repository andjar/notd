<?php
/**
 * /api/v1/attachments.php
 *
 *   GET ?note_id=X     attachments of a note
 *   GET                all attachments (?page, ?per_page, ?sort_by, ?sort_order, ?filter_by_name, ?filter_by_type)
 *   GET ?file=PATH     the file itself (only needed when UPLOADS_DIR is outside the web root)
 *   POST multipart     note_id + attachmentFile (one or several files): upload
 *   POST JSON          {"action": "delete", "attachment_id": N}  (or DELETE / _method=DELETE)
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\AttachmentException;
use App\Attachments;
use App\Ordering;

$attachments = new Attachments(get_db_connection());
$input = notd_json_input();
$method = notd_request_method();
if ($method === 'POST' && ($input['action'] ?? null) === 'delete') {
    $method = 'DELETE';
}

try {
    if ($method === 'GET') {
        if (isset($_GET['file'])) {
            $attachments->send((string)$_GET['file']);
        } elseif (isset($_GET['note_id'])) {
            if (!Ordering::isValidId((string)$_GET['note_id'])) {
                ApiResponse::error('Invalid note id.', 400);
                return;
            }
            ApiResponse::success($attachments->forNote((string)$_GET['note_id']));
        } else {
            ApiResponse::success($attachments->all($_GET));
        }
    } elseif ($method === 'POST') {
        $noteId = (string)($_POST['note_id'] ?? '');
        if (!Ordering::isValidId($noteId)) {
            ApiResponse::error('Invalid input for note ID.', 400);
            return;
        }
        if (!isset($_FILES['attachmentFile'])) {
            // A body larger than post_max_size arrives with no fields at all.
            ApiResponse::error(empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])
                ? 'The upload is larger than the server allows.' : 'attachmentFile is required.', 400);
            return;
        }
        $files = $_FILES['attachmentFile'];
        $list = [];
        if (is_array($files['name'])) {
            foreach (array_keys($files['name']) as $i) {
                $list[] = array_combine(array_keys($files), array_column($files, $i));
            }
        } else {
            $list[] = $files;
        }
        $saved = [];
        $failed = [];
        foreach ($list as $file) {
            try {
                $saved[] = $attachments->store($noteId, $file);
            } catch (AttachmentException $e) {
                $failed[] = ['name' => (string)($file['name'] ?? ''), 'message' => $e->getMessage(), 'status' => $e->getCode()];
            }
        }
        if (!$saved) {
            ApiResponse::error($failed[0]['message'] ?? 'No file was uploaded.', $failed[0]['status'] ?? 400, ['failed' => $failed]);
            return;
        }
        ApiResponse::success(['attachments' => $saved, 'failed' => $failed]);
    } elseif ($method === 'DELETE') {
        $id = $input['attachment_id'] ?? $_POST['attachment_id'] ?? null;
        if (!is_numeric($id) || (int)$id < 1) {
            ApiResponse::error('Invalid input.', 400, ['attachment_id' => 'A positive integer is required.']);
            return;
        }
        $attachments->delete((int)$id);
        ApiResponse::success(['deleted_attachment_id' => (int)$id]);
    } else {
        ApiResponse::error('Method Not Allowed', 405);
    }
} catch (AttachmentException $e) {
    ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
}
