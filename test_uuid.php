<?php
/**
 * Test UUID v7 implementation
 */

require_once __DIR__ . '/api/uuid_utils.php';

use App\UuidUtils;

echo "=== UUID v7 Generation Test ===\n\n";

// Test 1: Generate multiple UUIDs and verify format
echo "1. Testing UUID generation and validation:\n";
for ($i = 0; $i < 5; $i++) {
    $uuid = UuidUtils::generateUuidV7();
    $isValid = UuidUtils::isValidUuidV7($uuid);
    $timestamp = UuidUtils::extractTimestamp($uuid);
    
    echo "   UUID: $uuid\n";
    echo "   Valid: " . ($isValid ? 'YES' : 'NO') . "\n";
    echo "   Timestamp: $timestamp (" . date('Y-m-d H:i:s', $timestamp / 1000) . ")\n";
    echo "   ---\n";
    
    if (!$isValid) {
        echo "❌ Generated UUID is not valid!\n";
        exit(1);
    }
    
    // Small delay to ensure different timestamps
    usleep(1000);
}

// Test 2: Test invalid UUIDs
echo "\n2. Testing invalid UUID detection:\n";
$invalidUuids = [
    '123e4567-e89b-12d3-a456-426614174000', // Wrong version
    '123e4567-e89b-62d3-a456-426614174000', // Wrong version
    'not-a-uuid',
    '',
    '123e4567-e89b-7123-x456-426614174000', // Invalid character
];

foreach ($invalidUuids as $invalid) {
    $isValid = UuidUtils::isValidUuidV7($invalid);
    echo "   '$invalid' -> " . ($isValid ? 'VALID (ERROR!)' : 'INVALID (correct)') . "\n";
    
    if ($isValid) {
        echo "❌ Invalid UUID was marked as valid!\n";
        exit(1);
    }
}

// Test 3: Test ordering (UUIDs generated later should have higher timestamps)
echo "\n3. Testing timestamp ordering:\n";
$uuid1 = UuidUtils::generateUuidV7();
usleep(5000); // 5ms delay
$uuid2 = UuidUtils::generateUuidV7();

$ts1 = UuidUtils::extractTimestamp($uuid1);
$ts2 = UuidUtils::extractTimestamp($uuid2);

echo "   UUID1: $uuid1 (ts: $ts1)\n";
echo "   UUID2: $uuid2 (ts: $ts2)\n";
echo "   Ordered correctly: " . ($ts2 > $ts1 ? 'YES' : 'NO') . "\n";

if ($ts2 <= $ts1) {
    echo "❌ UUIDs are not properly time-ordered!\n";
    exit(1);
}

// Test 4: Test utility functions
echo "\n4. Testing utility functions:\n";
$testUuid = UuidUtils::generateUuidV7();
echo "   looksLikeUuid('$testUuid'): " . (UuidUtils::looksLikeUuid($testUuid) ? 'YES' : 'NO') . "\n";
echo "   looksLikeUuid('123'): " . (UuidUtils::looksLikeUuid('123') ? 'YES' : 'NO') . "\n";
echo "   looksLikeUuid(123): " . (UuidUtils::looksLikeUuid(123) ? 'YES' : 'NO') . "\n";

echo "\n✅ All UUID tests passed!\n";
echo "=== End UUID Test ===\n";