<?php
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
                throw new RuntimeException('Failed to create upload directory: ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            error_log("Directory not writable: " . $dir);
            throw new RuntimeException('Upload directory is not writable: ' . $dir);
        }
        return $dir;
    }

    private function validateFile($file) {
        error_log("Starting file validation for: " . $file['name']);
        
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

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File exceeds maximum size limit of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB');
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

        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException('File type not allowed: ' . $mime_type);
        }

        error_log("File validation completed successfully");
        return $mime_type;
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];

        // Handle method overriding for DELETE via POST (e.g., for phpdesktop)
        if ($method === 'POST' && isset($_POST['_method']) && strtoupper($_POST['_method']) === 'DELETE') {
            $method = 'DELETE';
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
                ApiResponse::error('Method Not Allowed', 405);
        }
    }

    private function handlePostRequest() {
        error_log("=== POST METHOD PROCESSING ===");
        
        $validationRulesPOST = ['note_id' => 'required|isPositiveInteger'];
        $errorsPOST = Validator::validate($_POST, $validationRulesPOST);
        if (!empty($errorsPOST)) {
            ob_end_clean();
            ApiResponse::error('Invalid input for note ID.', 400, $errorsPOST);
            return;
        }
        $note_id = (int)$_POST['note_id'];

        if (!isset($_FILES['attachmentFile'])) {
            ob_end_clean();
            ApiResponse::error('attachmentFile is required.', 400);
            return;
        }
        $file = $_FILES['attachmentFile'];

        error_log("Upload attempt for note_id: $note_id");
        error_log("File info: " . json_encode($file));
        error_log("UPLOADS_DIR: " . UPLOADS_DIR);

        try {
            error_log("Starting database transaction");
            $this->pdo->beginTransaction();

            error_log("Verifying note exists");
            $note_stmt = $this->pdo->prepare("SELECT id, page_id FROM Notes WHERE id = ?");
            $note_stmt->execute([$note_id]);
            $note = $note_stmt->fetch();

            if (!$note) {
                error_log("Note not found for ID: $note_id");
                $this->pdo->rollBack();
                ob_end_clean();
                ApiResponse::error('Note not found', 404);
                return;
            }

            error_log("Note found, starting file validation");
            $mime_type = $this->validateFile($file);
            error_log("File validation passed, MIME type: $mime_type");

            if (!file_exists(UPLOADS_DIR)) {
                if (!mkdir(UPLOADS_DIR, 0755, true)) {
                    throw new RuntimeException('Failed to create uploads directory');
                }
            }

            $year = date('Y');
            $month = date('m');
            $upload_dir = $this->ensureUploadDirectory($year, $month);
            $unique_filename = $this->generateUniqueFilename($file['name']);
            $relative_path = $year . '/' . $month . '/' . $unique_filename;
            $full_path = $upload_dir . '/' . $unique_filename;

            error_log("Attempting to move file to: $full_path");

            if (!move_uploaded_file($file['tmp_name'], $full_path)) {
                throw new RuntimeException('Failed to move uploaded file to: ' . $full_path);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO Attachments (note_id, name, path, type, created_at) 
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([$note_id, $file['name'], $relative_path, $mime_type]);
            $attachment_id = $this->pdo->lastInsertId();

            $note_update_stmt = $this->pdo->prepare("UPDATE Notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $note_update_stmt->execute([$note_id]);

            $page_update_stmt = $this->pdo->prepare("UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $page_update_stmt->execute([$note['page_id']]);

            $attachment_stmt = $this->pdo->prepare("SELECT * FROM Attachments WHERE id = ?");
            $attachment_stmt->execute([$attachment_id]);
            $attachment = $attachment_stmt->fetch();

            $this->pdo->commit();
            ApiResponse::success($attachment, 201);
            ob_end_flush();
        } catch (RuntimeException $e) {
            if (isset($full_path) && file_exists($full_path)) {
                unlink($full_path);
            }
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ob_end_clean();
            ApiResponse::error($e->getMessage(), 400);
        } catch (PDOException $e) {
            if (isset($full_path) && file_exists($full_path)) {
                unlink($full_path);
            }
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ob_end_clean();
            ApiResponse::error('Failed to save attachment: ' . $e->getMessage(), 500);
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
                $attachment['url'] = APP_BASE_URL . 'uploads/' . $attachment['path']; 
            }
            ApiResponse::success($attachments);
            ob_end_flush();
        } catch (PDOException $e) {
            ob_end_clean();
            ApiResponse::error('Failed to fetch attachments for note_id: ' . $e->getMessage(), 500);
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
                $attachment['url'] = APP_BASE_URL . 'uploads/' . $attachment['path'];
            }
            
            ApiResponse::success([
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
            ApiResponse::error('Failed to fetch all attachments: ' . $e->getMessage(), 500);
        }
    }

    private function handleDeleteRequest() {
        $id_to_validate = $_GET['id'] ?? ($input['id'] ?? null);

        $validationRules = ['id' => 'required|isPositiveInteger'];
        $errors = Validator::validate(['id' => $id_to_validate], $validationRules); 
        if (!empty($errors)) {
            ob_end_clean();
            ApiResponse::error('Invalid attachment ID.', 400, $errors);
            return;
        }
        $attachment_id = (int)$id_to_validate;

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT a.*, n.page_id FROM Attachments a JOIN Notes n ON a.note_id = n.id WHERE a.id = ?");
            $stmt->execute([$attachment_id]);
            $attachment = $stmt->fetch();

            if (!$attachment) {
                $this->pdo->rollBack();
                ob_end_clean();
                ApiResponse::error('Attachment not found', 404);
                return;
            }

            $file_path = UPLOADS_DIR . '/' . $attachment['path'];
            if (file_exists($file_path)) {
                if (!unlink($file_path)) {
                    throw new RuntimeException('Failed to delete file from filesystem');
                }
            }

            $delete_stmt = $this->pdo->prepare("DELETE FROM Attachments WHERE id = ?");
            $delete_stmt->execute([$attachment_id]);

            $note_update_stmt = $this->pdo->prepare("UPDATE Notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $note_update_stmt->execute([$attachment['note_id']]);

            $page_update_stmt = $this->pdo->prepare("UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $page_update_stmt->execute([$attachment['page_id']]);

            $this->pdo->commit();
            ApiResponse::success(['deleted_attachment_id' => $attachment_id]);
            ob_end_flush();
        } catch (RuntimeException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ob_end_clean();
            ApiResponse::error($e->getMessage(), 500);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ob_end_clean();
            ApiResponse::error('Failed to delete attachment: ' . $e->getMessage(), 500);
        }
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$attachmentManager = new AttachmentManager($pdo);
$attachmentManager->handleRequest();
