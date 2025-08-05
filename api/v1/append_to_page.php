<?php

namespace App;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../validator_utils.php';
require_once __DIR__ . '/../PatternProcessor.php';
require_once __DIR__ . '/../UuidUtils.php';

use App\UuidUtils;

// New "Smart Property Indexer"
// This function is the single source of truth for processing properties from content.
if (!function_exists('_indexPropertiesFromContent')) {
    function _indexPropertiesFromContent($pdo, $entityType, $entityId, $content) {
        // For notes, check if encrypted. If so, do not process properties from content.
        if ($entityType === 'note') {
            $encryptedStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND value = 'true' LIMIT 1");
            $encryptedStmt->execute([':note_id' => $entityId]);
            if ($encryptedStmt->fetch()) {
                return; // Note is encrypted, do not parse/modify properties from its content.
            }
        }

        // Instantiate the pattern processor with the existing PDO connection to avoid database locks
        $patternProcessor = new \App\PatternProcessor($pdo);

        // Process the content to extract properties and potentially modified content
        // Pass $pdo in context for handlers that might need it directly.
        $processedData = $patternProcessor->processContent($content, $entityType, $entityId, ['pdo' => $pdo]);
        
        $parsedProperties = $processedData['properties'];

        // Save all extracted/generated properties using the processor's save method
        // This method should handle deleting old 'replaceable' properties and inserting/updating new ones.
        // It will also handle property triggers.
        if (!empty($parsedProperties)) {
            $patternProcessor->saveProperties($parsedProperties, $entityType, $entityId);
        }
        
        // Update the note's 'internal' flag based on the final set of properties applied.
        $hasInternalTrue = false;
        if (!empty($parsedProperties)) {
            foreach ($parsedProperties as $prop) {
                if (isset($prop['name']) && strtolower($prop['name']) === 'internal' && 
                    isset($prop['value']) && strtolower((string)$prop['value']) === 'true') {
                    $hasInternalTrue = true;
                    break;
                }
            }
        }

        if ($entityType === 'note') {
             try {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
                $updateStmt->execute([$hasInternalTrue ? 1 : 0, $entityId]);
            } catch (PDOException $e) {
                // Silently handle internal flag update errors
            }
        }
    }
}


header('Content-Type: application/json');
$pdo = get_db_connection();
$dataManager = new \App\DataManager($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method !== 'POST') {
    \App\ApiResponse::error('Method not allowed. Only POST is supported.', 405);
    exit;
}

// 1. Validate input
if (!isset($input['page_name']) || !is_string($input['page_name']) || empty(trim($input['page_name']))) {
    \App\ApiResponse::error('page_name is required and must be a non-empty string.', 400);
    exit;
}
$page_name = \Validator::sanitizeString($input['page_name']);

try {
    $pdo->beginTransaction();

    // 2. Page Handling (Retrieve or Create)
    $page_data = $dataManager->getPageDetailsByName($page_name, true);

    if ($page_data) {
        $page_id = $page_data['id'];
    } else {
        $page_id = \App\UuidUtils::generateUuidV7();
        $insertStmt = $pdo->prepare("INSERT INTO Pages (id, name, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $insertStmt->execute([$page_id, $page_name]);
    }
    
    // 3. Note Handling - Convert to batch operations format
    if (!isset($input['notes'])) {
        throw new Exception('notes field is required.');
    }
    $notes_input = is_string($input['notes']) ? [['content' => $input['notes']]] : $input['notes'];
    if (!is_array($notes_input)) {
        throw new Exception('notes field must be a string or an array of note objects.');
    }

    // Convert notes to batch operations format
    $batch_operations = [];
    foreach ($notes_input as $note_item) {
        if (!is_array($note_item) || !isset($note_item['content']) || !is_string($note_item['content'])) {
            throw new Exception("Each note item must be an object with a 'content' string.");
        }

        $batch_operations[] = [
            'type' => 'create',
            'payload' => [
                'page_id' => $page_id,
                'content' => $note_item['content'],
                'parent_note_id' => $note_item['parent_note_id'] ?? null,
                'order_index' => $note_item['order_index'] ?? 0,
                'collapsed' => $note_item['collapsed'] ?? 0
            ]
        ];
    }

    // Include batch operations utility file
    require_once __DIR__ . '/batch_operations.php';
    
    // Process batch operations directly
    if (!empty($batch_operations)) {
        try {
            $appended_notes_results = _handleBatchOperations($pdo, $dataManager, $batch_operations, false);

            // If we get here, the batch operations were successful (or at least did not throw an exception)
            // The _handleBatchOperations function now returns results directly or throws an exception.
            // Individual operations within the batch might have failed, this is reflected in $appended_notes_results.

            $pdo->commit();

            // Re-fetch page_data to include any new properties
            $final_page_data = $dataManager->getPageDetailsById($page_id, true);

            \App\ApiResponse::success([
                'message' => ($page_data ? 'Page retrieved' : 'Page created') . ' and notes appended successfully.',
                'page' => $final_page_data,
                'appended_notes' => $appended_notes_results
            ]);
        } catch (Exception $e) {
            // This catch block now primarily catches exceptions from _handleBatchOperations
            // or issues with committing the transaction.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // It's good to preserve the original error message if possible, or a more specific one.
            throw new Exception('Failed during batch operations or commit: ' . $e->getMessage());
        }
    } else {
        throw new Exception('No valid notes to append.');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    \App\ApiResponse::error('An error occurred: ' . $e->getMessage(), 500);
}