<?php
header('Content-Type: application/json');

// Retrieve and sanitize the search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Return empty array if query length is less than 2
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Database connection
$db_path = __DIR__ . '/../db/notes.db';
$db = new SQLite3($db_path);

if (!$db) {
    // Optionally log error here, similar to search.php
    // error_log("Failed to connect to database: " . $db->lastErrorMsg());
    echo json_encode([]);
    exit;
}

// Prepare the SQL query
$stmt = $db->prepare("SELECT id, title FROM pages WHERE LOWER(title) LIKE LOWER(:query) ORDER BY title ASC LIMIT 10");
if (!$stmt) {
    // Optionally log error here
    // error_log("Failed to prepare statement: " . $db->lastErrorMsg());
    $db->close();
    echo json_encode([]);
    exit;
}

// Bind the query parameter
$search_term = '%' . $query . '%';
$stmt->bindValue(':query', $search_term, SQLITE3_TEXT);

// Execute the query
$result = $stmt->execute();

$pages = [];
if ($result) {
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $pages[] = $row;
    }
} else {
    // Optionally log error here
    // error_log("Failed to execute statement: " . $db->lastErrorMsg());
}

// Close statement and database connection
$stmt->close();
$db->close();

// Encode and output the results
echo json_encode($pages);
?>
