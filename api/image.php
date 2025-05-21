<?php
header('Content-Type: application/json');

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
if ($noteId) {
    require_once 'db.php';
    
    $stmt = $db->prepare('INSERT INTO attachments (note_id, filename, original_name) VALUES (?, ?, ?)');
    $stmt->execute([$noteId, $filename, $file['name']]);
    
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'original_name' => $file['name'],
        'attachment_id' => $db->lastInsertId()
    ]);
} else {
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'original_name' => $file['name']
    ]);
} 