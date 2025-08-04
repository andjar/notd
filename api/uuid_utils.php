<?php

namespace App;

/**
 * UUID v7 utility functions for notd
 * 
 * UUIDv7 provides time-ordered UUIDs with microsecond precision.
 * Format: xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx
 * - First 48 bits: Unix timestamp in milliseconds
 * - 4 bits: Version (0111 for v7)
 * - 12 bits: Random data
 * - 2 bits: Variant (10)
 * - 62 bits: Random data
 */
class UuidUtils {
    
    /**
     * Generate a UUIDv7 string
     * 
     * @return string A UUIDv7 in the format xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx
     */
    public static function generateUuidV7(): string {
        // Get current time in milliseconds since Unix epoch
        $timestamp_ms = (int)(microtime(true) * 1000);
        
        // Convert timestamp to 48-bit hex (6 bytes)
        $timestamp_hex = str_pad(dechex($timestamp_ms), 12, '0', STR_PAD_LEFT);
        
        // Generate random bytes for the rest of the UUID
        $random_bytes = random_bytes(10);
        
        // Build the UUID parts according to UUIDv7 spec
        // time_hi (32 bits): first 8 hex chars of timestamp
        $time_hi = substr($timestamp_hex, 0, 8);
        
        // time_mid (16 bits): next 4 hex chars of timestamp  
        $time_mid = substr($timestamp_hex, 8, 4);
        
        // time_hi_and_version (16 bits): version (4 bits) + rand_a (12 bits)
        $rand_a = bin2hex(substr($random_bytes, 0, 2));
        $version_and_rand_a = '7' . substr($rand_a, 0, 3);
        
        // clock_seq_hi_and_reserved + clock_seq_low (16 bits): variant (2 bits) + rand_b (14 bits)
        $rand_b = ord($random_bytes[2]);
        $rand_b_hi = ($rand_b & 0x3f) | 0x80;  // Set variant bits to 10
        $clock_seq = sprintf('%02x%02x', $rand_b_hi, ord($random_bytes[3]));
        
        // node (48 bits): rand_c (48 bits) - need 12 characters from 6 bytes
        $rand_c = bin2hex(substr($random_bytes, 4, 6));
        
        // Format as standard UUID string
        return sprintf(
            '%s-%s-%s-%s-%s',
            $time_hi,
            $time_mid,
            $version_and_rand_a,
            $clock_seq,
            $rand_c
        );
    }
    
    /**
     * Validate that a string is a valid UUIDv7
     * 
     * @param string $uuid The UUID string to validate
     * @return bool True if valid UUIDv7, false otherwise
     */
    public static function isValidUuidV7(string $uuid): bool {
        // Check basic UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Extract timestamp from UUIDv7
     * 
     * @param string $uuid The UUIDv7 string
     * @return int|null Unix timestamp in milliseconds, or null if invalid
     */
    public static function extractTimestamp(string $uuid): ?int {
        if (!self::isValidUuidV7($uuid)) {
            return null;
        }
        
        // Remove hyphens and extract first 12 hex characters (48 bits)
        $hex_timestamp = substr(str_replace('-', '', $uuid), 0, 12);
        
        return hexdec($hex_timestamp);
    }
    
    /**
     * Check if a value looks like a UUIDv7 (for migration purposes)
     * 
     * @param mixed $value The value to check
     * @return bool True if it looks like a UUIDv7
     */
    public static function looksLikeUuid($value): bool {
        if (!is_string($value)) {
            return false;
        }
        
        return self::isValidUuidV7($value);
    }
}