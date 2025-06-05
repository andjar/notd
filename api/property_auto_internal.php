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
    
    // Check if PropertyDefinitions table exists (cache the result)
    if ($tableExists === null) {
        try {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='PropertyDefinitions'");
            $tableExists = (bool)$stmt->fetch();
        } catch (Exception $e) {
            $tableExists = false;
        }
    }
    
    // If table doesn't exist, return null (no definition available)
    if (!$tableExists) {
        return null;
    }
    
    // Use cache to avoid repeated database queries
    if (!isset($definitionCache[$propertyName])) {
        try {
            $stmt = $pdo->prepare("SELECT internal FROM PropertyDefinitions WHERE name = ?");
            $stmt->execute([$propertyName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $definitionCache[$propertyName] = $result ? (int)$result['internal'] : null;
        } catch (Exception $e) {
            // If query fails, cache null result
            $definitionCache[$propertyName] = null;
        }
    }
    
    return $definitionCache[$propertyName];
}

/**
 * Set property internal status based on definitions when creating/updating properties
 * This should be called before inserting or updating a property
 * @param PDO $pdo Database connection
 * @param string $propertyName Property name
 * @param int|null $explicitInternal Explicitly set internal status (overrides definitions)
 * @return int Internal status to use (0 or 1)
 */
function determinePropertyInternalStatus($pdo, $propertyName, $explicitInternal = null) {
    // If explicitly set, use that value
    if ($explicitInternal !== null) {
        return (int)$explicitInternal;
    }
    
    // Check property definitions
    $definedInternal = getPropertyInternalStatusFromDefinition($pdo, $propertyName);
    if ($definedInternal !== null) {
        return $definedInternal;
    }
    
    // Default to public (not internal)
    return 0;
}

// Removed applyPropertyDefinitionToProperty function (now redundant)

// Removed autoSetPropertyInternalStatus function (now redundant) 