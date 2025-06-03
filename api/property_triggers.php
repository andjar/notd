<?php
// api/property_triggers.php

// Placeholder for a more sophisticated trigger registration if needed
// global $propertyTriggers; // This line is removed
$propertyTriggers = [
    'internal' => 'handleInternalPropertyTriggerForNote',
    'alias' => 'handleAliasPropertyTrigger',
    // Add other triggers here, e.g.
    // 'remember_me' => 'handleRememberMePropertyTrigger',
];

function handleInternalPropertyTriggerForNote($pdo, $entityType, $entityId, $propertyName, $propertyValue) {
    if ($entityType === 'note' && $propertyName === 'internal') {
        // Ensure $propertyValue is treated as a string for comparison, as it comes from DB or input
        $noteInternalValue = ($propertyValue === 'true' || $propertyValue === '1') ? 1 : 0;
        try {
            $stmtUpdateNote = $pdo->prepare("UPDATE Notes SET internal = :internalValue WHERE id = :noteId");
            $stmtUpdateNote->bindParam(':internalValue', $noteInternalValue, PDO::PARAM_INT);
            $stmtUpdateNote->bindParam(':noteId', $entityId, PDO::PARAM_INT);
            $stmtUpdateNote->execute();
            // error_log("Triggered Notes.internal update for note $entityId to $noteInternalValue based on property value: $propertyValue");
        } catch (PDOException $e) {
            // Log error, but don't let trigger failure stop main operation usually
            error_log("Error in handleInternalPropertyTriggerForNote: " . $e->getMessage());
        }
    }
}

function handleAliasPropertyTrigger($pdo, $entityType, $entityId, $propertyName, $propertyValue) {
    if ($entityType === 'page' && $propertyName === 'alias') {
        try {
            $aliasValue = trim($propertyValue);
            $aliasValue = empty($aliasValue) ? null : $aliasValue;
            
            $stmtUpdatePage = $pdo->prepare("UPDATE Pages SET alias = :aliasValue WHERE id = :pageId");
            $stmtUpdatePage->bindParam(':aliasValue', $aliasValue, PDO::PARAM_STR);
            $stmtUpdatePage->bindParam(':pageId', $entityId, PDO::PARAM_INT);
            $stmtUpdatePage->execute();
            
            // Log for debugging
            error_log("Updated Pages.alias for page $entityId to '$aliasValue' based on property value: $propertyValue");
        } catch (PDOException $e) {
            // Log error, but don't let trigger failure stop main operation usually
            error_log("Error in handleAliasPropertyTrigger: " . $e->getMessage());
        }
    }
}

// Example for a future trigger
/*
function handleRememberMePropertyTrigger($pdo, $entityType, $entityId, $propertyName, $propertyValue) {
    if ($entityType === 'note' && $propertyName === 'remember_me') {
        // Logic for remember_me
        error_log("Remember_me trigger for note $entityId with value $propertyValue");
    }
}
*/

function dispatchPropertyTriggers($pdo, $entityType, $entityId, $propertyName, $propertyValue) {
    global $propertyTriggers;
    if (isset($propertyTriggers[$propertyName])) {
        $handlerFunction = $propertyTriggers[$propertyName];
        if (function_exists($handlerFunction)) {
            call_user_func($handlerFunction, $pdo, $entityType, $entityId, $propertyName, $propertyValue);
        } else {
            error_log("Property trigger handler function not found: {$handlerFunction} for property {$propertyName}");
        }
    }
} 