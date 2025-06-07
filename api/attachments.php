<?php
error_log("=== ATTACHMENTS.PHP START ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

try {
    require_once __DIR__ . '/db_connect.php';
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/response_utils.php'; // Include the new response utility
    require_once __DIR__ . '/validator_utils.php'; // Include the new Validator

    error_log("Required files loaded successfully");
    error_log("UPLOADS_DIR: " . UPLOADS_DIR);

    // header('Content-Type: application/json'); // Will be handled by ApiResponse
    
    error_log("Attempting to get database connection");
    $pdo = get_db_connection();
    error_log("Database connection established");
    
    $method = $_SERVER['REQUEST_METHOD'];

    // Handle method overriding for DELETE via POST (e.g., for phpdesktop)
    if ($method === 'POST' && isset($_POST['_method']) && strtoupper($_POST['_method']) === 'DELETE') {
        $method = 'DELETE';
    }

    // Constants for file validation
    if (!defined('MAX_FILE_SIZE')) {
        define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
    }
    if (!defined('ALLOWED_MIME_TYPES')) {
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
    }

    if (!function_exists('generate_unique_filename')) {
        function generate_unique_filename($original_name) {
            $extension = pathinfo($original_name, PATHINFO_EXTENSION);
            return uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $original_name);
        }
    }

    if (!function_exists('ensure_upload_directory')) {
        function ensure_upload_directory($year, $month) {
            $dir = UPLOADS_DIR . '/' . $year . '/' . $month;
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            return $dir;
        }
    }

    if (!function_exists('validate_file')) {
        function validate_file($file) {
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

            if ($file['size'] > MAX_FILE_SIZE) {
                throw new RuntimeException('File exceeds maximum size limit of ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB');
            }

            // Try to get MIME type with better error handling
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
            
            // Fallback to checking file extension if finfo fails
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

            if (!in_array($mime_type, ALLOWED_MIME_TYPES)) {
                throw new RuntimeException('File type not allowed: ' . $mime_type);
            }

            error_log("File validation completed successfully");
            return $mime_type;
        }
    }

    if ($method === 'POST') {
        error_log("=== POST METHOD PROCESSING ===");
        
        // Validate note_id from POST data
        $validationRulesPOST = ['note_id' => 'required|isPositiveInteger'];
        $errorsPOST = Validator::validate($_POST, $validationRulesPOST);
        if (!empty($errorsPOST)) {
            ApiResponse::error('Invalid input for note ID.', 400, $errorsPOST);
            exit;
        }
        $note_id = (int)$_POST['note_id']; // Validated

        // File presence check
        if (!isset($_FILES['attachmentFile'])) {
            ApiResponse::error('attachmentFile is required.', 400);
            exit;
        }
        $file = $_FILES['attachmentFile'];

        // Debug logging
        error_log("Upload attempt for note_id: $note_id");
        error_log("File info: " . json_encode($file));
        error_log("UPLOADS_DIR: " . UPLOADS_DIR);

        try {
            error_log("Starting database transaction");
            $pdo->beginTransaction();

            error_log("Verifying note exists");
            // Verify note exists
            $note_stmt = $pdo->prepare("SELECT id, page_id FROM Notes WHERE id = ?");
            $note_stmt->execute([$note_id]);
            $note = $note_stmt->fetch();

            if (!$note) {
                error_log("Note not found for ID: $note_id");
                $pdo->rollBack();
                ApiResponse::error('Note not found', 404);
                exit;
            }

            error_log("Note found, starting file validation");
            // Validate file
            $mime_type = validate_file($file);
            error_log("File validation passed, MIME type: $mime_type");

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
            ApiResponse::success($attachment);

        } catch (RuntimeException $e) {
            if (isset($full_path) && file_exists($full_path)) {
                unlink($full_path);
            }
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            ApiResponse::error($e->getMessage(), 400);
        } catch (PDOException $e) {
            if (isset($full_path) && file_exists($full_path)) {
                unlink($full_path);
            }
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            ApiResponse::error('Failed to save attachment: ' . $e->getMessage(), 500);
        }
    } elseif ($method === 'GET') {
        if (isset($_GET['note_id'])) {
            $note_id = (int)$_GET['note_id'];
            try {
                $stmt = $pdo->prepare("SELECT id, name, path, type, size, created_at FROM Attachments WHERE note_id = ? ORDER BY created_at DESC");
                $stmt->execute([$note_id]);
                $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($attachments as &$attachment) {
                    $attachment['url'] = APP_BASE_URL . 'uploads/' . $attachment['path']; 
                }
                ApiResponse::success($attachments);
            } catch (PDOException $e) {
                ApiResponse::error('Failed to fetch attachments for note_id: ' . $e->getMessage(), 500);
            }
        } else {
            // New logic for all attachments
            $sortBy = $_GET['sort_by'] ?? 'created_at';
            $sortOrder = $_GET['sort_order'] ?? 'desc';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
            if ($page < 1) $page = 1;
            if ($perPage < 1) $perPage = 10;
            if ($perPage > 100) $perPage = 100; // Max per page

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
            $params = []; // For WHERE clauses

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
                // Fetch total count
                $countStmt = $pdo->prepare($countQuery);
                $countStmt->execute($params);
                $totalItems = (int)$countStmt->fetchColumn();

                // $sortBy is whitelisted, so direct concatenation is safe.
                // $sortOrder is also whitelisted ('asc' or 'desc').
                $baseQuery .= " ORDER BY " . $sortBy . " " . (strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC');

                $offset = ($page - 1) * $perPage;

                $baseQuery .= " LIMIT ? OFFSET ?";
                
                $mainQueryParams = $params; 
                $mainQueryParams[] = $perPage; 
                $mainQueryParams[] = $offset;  

                $stmt = $pdo->prepare($baseQuery);
                
                // Bind parameters one by one to ensure correct types for LIMIT/OFFSET
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
            } catch (PDOException $e) {
                ApiResponse::error('Failed to fetch all attachments: ' . $e->getMessage(), 500);
            }
        }
    } elseif ($method === 'DELETE') {
        // For DELETE, the 'id' might come from $_GET or from parsed input if _method override is used
        $id_to_validate = $_GET['id'] ?? ($input['id'] ?? null);

        $validationRules = ['id' => 'required|isPositiveInteger'];
        // Use a temporary array for validation as $_GET or $input might be the source
        $errors = Validator::validate(['id' => $id_to_validate], $validationRules); 
        if (!empty($errors)) {
            ApiResponse::error('Invalid attachment ID.', 400, $errors);
            exit;
        }
        $attachment_id = (int)$id_to_validate; // Validated

        try {
            $pdo->beginTransaction();

            // Get attachment info
            $stmt = $pdo->prepare("SELECT a.*, n.page_id FROM Attachments a JOIN Notes n ON a.note_id = n.id WHERE a.id = ?");
            $stmt->execute([$attachment_id]);
            $attachment = $stmt->fetch();

            if (!$attachment) {
                $pdo->rollBack();
                ApiResponse::error('Attachment not found', 404);
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
            ApiResponse::success(['deleted_attachment_id' => $attachment_id]);

        } catch (RuntimeException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            ApiResponse::error($e->getMessage(), 500);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            ApiResponse::error('Failed to delete attachment: ' . $e->getMessage(), 500);
        }
    } else {
        ApiResponse::error('Method Not Allowed', 405);
    }
} catch (Exception $e) {
    // Ensure ApiResponse is used for the final catch-all
    ApiResponse::error('An unexpected error occurred: ' . $e->getMessage(), 500);
}
