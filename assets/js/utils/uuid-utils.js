/**
 * UUID v7 utility functions for notd frontend
 * 
 * UUIDv7 provides time-ordered UUIDs with microsecond precision.
 * Format: xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx
 * - First 48 bits: Unix timestamp in milliseconds
 * - 4 bits: Version (0111 for v7)
 * - 12 bits: Random data
 * - 2 bits: Variant (10)
 * - 62 bits: Random data
 */

/**
 * Generate a UUIDv7 string
 * 
 * @returns {string} A UUIDv7 in the format xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx
 */
export function generateUuidV7() {
    // Get current time in milliseconds since Unix epoch
    const timestampMs = Date.now();
    
    // Convert timestamp to 48-bit hex (6 bytes)
    const timestampHex = timestampMs.toString(16).padStart(12, '0');
    
    // Generate random bytes for the rest of the UUID
    const randomBytes = new Uint8Array(10);
    crypto.getRandomValues(randomBytes);
    const randomHex = Array.from(randomBytes, b => b.toString(16).padStart(2, '0')).join('');
    
    // Build the UUID parts according to UUIDv7 spec
    // time_hi (32 bits): first 8 hex chars of timestamp
    const timeHi = timestampHex.slice(0, 8);
    
    // time_mid (16 bits): next 4 hex chars of timestamp  
    const timeMid = timestampHex.slice(8, 12);
    
    // time_hi_and_version (16 bits): version (4 bits) + rand_a (12 bits)
    const versionAndRandA = '7' + randomHex.slice(0, 3);
    
    // clock_seq_hi_and_reserved + clock_seq_low (16 bits): variant (2 bits) + rand_b (14 bits)
    const randBHi = (randomBytes[3] & 0x3f) | 0x80;  // Set variant bits to 10
    const clockSeq = randBHi.toString(16).padStart(2, '0') + randomHex.slice(8, 10);
    
    // node (48 bits): rand_c (48 bits)
    const node = randomHex.slice(10, 22);
    
    // Format as standard UUID string
    return `${timeHi}-${timeMid}-${versionAndRandA}-${clockSeq}-${node}`;
}

/**
 * Validate that a string is a valid UUIDv7
 * 
 * @param {string} uuid - The UUID string to validate
 * @returns {boolean} True if valid UUIDv7, false otherwise
 */
export function isValidUuidV7(uuid) {
    // Check basic UUID format with version 7
    return /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(uuid);
}

/**
 * Extract timestamp from UUIDv7
 * 
 * @param {string} uuid - The UUIDv7 string
 * @returns {number|null} Unix timestamp in milliseconds, or null if invalid
 */
export function extractTimestamp(uuid) {
    if (!isValidUuidV7(uuid)) {
        return null;
    }
    
    // Remove hyphens and extract first 12 hex characters (48 bits)
    const hexTimestamp = uuid.replace(/-/g, '').slice(0, 12);
    
    return parseInt(hexTimestamp, 16);
}

/**
 * Check if a value looks like a UUID (for migration purposes)
 * 
 * @param {any} value - The value to check
 * @returns {boolean} True if it looks like a UUID
 */
export function looksLikeUuid(value) {
    return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

/**
 * Check if a value looks like a temporary ID (for cleanup purposes)
 * 
 * @param {any} value - The value to check
 * @returns {boolean} True if it looks like a temporary ID
 */
export function looksLikeTempId(value) {
    return typeof value === 'string' && value.startsWith('temp-');
}