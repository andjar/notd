<?php
// Debug UUID issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug UUID Issue</h1>";

try {
    require_once 'api/uuid_utils.php';
    
    echo "<p style='color: green;'>✅ UuidUtils loaded</p>";
    
    // Test the problematic UUID
    $problematicUuid = "0198721f-2e2f-70a2-a913-4992b11681";
    
    echo "<h2>Analyzing Problematic UUID:</h2>";
    echo "<p><strong>UUID:</strong> $problematicUuid</p>";
    echo "<p><strong>Length:</strong> " . strlen($problematicUuid) . " characters</p>";
    echo "<p><strong>Expected length:</strong> 36 characters (with hyphens)</p>";
    echo "<p><strong>Missing characters:</strong> " . (36 - strlen($problematicUuid)) . "</p>";
    
    // Check if it looks like a UUID
    echo "<p><strong>looksLikeUuid:</strong> " . (\App\UuidUtils::looksLikeUuid($problematicUuid) ? 'YES' : 'NO') . "</p>";
    
    // Generate a proper UUID for comparison
    $properUuid = \App\UuidUtils::generateUuidV7();
    echo "<p><strong>Proper UUID:</strong> $properUuid</p>";
    echo "<p><strong>Proper UUID length:</strong> " . strlen($properUuid) . " characters</p>";
    echo "<p><strong>Proper UUID looksLikeUuid:</strong> " . (\App\UuidUtils::looksLikeUuid($properUuid) ? 'YES' : 'NO') . "</p>";
    
    // Check if the problematic UUID is being truncated somewhere
    echo "<h2>Testing UUID Generation:</h2>";
    
    for ($i = 0; $i < 5; $i++) {
        $uuid = \App\UuidUtils::generateUuidV7();
        echo "<p>Generated UUID $i: $uuid (length: " . strlen($uuid) . ")</p>";
        echo "<p>looksLikeUuid: " . (\App\UuidUtils::looksLikeUuid($uuid) ? 'YES' : 'NO') . "</p>";
    }
    
    // Check if there's a pattern in the problematic UUID
    echo "<h2>Analyzing UUID Pattern:</h2>";
    echo "<p>The problematic UUID appears to be truncated. Let's check if this is a frontend issue.</p>";
    
    // Test what happens if we try to validate the truncated UUID
    echo "<p><strong>Hypothesis:</strong> The frontend might be truncating UUIDs or there's an issue in the UUID generation.</p>";
    
    // Check if the UUID is being generated correctly in the backend
    echo "<h2>Backend UUID Generation Test:</h2>";
    
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    // Test note creation with proper UUID
    $testUuid = \App\UuidUtils::generateUuidV7();
    echo "<p><strong>Test UUID for database:</strong> $testUuid</p>";
    
    // Get a page ID
    $stmt = $pdo->query("SELECT id FROM Pages LIMIT 1");
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($page) {
        $pageId = $page['id'];
        echo "<p><strong>Page ID:</strong> $pageId</p>";
        
        try {
            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
            $result = $stmt->execute([$testUuid, $pageId, 'Test content']);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Note creation successful with proper UUID</p>";
                
                // Clean up
                $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                $stmt->execute([$testUuid]);
                echo "<p style='color: green;'>✅ Test data cleaned up</p>";
                
                echo "<h2>🎯 CONCLUSION:</h2>";
                echo "<p style='color: red; font-weight: bold;'>The issue is that the frontend is sending truncated UUIDs!</p>";
                echo "<p>The backend UUID generation works correctly, but the frontend is somehow truncating the UUIDs before sending them to the backend.</p>";
                
            } else {
                echo "<p style='color: red;'>❌ Note creation failed</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ No pages found to test with</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 