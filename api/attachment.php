<?php
header('Content-Type: application/json');
error_reporting(E_ERROR);
ini_set('display_errors', 0);

try {
    $db = new SQLite3('../db/notes.db');
    if (!$db) {
        // This specific check might be redundant if SQLite3 constructor throws an exception on failure,
        // but it's a good early check. The main catch block will handle exceptions.
        throw new Exception('Failed to connect to database in attachment.php');
    }
    $db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)
    
    // Enable foreign key constraints (optional but good practice)
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        // error_log("Notice: Attempted to enable foreign_keys for attachment.php. Check SQLite logs if issues persist.");
    }

    $uploadsDir = __DIR__ . '/../uploads';

    if (!file_exists($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    function handleUpload() {
        global $db, $uploadsDir;
        
        if (!isset($_FILES['file']) || !isset($_POST['note_id'])) {
            return ['error' => 'Missing file or note_id'];
        }

        $file = $_FILES['file'];
        $noteId = $_POST['note_id'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'File upload failed'];
        }

        // Create dated subfolder
        $dateFolder = date('Y-m-d');
        $targetDir = $uploadsDir . '/' . $dateFolder;
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $filename = uniqid() . '_' . basename($file['name']);
        $filepath = $targetDir . '/' . $filename;
        $relativePath = $dateFolder . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['error' => 'Failed to move uploaded file'];
        }

        $stmt = $db->prepare('
            INSERT INTO attachments (note_id, filename, original_name, file_path, mime_type)
            VALUES (:note_id, :filename, :original_name, :file_path, :mime_type)
        ');

        $stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
        $stmt->bindValue(':filename', $relativePath, SQLITE3_TEXT);
        $stmt->bindValue(':original_name', $file['name'], SQLITE3_TEXT);
        $stmt->bindValue(':file_path', $filepath, SQLITE3_TEXT);
        $stmt->bindValue(':mime_type', $file['type'], SQLITE3_TEXT);

        if (!$stmt->execute()) {
            unlink($filepath);
            return ['error' => 'Failed to save attachment record'];
        }

        return [
            'success' => true,
            'id' => $db->lastInsertRowID(),
            'filename' => $relativePath,
            'original_name' => $file['name']
        ];
    }

    function handleDelete($id) {
        global $db, $uploadsDir;

        $stmt = $db->prepare('SELECT filename, file_path FROM attachments WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $attachment = $result->fetchArray(SQLITE3_ASSOC);

        if (!$attachment) {
            return ['error' => 'Attachment not found'];
        }

        if (file_exists($attachment['file_path'])) {
            unlink($attachment['file_path']);
        }

        $stmt = $db->prepare('DELETE FROM attachments WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);

        if (!$stmt->execute()) {
            return ['error' => 'Failed to delete attachment record'];
        }

        return ['success' => true];
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'POST':
            if (isset($_FILES['file'])) {
                echo json_encode(handleUpload());
            } else {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (!$data || !isset($data['action'])) {
                    echo json_encode(['error' => 'Invalid request data']);
                    break;
                }
                
                if ($data['action'] === 'delete') {
                    $attachmentIdToDelete = $data['id'] ?? null; // Get id from JSON data
                    if (!$attachmentIdToDelete) {
                        echo json_encode(['error' => 'Attachment ID required in JSON payload']);
                        break;
                    }
                    echo json_encode(handleDelete($attachmentIdToDelete)); // Pass the correct ID
                } else {
                    echo json_encode(['error' => 'Invalid action']);
                }
            }
            break;

        default:
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 