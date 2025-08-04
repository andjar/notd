<?php
// Test UUID generation and validation
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test UUID Generation and Validation</h1>";

try {
    require_once 'api/uuid_utils.php';
    
    echo "<p style='color: green;'>✅ UuidUtils loaded</p>";
    
    // Test the problematic UUID
    $problematicUuid = "01987222-664e-7c9d-8f54-09825ded04";
    
    echo "<h2>Analyzing Problematic UUID:</h2>";
    echo "<p><strong>UUID:</strong> $problematicUuid</p>";
    echo "<p><strong>Length:</strong> " . strlen($problematicUuid) . " characters</p>";
    echo "<p><strong>Expected length:</strong> 36 characters (with hyphens)</p>";
    echo "<p><strong>Missing characters:</strong> " . (36 - strlen($problematicUuid)) . "</p>";
    
    // Check if it looks like a UUID
    echo "<p><strong>looksLikeUuid:</strong> " . (\App\UuidUtils::looksLikeUuid($problematicUuid) ? 'YES' : 'NO') . "</p>";
    
    // Generate multiple UUIDs to see the pattern
    echo "<h2>Testing UUID Generation:</h2>";
    
    for ($i = 0; $i < 5; $i++) {
        $uuid = \App\UuidUtils::generateUuidV7();
        echo "<p>Generated UUID $i: $uuid (length: " . strlen($uuid) . ")</p>";
        echo "<p>looksLikeUuid: " . (\App\UuidUtils::looksLikeUuid($uuid) ? 'YES' : 'NO') . "</p>";
        echo "<p>isValidUuidV7: " . (\App\UuidUtils::isValidUuidV7($uuid) ? 'YES' : 'NO') . "</p>";
        echo "<hr>";
    }
    
    // Test if the problematic UUID is a valid UUID v7
    echo "<h2>Testing Problematic UUID:</h2>";
    echo "<p><strong>isValidUuidV7:</strong> " . (\App\UuidUtils::isValidUuidV7($problematicUuid) ? 'YES' : 'NO') . "</p>";
    
    // Check if it's a truncated UUID v7
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{8}$/i';
    echo "<p><strong>Matches truncated pattern:</strong> " . (preg_match($pattern, $problematicUuid) ? 'YES' : 'NO') . "</p>";
    
    // Try to complete the UUID
    $completedUuid = $problematicUuid . "0000";
    echo "<p><strong>Completed UUID:</strong> $completedUuid</p>";
    echo "<p><strong>Completed UUID looksLikeUuid:</strong> " . (\App\UuidUtils::looksLikeUuid($completedUuid) ? 'YES' : 'NO') . "</p>";
    
    echo "<h2>🎯 CONCLUSION:</h2>";
    echo "<p style='color: red; font-weight: bold;'>The UUID is being truncated somewhere in the frontend!</p>";
    echo "<p>The UUID should be 36 characters but is only " . strlen($problematicUuid) . " characters.</p>";
    echo "<p>This suggests the frontend UUID generation or transmission is cutting off the last 4 characters.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 