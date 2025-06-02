<?php
require_once 'db_connect.php';
require_once '../config.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

// Handle method overriding for DELETE via POST (e.g., for phpdesktop)
if ($method === 'POST' && isset($_POST['_method']) && strtoupper($_POST['_method']) === 'DELETE') {
    $method = 'DELETE';
}

// Constants for file validation
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_MIME_TYPES', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'text/plain',
    'text/markdown',
    'text/csv',
    'application/json'
]);

function generate_unique_filename($original_name) {
    $extension = pathinfo($original_name, PATHINFO_EXTENSION);
    return uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $original_name);
}

function ensure_upload_directory($year, $month) {
    $dir = UPLOADS_DIR . '/' . $year . '/' . $month;
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function validate_file($file) {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file parameter');
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('File exceeds maximum size limit');
        case UPLOAD_ERR_PARTIAL:
            throw new RuntimeException('File was only partially uploaded');
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('No file was uploaded');
        case UPLOAD_ERR_NO_TMP_DIR:
            throw new RuntimeException('Missing temporary folder');
        case UPLOAD_ERR_CANT_WRITE:
            throw new RuntimeException('Failed to write file to disk');
        case UPLOAD_ERR_EXTENSION:
            throw new RuntimeException('File upload stopped by extension');
        default:
            throw new RuntimeException('Unknown upload error');
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File exceeds maximum size limit of ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);

    if (!in_array($mime_type, ALLOWED_MIME_TYPES)) {
        throw new RuntimeException('File type not allowed');
    }

    return $mime_type;
}

if ($method === 'POST') {
    if (!isset($_POST['note_id']) || !isset($_FILES['attachmentFile'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'note_id and attachmentFile are required']);
        exit;
    }

    $note_id = (int)$_POST['note_id'];
    $file = $_FILES['attachmentFile'];

    // Debug logging
    error_log("Upload attempt for note_id: $note_id");
    error_log("File info: " . json_encode($file));
    error_log("UPLOADS_DIR: " . UPLOADS_DIR);

    try {
        $pdo->beginTransaction();

        // Verify note exists
        $note_stmt = $pdo->prepare("SELECT id, page_id FROM Notes WHERE id = ?");
        $note_stmt->execute([$note_id]);
        $note = $note_stmt->fetch();

        if (!$note) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Note not found']);
            exit;
        }

        // Validate file
        $mime_type = validate_file($file);

        // Ensure uploads directory exists
        if (!file_exists(UPLOADS_DIR)) {
            if (!mkdir(UPLOADS_DIR, 0755, true)) {
                throw new RuntimeException('Failed to create uploads directory');
            }
        }

        // Generate unique filename and path
        $year = date('Y');
        $month = date('m');
        $upload_dir = ensure_upload_directory($year, $month);
        $unique_filename = generate_unique_filename($file['name']);
        $relative_path = $year . '/' . $month . '/' . $unique_filename;
        $full_path = $upload_dir . '/' . $unique_filename;

        error_log("Attempting to move file to: $full_path");

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $full_path)) {
            throw new RuntimeException('Failed to move uploaded file to: ' . $full_path);
        }

        // Insert into database
        $stmt = $pdo->prepare(
            "INSERT INTO Attachments (note_id, name, path, type, created_at) 
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$note_id, $file['name'], $relative_path, $mime_type]);
        $attachment_id = $pdo->lastInsertId();

        // Update note and page timestamps
        $note_update_stmt = $pdo->prepare("UPDATE Notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $note_update_stmt->execute([$note_id]);

        $page_update_stmt = $pdo->prepare("UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $page_update_stmt->execute([$note['page_id']]);

        // Fetch the created attachment
        $attachment_stmt = $pdo->prepare("SELECT * FROM Attachments WHERE id = ?");
        $attachment_stmt->execute([$attachment_id]);
        $attachment = $attachment_stmt->fetch();

        $pdo->commit();
        echo json_encode(['success' => true, 'data' => $attachment]);

    } catch (RuntimeException $e) {
        if (isset($full_path) && file_exists($full_path)) {
            unlink($full_path);
        }
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (PDOException $e) {
        if (isset($full_path) && file_exists($full_path)) {
            unlink($full_path);
        }
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save attachment: ' . $e->getMessage()]);
    }
} elseif ($method === 'GET') {
    if (!isset($_GET['note_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'note_id is required for fetching attachments']);
        exit;
    }
    $note_id = (int)$_GET['note_id'];

    try {
        $stmt = $pdo->prepare("SELECT id, name, path, type, created_at FROM Attachments WHERE note_id = ? ORDER BY created_at DESC");
        $stmt->execute([$note_id]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add full URL to path for client consumption if needed, or handle on client
        foreach ($attachments as &$attachment) {
            // Assuming UPLOADS_DIR is web-accessible as /uploads/
            $attachment['url'] = APP_BASE_URL . 'uploads/' . $attachment['path']; 
        }

        echo json_encode(['success' => true, 'data' => $attachments]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to fetch attachments: ' . $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Attachment ID is required']);
        exit;
    }

    $attachment_id = (int)$_GET['id'];

    try {
        $pdo->beginTransaction();

        // Get attachment info
        $stmt = $pdo->prepare("SELECT a.*, n.page_id FROM Attachments a JOIN Notes n ON a.note_id = n.id WHERE a.id = ?");
        $stmt->execute([$attachment_id]);
        $attachment = $stmt->fetch();

        if (!$attachment) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Attachment not found']);
            exit;
        }

        // Delete file from filesystem
        $file_path = UPLOADS_DIR . '/' . $attachment['path'];
        if (file_exists($file_path)) {
            if (!unlink($file_path)) {
                throw new RuntimeException('Failed to delete file from filesystem');
            }
        }

        // Delete from database
        $delete_stmt = $pdo->prepare("DELETE FROM Attachments WHERE id = ?");
        $delete_stmt->execute([$attachment_id]);

        // Update note and page timestamps
        $note_update_stmt = $pdo->prepare("UPDATE Notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $note_update_stmt->execute([$attachment['note_id']]);

        $page_update_stmt = $pdo->prepare("UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $page_update_stmt->execute([$attachment['page_id']]);

        $pdo->commit();
        echo json_encode(['success' => true, 'data' => ['deleted_attachment_id' => $attachment_id]]);

    } catch (RuntimeException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete attachment: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
}
