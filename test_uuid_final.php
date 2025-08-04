<?php
// Final UUID Test - Comprehensive verification
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Final UUID Test - Comprehensive Verification</h1>";

try {
    require_once 'api/uuid_utils.php';
    
    echo "<p style='color: green;'>✅ UuidUtils loaded</p>";
    
    // Test the problematic UUID
    $problematicUuid = "01987222-664e-7c9d-8f54-09825ded04";
    
    echo "<h2>Analyzing Previous Problematic UUID:</h2>";
    echo "<p><strong>UUID:</strong> $problematicUuid</p>";
    echo "<p><strong>Length:</strong> " . strlen($problematicUuid) . " characters</p>";
    echo "<p><strong>Expected length:</strong> 36 characters (with hyphens)</p>";
    echo "<p><strong>Missing characters:</strong> " . (36 - strlen($problematicUuid)) . "</p>";
    
    // Check if it looks like a UUID
    echo "<p><strong>looksLikeUuid:</strong> " . (\App\UuidUtils::looksLikeUuid($problematicUuid) ? 'YES' : 'NO') . "</p>";
    
    // Generate multiple UUIDs to verify the fix
    echo "<h2>Testing Fixed UUID Generation:</h2>";
    
    $allValid = true;
    for ($i = 0; $i < 10; $i++) {
        $uuid = \App\UuidUtils::generateUuidV7();
        $length = strlen($uuid);
        $isValid = \App\UuidUtils::looksLikeUuid($uuid);
        $isValidV7 = \App\UuidUtils::isValidUuidV7($uuid);
        
        echo "<p><strong>UUID $i:</strong> $uuid (length: $length)</p>";
        echo "<p>looksLikeUuid: " . ($isValid ? 'YES' : 'NO') . "</p>";
        echo "<p>isValidUuidV7: " . ($isValidV7 ? 'YES' : 'NO') . "</p>";
        
        if ($length !== 36 || !$isValid || !$isValidV7) {
            $allValid = false;
            echo "<p style='color: red;'>❌ UUID $i is invalid!</p>";
        } else {
            echo "<p style='color: green;'>✅ UUID $i is valid</p>";
        }
        echo "<hr>";
    }
    
    // Test database integration
    echo "<h2>Testing Database Integration:</h2>";
    
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    // Test note creation with new UUID
    $testUuid = \App\UuidUtils::generateUuidV7();
    echo "<p><strong>Test UUID for database:</strong> $testUuid</p>";
    echo "<p><strong>UUID Length:</strong> " . strlen($testUuid) . " characters</p>";
    echo "<p><strong>looksLikeUuid:</strong> " . (\App\UuidUtils::looksLikeUuid($testUuid) ? 'YES' : 'NO') . "</p>";
    
    // Get a page ID
    $stmt = $pdo->query("SELECT id FROM Pages LIMIT 1");
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($page) {
        $pageId = $page['id'];
        echo "<p><strong>Page ID:</strong> $pageId</p>";
        
        try {
            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
            $result = $stmt->execute([$testUuid, $pageId, 'Test content for UUID verification']);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Note creation successful with fixed UUID</p>";
                
                // Clean up
                $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                $stmt->execute([$testUuid]);
                echo "<p style='color: green;'>✅ Test data cleaned up</p>";
                
                echo "<h2>🎯 FINAL RESULTS:</h2>";
                if ($allValid) {
                    echo "<p style='color: green; font-weight: bold;'>✅ All UUIDs generated correctly!</p>";
                    echo "<p style='color: green;'>✅ UUID length is consistently 36 characters</p>";
                    echo "<p style='color: green;'>✅ UUID validation works correctly</p>";
                    echo "<p style='color: green;'>✅ Database integration works</p>";
                    echo "<p style='color: green; font-weight: bold;'>🎉 UUID migration is now complete and working!</p>";
                } else {
                    echo "<p style='color: red; font-weight: bold;'>❌ Some UUIDs are still invalid!</p>";
                }
                
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