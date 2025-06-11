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
        $input = [];

        // For POST requests, determine if it's an upload or a delete action based on Content-Type and payload
        if ($method === 'POST') {
            if (isset($_SERVER['CONTENT_TYPE']) && strpos(strtolower($_SERVER['CONTENT_TYPE']), 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true);
                if (isset($input['action']) && $input['action'] === 'delete') {
                    $this->handleDeleteRequest($input); // Pass parsed JSON input
                    return;
                }
                 // If action is not 'delete', or not present, it might be an upload attempt with wrong Content-Type,
                 // or another JSON based POST action if we add more later.
                 // For now, if it's not 'delete', let it fall through to be handled as an upload,
                 // which will likely fail if 'multipart/form-data' is not used.
                 // Or, explicitly error out if JSON content type but no valid action:
                 // else {
                 //    ApiResponse::error('Invalid action for POST with application/json', 400);
                 //    return;
                 // }
            }
            // Default POST action is upload (handlePostRequest will deal with multipart/form-data)
            $this->handlePostRequest();
            return;
        }

        // Handle method overriding for DELETE via POST (e.g., for phpdesktop using _method)
        // This is less common if we strictly use action: "delete" for POST.
        // Consider if this specific override is still needed or if action based is sufficient.
        // For now, keeping it but it means handleDeleteRequest needs to check $_POST as well.
        if ($method === 'POST' && isset($_POST['_method']) && strtoupper($_POST['_method']) === 'DELETE') {
             // This case is tricky. If _method is used, the body might be form-urlencoded.
             // The spec pushes for JSON body for actions.
             // For simplicity, this example will assume if _method=DELETE, id is in $_POST or $_GET.
            $this->handleDeleteRequest($_REQUEST); // $_REQUEST contains $_GET and $_POST
            return;
        }
        
        switch ($method) {
            // POST is handled above
            case 'GET':
                $this->handleGetRequest();
                break;
            case 'DELETE': // Direct DELETE request
                $this->handleDeleteRequest($_GET); // Pass $_GET as source for ID
                break;
            default:
                ApiResponse::error('Method Not Allowed', 405);
        }
    }

    private function handlePostRequest() { // This is now explicitly for UPLOAD
        error_log("=== POST METHOD PROCESSING (UPLOAD) ===");

        // Check if it's a multipart/form-data request, essential for file uploads
        if (!isset($_SERVER['CONTENT_TYPE']) || strpos(strtolower($_SERVER['CONTENT_TYPE']), 'multipart/form-data') === false) {
            // If 'action: "delete"' was intended but sent with wrong content type, this will catch it too.
            // However, the primary check for 'action: "delete"' with JSON is now in handleRequest.
            ob_end_clean();
            ApiResponse::error('Invalid request for upload. Content-Type must be multipart/form-data.', 415);
            return;
        }
        
        // Standard $_POST validation for note_id if it's part of multipart form
        $validationRulesPOST = ['note_id' => 'required|isPositiveInteger'];
        $errorsPOST = Validator::validate($_POST, $validationRulesPOST);
        if (!empty($errorsPOST)) {
            ob_end_clean();
            ApiResponse::error('Invalid input for note ID with upload.', 400, $errorsPOST);
            return;
        }
        $note_id = (int)$_POST['note_id'];

        if (!isset($_FILES['attachmentFile'])) {
            ob_end_clean();
            ApiResponse::error('attachmentFile is required for upload.', 400);
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
        if (isset($_GET['id']) && !isset($_GET['note_id'])) {
            $this->handleGetById();
        } elseif (isset($_GET['note_id'])) {
            $this->handleGetByNoteId();
        } else {
            $this->handleGetAll();
        }
    }

    private function handleGetById() {
        if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT) || (int)$_GET['id'] <= 0) {
            ob_end_clean(); // Clean buffer before error response
            ApiResponse::error("Valid attachment ID is required.", 400);
            return;
        }
        $id = (int)$_GET['id'];

        try {
            // Fetch attachment details from the database
            // Using column aliases to match the desired output structure ('name', 'path', 'type', 'size')
            $stmt = $this->pdo->prepare(
                "SELECT id, note_id, name, path, type, size, created_at 
                 FROM Attachments 
                 WHERE id = ?"
            );
            $stmt->execute([$id]);
            $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($attachment) {
                // Construct the full URL for file access
                $attachment['url'] = APP_BASE_URL . 'uploads/' . $attachment['path'];
                
                // Ensure numeric fields are actual numbers if necessary (PDO usually handles this)
                $attachment['id'] = (int)$attachment['id'];
                $attachment['note_id'] = (int)$attachment['note_id'];
                $attachment['size'] = (int)$attachment['size'];

                ApiResponse::success($attachment);
                ob_end_flush();
            } else {
                ob_end_clean();
                ApiResponse::error("Attachment not found.", 404);
            }
        } catch (PDOException $e) {
            ob_end_clean();
            // Log error: error_log("Database error in handleGetById: " . $e->getMessage());
            ApiResponse::error("Database error while fetching attachment.", 500);
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

    private function handleDeleteRequest($dataSource = []) { // Accepts a data source for the ID
        error_log("=== DELETE METHOD PROCESSING ===");
        $attachment_id = null;

        if (isset($dataSource['action']) && $dataSource['action'] === 'delete') { // From POST JSON body
            $attachment_id = $dataSource['id'] ?? null;
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') { // From DELETE request (likely ID in query string)
            $attachment_id = $dataSource['id'] ?? null; // dataSource here would be $_GET
        } else if (isset($dataSource['_method']) && strtoupper($dataSource['_method']) === 'DELETE') { // From POST _method override
             $attachment_id = $dataSource['id'] ?? null; // dataSource here would be $_REQUEST
        }
        else {
            // Fallback or error if ID source is unclear / this method is called incorrectly
            // This specific check might be redundant if handleRequest routes correctly.
            $attachment_id = $dataSource['id'] ?? $_GET['id'] ?? null;
        }
        
        $validationRules = ['id' => 'required|isPositiveInteger'];
        $errors = Validator::validate(['id' => $attachment_id], $validationRules); 
        if (!empty($errors)) {
            ob_end_clean();
            ApiResponse::error('A valid attachment ID is required for deletion.', 400, $errors);
            return;
        }
        $attachment_id = (int)$attachment_id;

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
