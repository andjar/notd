<?php
require_once '../config.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify required parameters
if (!isset($_POST['note_id']) || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

$noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT);
if (!$noteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid note ID']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'File upload failed: ' . $file['error']]);
    exit;
}

try {
    $pdo = get_db_connection();
    
    // Verify note exists
    $stmt = $pdo->prepare('SELECT id FROM Notes WHERE id = ?');
    $stmt->execute([$noteId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Note not found']);
        exit;
    }
    
    // Create uploads directory if it doesn't exist
    $uploadDir = UPLOADS_DIR . '/' . date('Y/m');
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Get file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    
    // Save to database
    $stmt = $pdo->prepare('
        INSERT INTO Attachments (note_id, name, path, type)
        VALUES (?, ?, ?, ?)
    ');
    
    $relativePath = str_replace(UPLOADS_DIR . '/', '', $filepath);
    $stmt->execute([$noteId, $file['name'], $relativePath, $mimeType]);
    
    $attachmentId = $pdo->lastInsertId();
    
    echo json_encode([
        'id' => $attachmentId,
        'name' => $file['name'],
        'path' => $relativePath,
        'type' => $mimeType
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    
    // Clean up file if it was uploaded but database insert failed
    if (isset($filepath) && file_exists($filepath)) {
        unlink($filepath);
    }
} 