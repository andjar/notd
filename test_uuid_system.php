<?php
require_once 'config.php';
require_once 'api/uuid_utils.php';

use App\UuidUtils;

echo "=== UUID System Test ===\n";

// Test 1: Generate UUIDs
echo "1. Testing UUID generation...\n";
$uuid1 = UuidUtils::generateUuidV7();
$uuid2 = UuidUtils::generateUuidV7();
echo "   Generated UUID 1: $uuid1\n";
echo "   Generated UUID 2: $uuid2\n";
echo "   UUIDs are different: " . ($uuid1 !== $uuid2 ? "PASS" : "FAIL") . "\n";

// Test 2: Validate UUIDs
echo "\n2. Testing UUID validation...\n";
echo "   UUID 1 is valid: " . (UuidUtils::isValidUuidV7($uuid1) ? "PASS" : "FAIL") . "\n";
echo "   UUID 2 is valid: " . (UuidUtils::isValidUuidV7($uuid2) ? "PASS" : "FAIL") . "\n";
echo "   Invalid UUID test: " . (!UuidUtils::isValidUuidV7("invalid-uuid") ? "PASS" : "FAIL") . "\n";

// Test 3: Check UUID format
echo "\n3. Testing UUID format...\n";
echo "   UUID 1 format: " . (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid1) ? "PASS" : "FAIL") . "\n";
echo "   UUID 2 format: " . (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid2) ? "PASS" : "FAIL") . "\n";

// Test 4: Extract timestamp
echo "\n4. Testing timestamp extraction...\n";
$timestamp1 = UuidUtils::extractTimestamp($uuid1);
$timestamp2 = UuidUtils::extractTimestamp($uuid2);
echo "   UUID 1 timestamp: $timestamp1\n";
echo "   UUID 2 timestamp: $timestamp2\n";
echo "   Timestamps are different: " . ($timestamp1 !== $timestamp2 ? "PASS" : "FAIL") . "\n";

// Test 5: Check looksLikeUuid function
echo "\n5. Testing looksLikeUuid function...\n";
echo "   UUID 1 looks like UUID: " . (UuidUtils::looksLikeUuid($uuid1) ? "PASS" : "FAIL") . "\n";
echo "   UUID 2 looks like UUID: " . (UuidUtils::looksLikeUuid($uuid2) ? "PASS" : "FAIL") . "\n";
echo "   Invalid string looks like UUID: " . (!UuidUtils::looksLikeUuid("not-a-uuid") ? "PASS" : "FAIL") . "\n";

echo "\n=== Test Complete ===\n";
echo "If all tests show PASS, the UUID system is working correctly.\n";
echo "You may need to recreate your database with the new schema for full functionality.\n"; 