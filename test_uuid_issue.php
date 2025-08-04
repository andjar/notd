<?php

// Test UUID generation without any includes
echo "<h1>Testing UUID Generation</h1>";

// Test basic UUID generation
try {
    // Include uuid_utils.php directly
    require_once __DIR__ . '/api/uuid_utils.php';
    
    echo "<p>UUID utils loaded successfully</p>";
    
    // Test UUID generation
    $uuid = UuidUtils::generateUuidV7();
    echo "<p>Generated UUID: $uuid</p>";
    
    // Test UUID validation
    $isValid = UuidUtils::isValidUuidV7($uuid);
    echo "<p>UUID is valid: " . ($isValid ? 'YES' : 'NO') . "</p>";
    
    echo "<p>UUID generation test successful!</p>";
    
} catch (Exception $e) {
    echo "<p>UUID generation failed: " . $e->getMessage() . "</p>";
}

echo "<p>Test complete.</p>"; 