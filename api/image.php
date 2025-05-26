<?php
header('Content-Type: application/json');

// Set error handling
error_reporting(E_ERROR);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No image provided']);
    exit;
}

$file = $_FILES['image'];
$noteId = $_POST['note_id'] ?? null;

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '.' . $extension;
$filepath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save image']);
    exit;
}

// If note_id is provided, create an attachment record
$db = null; // Initialize $db to null
if ($noteId) {
    try {
        $db = new SQLite3(__DIR__ . '/../db/notes.db'); // Use absolute path
        if (!$db) {
            // This specific check might be redundant if SQLite3 constructor throws an exception on failure,
            // but it's a safeguard. The main catch block will handle exceptions.
            throw new Exception('Failed to connect to database in image.php');
        }
        $db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)
        
        // Enable foreign key constraints for this connection (optional but good practice)
        if (!$db->exec('PRAGMA foreign_keys = ON;')) {
            // error_log("Notice: Attempted to enable foreign_keys for image.php. Check SQLite logs if issues persist.");
        }

        $stmt = $db->prepare('INSERT INTO attachments (note_id, filename, original_name, file_path, mime_type, size) VALUES (:note_id, :filename, :original_name, :file_path, :mime_type, :size)');
        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $db->lastErrorMsg());
        }
        
        $stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT); // The new unique filename
        $stmt->bindValue(':original_name', $file['name'], SQLITE3_TEXT); // The original uploaded filename
        $stmt->bindValue(':file_path', 'uploads/' . $filename, SQLITE3_TEXT); // Relative path to the file
        $stmt->bindValue(':mime_type', $file['type'], SQLITE3_TEXT);
        $stmt->bindValue(':size', $file['size'], SQLITE3_INTEGER);

        if (!$stmt->execute()) {
            throw new Exception('Failed to execute statement: ' . $db->lastErrorMsg());
        }
        
        echo json_encode([
            'success' => true,
            'filename' => $filename, // unique filename
            'original_name' => $file['name'],
            'attachment_id' => $db->lastInsertRowID()
        ]);
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        error_log("Database error in image.php: " . $e->getMessage());
        echo json_encode(['error' => 'Database operation failed: ' . $e->getMessage()]);
    } finally {
        if ($db) {
            $db->close();
        }
    }
} else {
    // If no note_id, just return the success of upload without DB interaction for attachment
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'original_name' => $file['name']
    ]);
} 