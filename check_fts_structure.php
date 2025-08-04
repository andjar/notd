<?php
try {
    $pdo = new PDO('sqlite:db/database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Checking FTS Table Structure ===\n";
    
    // Check Notes_fts table structure
    $stmt = $pdo->query('PRAGMA table_info(Notes_fts)');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Notes_fts table structure:\n";
    foreach($columns as $col) {
        echo "  {$col['name']} ({$col['type']})" . ($col['pk'] ? ' PRIMARY KEY' : '') . "\n";
    }
    
    echo "\n=== Checking Notes_fts_lookup table ===\n";
    $stmt = $pdo->query('PRAGMA table_info(Notes_fts_lookup)');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Notes_fts_lookup table structure:\n";
    foreach($columns as $col) {
        echo "  {$col['name']} ({$col['type']})" . ($col['pk'] ? ' PRIMARY KEY' : '') . "\n";
    }
    
    echo "\n=== Testing Note Creation ===\n";
    
    // Test creating a note directly
    $pageId = '01987228-35a1-7641-b23b-53b8ec6154ac'; // Use existing page ID
    $noteId = 'test-note-' . time();
    $content = 'Test note content';
    
    echo "Attempting to create note with ID: $noteId\n";
    
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
    $result = $stmt->execute([$noteId, $pageId, $content]);
    
    if ($result) {
        echo "✅ Note created successfully!\n";
        
        // Check if it was added to FTS
        $stmt = $pdo->query("SELECT COUNT(*) FROM Notes_fts_lookup WHERE uuid = '$noteId'");
        $lookupCount = $stmt->fetchColumn();
        echo "FTS lookup entries: $lookupCount\n";
        
        // Clean up
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        echo "Test note cleaned up.\n";
    } else {
        echo "❌ Failed to create note.\n";
        print_r($stmt->errorInfo());
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?> 