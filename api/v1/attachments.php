<?php

namespace App;

use PDO;

error_log("=== ATTACHMENTS.PHP START ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

ob_start(); // Start output buffering

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../validator_utils.php';

class AttachmentManager {
    private $pdo;
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json'
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function generateUniqueFilename($original_name) {
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        return uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $original_name);
    }

    private function ensureUploadDirectory($year, $month) {
        $dir = UPLOADS_DIR . '/' . $year . '/' . $month;
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create directory: " . $dir);
                throw new \RuntimeException('Failed to create upload directory: ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            error_log("Directory not writable: " . $dir);
            throw new \RuntimeException('Upload directory is not writable: ' . $dir);
        }
        return $dir;
    }

    private function validateFile($file) {
        error_log("Starting file validation for: " . $file['name']);
        
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new \RuntimeException('Invalid file parameter');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new \RuntimeException('File exceeds maximum size limit');
            case UPLOAD_ERR_PARTIAL:
                throw new \RuntimeException('File was only partially uploaded');
            case UPLOAD_ERR_NO_FILE:
                throw new \RuntimeException('No file was uploaded');
            case UPLOAD_ERR_NO_TMP_DIR:
                throw new \RuntimeException('Missing temporary folder');
            case UPLOAD_ERR_CANT_WRITE:
                throw new \RuntimeException('Failed to write file to disk');
            case UPLOAD_ERR_EXTENSION:
                throw new \RuntimeException('File upload stopped by extension');
            default:
                throw new \RuntimeException('Unknown upload error');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('File exceeds maximum size limit of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB');
        }

        $mime_type = null;
        
        if (class_exists('finfo')) {
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->file($file['tmp_name']);
                error_log("MIME type detected via finfo: " . $mime_type);
            } catch (Exception $e) {
                error_log("finfo failed: " . $e->getMessage());
                $mime_type = null;
            }
        }
        
        if (!$mime_type) {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime_map = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'md' => 'text/markdown',
                'csv' => 'text/csv',
                'json' => 'application/json'
            ];
            
            $mime_type = $mime_map[$extension] ?? 'application/octet-stream';
            error_log("MIME type detected via extension fallback: " . $mime_type);
        }
        // Allow .excalidraw files as application/json
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension === 'excalidraw') {
            $mime_type = 'application/json';
        }

        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            throw new \RuntimeException('File type not allowed: ' . $mime_type);
        }

        error_log("File validation completed successfully: name={$file['name']}, mime_type={$mime_type}");
        return $mime_type;
    }

public function handleRequest() {
    $method = $_SERVER['REQUEST_METHOD'];

    // Handle method overriding for all request types
    if ($method === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = strpos($contentType, 'application/json') !== false;
        
        // Check for JSON-based method override
        if ($isJson) {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: [];
            
            // Route JSON delete requests to handleDeleteRequest
            if (isset($input['action']) && $input['action'] === 'delete') {
                $this->handleDeleteRequest();
                return;
            }
        }
        // Check for form-based method override
        else if (isset($_POST['_method']) && strtoupper($_POST['_method']) === 'DELETE') {
            $method = 'DELETE';
        }
    }

    switch ($method) {
        case 'POST':
            $this->handlePostRequest();
            break;
        case 'GET':
            $this->handleGetRequest();
            break;
        case 'DELETE':
            $this->handleDeleteRequest();
            break;
        default:
            \App\ApiResponse::error('Method Not Allowed', 405);
    }
}

    private function handlePostRequest() {
        error_log("=== POST METHOD PROCESSING ===");
        
        $validationRulesPOST = ['note_id' => 'required|isPositiveInteger'];
        $errorsPOST = Validator::validate($_POST, $validationRulesPOST);
        if (!empty($errorsPOST)) {
            ob_end_clean();
            \App\ApiResponse::error('Invalid input for note ID.', 400, $errorsPOST);
            return;
        }
        $note_id = (int)$_POST['note_id'];

        if (!isset($_FILES['attachmentFile'])) {
            ob_end_clean();
            \App\ApiResponse::error('attachmentFile is required.', 400);
            return;
        }
        $files = $_FILES['attachmentFile'];
        $savedAttachments = [];
        if (is_array($files['name'])) {
            // Multiple files
            for ($i = 0; $i < count($files['name']); $i++) {
                $file = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
                $result = $this->processSingleAttachment($note_id, $file);
                if ($result !== null) {
                    $savedAttachments[] = $result;
                }
            }
        } else {
            // Single file
            $result = $this->processSingleAttachment($note_id, $files);
            if ($result !== null) {
                $savedAttachments[] = $result;
            }
        }
        ob_end_clean();
        \App\ApiResponse::success(['attachments' => $savedAttachments]);
    }

    // Add a helper to process a single file
    private function processSingleAttachment($note_id, $file) {
        try {
            error_log("Starting database transaction");
            $this->pdo->beginTransaction();

            error_log("Verifying note exists");
            $note_stmt = $this->pdo->prepare("SELECT id, page_id FROM Notes WHERE id = ?");
            $note_stmt->execute([$note_id]);
            $note = $note_stmt->fetch();
            if (!$note) {
                $this->pdo->rollBack();
                throw new \RuntimeException('Note not found.');
            }

            $year = date('Y');
            $month = date('m');
            $uploadDir = $this->ensureUploadDirectory($year, $month);
            $original_name = $file['name'];
            $unique_name = $this->generateUniqueFilename($original_name);
            $target_path = $uploadDir . '/' . $unique_name;

            $mime_type = $this->validateFile($file);

            error_log("About to move_uploaded_file: name={$file['name']}, tmp_name={$file['tmp_name']}");
            if (!file_exists($file['tmp_name'])) {
                error_log('Temp file does not exist: ' . $file['tmp_name'] . ' for ' . $file['name']);
            }

            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                // Fallback: try copy if move_uploaded_file fails but file exists
                if (file_exists($file['tmp_name'])) {
                    error_log('move_uploaded_file failed, trying copy() for ' . $file['name']);
                    if (!copy($file['tmp_name'], $target_path)) {
                        $this->pdo->rollBack();
                        throw new \RuntimeException('Failed to move or copy uploaded file.');
                    }
                    // Optionally, unlink the temp file after copy
                    @unlink($file['tmp_name']);
                } else {
                    $this->pdo->rollBack();
                    throw new \RuntimeException('Failed to move uploaded file.');
                }
            }

            $insert_stmt = $this->pdo->prepare("INSERT INTO Attachments (note_id, name, path, type, size, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
            $insert_stmt->execute([
                $note_id,
                $original_name,
                $year . '/' . $month . '/' . $unique_name,
                $mime_type,
                $file['size']
            ]);

            $attachment_id = $this->pdo->lastInsertId();
            $this->pdo->commit();

            return [
                'id' => $attachment_id,
                'note_id' => $note_id,
                'name' => $original_name,
                'path' => $year . '/' . $month . '/' . $unique_name,
                'type' => $mime_type,
                'size' => $file['size'],
                'created_at' => date('c'),
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Error processing attachment: ' . $e->getMessage());
            return null;
        }
    }

    private function handleGetRequest() {
        if (isset($_GET['note_id'])) {
            $this->handleGetByNoteId();
        } else {
            $this->handleGetAll();
        }
    }

    private function handleGetByNoteId() {
        $note_id = (int)$_GET['note_id'];
        try {
            $stmt = $this->pdo->prepare("SELECT id, name, path, type, size, created_at FROM Attachments WHERE note_id = ? ORDER BY created_at DESC");
            $stmt->execute([$note_id]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($attachments as &$attachment) {
                $attachment['url'] = '/uploads/' . $attachment['path']; 
            }
            \App\ApiResponse::success($attachments);
            ob_end_flush();
        } catch (PDOException $e) {
            ob_end_clean();
            \App\ApiResponse::error('Failed to fetch attachments for note_id: ' . $e->getMessage(), 500);
        }
    }

    private function handleGetAll() {
        $sortBy = $_GET['sort_by'] ?? 'created_at';
        $sortOrder = $_GET['sort_order'] ?? 'desc';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
        if ($page < 1) $page = 1;
        if ($perPage < 1) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        $allowedSortColumns = ['id', 'name', 'path', 'type', 'size', 'created_at'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'created_at';
        }
        if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }

        $baseQuery = "SELECT id, name, path, type, size, created_at FROM Attachments";
        $countQuery = "SELECT COUNT(*) FROM Attachments";
        $whereClauses = [];
        $params = [];

        if (isset($_GET['filter_by_name']) && !empty($_GET['filter_by_name'])) {
            $whereClauses[] = "name LIKE ?";
            $params[] = '%' . $_GET['filter_by_name'] . '%';
        }
        if (isset($_GET['filter_by_type']) && !empty($_GET['filter_by_type'])) {
            $whereClauses[] = "type = ?";
            $params[] = $_GET['filter_by_type'];
        }

        if (!empty($whereClauses)) {
            $filterQueryPart = " WHERE " . implode(" AND ", $whereClauses);
            $baseQuery .= $filterQueryPart;
            $countQuery .= $filterQueryPart;
        }

        try {
            $countStmt = $this->pdo->prepare($countQuery);
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetchColumn();

            $baseQuery .= " ORDER BY " . $sortBy . " " . (strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC');

            $offset = ($page - 1) * $perPage;

            $baseQuery .= " LIMIT ? OFFSET ?";
            
            $mainQueryParams = $params; 
            $mainQueryParams[] = $perPage; 
            $mainQueryParams[] = $offset;  

            $stmt = $this->pdo->prepare($baseQuery);
            
            $paramIndex = 1;
            foreach ($params as $paramValue) {
                $stmt->bindValue($paramIndex++, $paramValue);
            }
            $stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($attachments as &$attachment) {
                $attachment['url'] = '/uploads/' . $attachment['path'];
            }
            
            \App\ApiResponse::success([
                'data' => $attachments,
                'pagination' => [
                    'total_items' => $totalItems,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => (int)ceil($totalItems / $perPage)
                ]
            ]);
            ob_end_flush();
        } catch (PDOException $e) {
            ob_end_clean();
            \App\ApiResponse::error('Failed to fetch all attachments: ' . $e->getMessage(), 500);
        }
    }


    

private function handleDeleteRequest() {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];

    error_log('Raw input: ' . $raw);
    error_log('Decoded input: ' . print_r($input, true));

    $attachment_id = $input['attachment_id'] ?? null;
    $note_id = $input['note_id'] ?? null;

    // Validate both IDs properly:
    $validationRules = [
        'attachment_id' => 'required|isPositiveInteger',
        'note_id' => 'required|isPositiveInteger',
    ];
    $errors = Validator::validate([
        'attachment_id' => $attachment_id,
        'note_id' => $note_id,
    ], $validationRules);

    if (!empty($errors)) {
        ob_end_clean();
        \App\ApiResponse::error('Invalid input.', 400, $errors);
        return;
    }

    $attachment_id = (int)$attachment_id;
    $note_id = (int)$note_id;

    try {
        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare("SELECT a.*, n.page_id FROM Attachments a JOIN Notes n ON a.note_id = n.id WHERE a.id = ?");
        $stmt->execute([$attachment_id]);
        $attachment = $stmt->fetch();

        if (!$attachment) {
            $this->pdo->rollBack();
            ob_end_clean();
            \App\ApiResponse::error('Attachment not found', 404);
            return;
        }

        $file_path = UPLOADS_DIR . '/' . $attachment['path'];
        if (file_exists($file_path)) {
            if (!unlink($file_path)) {
                throw new \RuntimeException('Failed to delete file from filesystem');
            }
        }

        $delete_stmt = $this->pdo->prepare("DELETE FROM Attachments WHERE id = ?");
        $delete_stmt->execute([$attachment_id]);

        $note_update_stmt = $this->pdo->prepare("UPDATE Notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $note_update_stmt->execute([$attachment['note_id']]);

        $page_update_stmt = $this->pdo->prepare("UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $page_update_stmt->execute([$attachment['page_id']]);

        $this->pdo->commit();

        \App\ApiResponse::success(['deleted_attachment_id' => $attachment_id]);
        ob_end_flush();

    } catch (RuntimeException | PDOException $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        ob_end_clean();
        \App\ApiResponse::error('Failed to delete attachment: ' . $e->getMessage(), 500);
    }
}


}

// Initialize and handle the request
$pdo = get_db_connection();
$attachmentManager = new \App\AttachmentManager($pdo);
$attachmentManager->handleRequest();
