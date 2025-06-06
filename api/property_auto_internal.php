<?php
// Helper functions for automatic property internal status based on definitions

require_once 'db_connect.php';

/**
 * Check if a property should be internal based on PropertyDefinitions
 * @param PDO $pdo Database connection
 * @param string $propertyName Property name to check
 * @return int|null Returns 1 if should be internal, 0 if should be public, null if no definition exists
 */
function getPropertyInternalStatusFromDefinition($pdo, $propertyName) {
    static $definitionCache = [];
    static $tableExists = null;
    
    error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Getting internal status from definition for: {$propertyName}");
    
    // Check if PropertyDefinitions table exists (cache the result)
    if ($tableExists === null) {
        try {
            error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Checking if PropertyDefinitions table exists");
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='PropertyDefinitions'");
            $tableExists = (bool)$stmt->fetch();
            error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] PropertyDefinitions table exists: " . ($tableExists ? 'yes' : 'no'));
        } catch (Exception $e) {
            error_log("[PROPERTY_AUTO_INTERNAL_ERROR] Error checking PropertyDefinitions table: " . $e->getMessage());
            $tableExists = false;
        }
    }
    
    // If table doesn't exist, return null (no definition available)
    if (!$tableExists) {
        error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] PropertyDefinitions table does not exist, returning null");
        return null;
    }
    
    // Use cache to avoid repeated database queries
    if (!isset($definitionCache[$propertyName])) {
        try {
            error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Querying PropertyDefinitions for: {$propertyName}");
            $stmt = $pdo->prepare("SELECT internal FROM PropertyDefinitions WHERE name = ?");
            $stmt->execute([$propertyName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $definitionCache[$propertyName] = $result ? (int)$result['internal'] : null;
            error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Definition found: " . ($definitionCache[$propertyName] === null ? 'no' : 'yes'));
        } catch (Exception $e) {
            error_log("[PROPERTY_AUTO_INTERNAL_ERROR] Error querying PropertyDefinitions: " . $e->getMessage());
            // If query fails, cache null result
            $definitionCache[$propertyName] = null;
        }
    } else {
        error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Using cached definition for: {$propertyName}");
    }
    
    return $definitionCache[$propertyName];
}

/**
 * Determine if a property should be internal based on its name and any explicit setting
 * @param PDO $pdo Database connection
 * @param string $propertyName The name of the property
 * @param bool|null $explicitInternal Explicit internal setting (if provided)
 * @return bool Whether the property should be internal
 */
function determinePropertyInternalStatus($pdo, $propertyName, $explicitInternal = null) {
    error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Determining internal status for property: {$propertyName}");
    error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Explicit internal value: " . ($explicitInternal === null ? 'null' : ($explicitInternal ? 'true' : 'false')));
    
    // If an explicit internal value is provided, use it
    if ($explicitInternal !== null) {
        error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Using explicit internal value: " . ($explicitInternal ? 'true' : 'false'));
        return $explicitInternal;
    }
    
    // Otherwise, check the property definitions
    $internalStatus = getPropertyInternalStatusFromDefinition($pdo, $propertyName);
    error_log("[PROPERTY_AUTO_INTERNAL_DEBUG] Internal status from definition: " . ($internalStatus !== null ? $internalStatus : 'null'));
    
    // If no definition is found, default to non-internal (0).
    return $internalStatus === null ? 0 : (int)$internalStatus;
}

// Removed applyPropertyDefinitionToProperty function (now redundant)

// Removed autoSetPropertyInternalStatus function (now redundant) 