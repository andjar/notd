<?php

namespace App;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
// The 'Writer' logic is now handled by PatternProcessor and called from note/page update endpoints.
// (property_parser.php was removed)
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../validator_utils.php';

// Helper function to check for entity existence.
if (!function_exists('checkEntityExists')) {
    function checkEntityExists($pdo, $entityType, $entityId) {
        if ($entityType === 'note') {
            $stmt = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
        } else { // 'page'
            $stmt = $pdo->prepare("SELECT id FROM Pages WHERE id = ?");
        }
        $stmt->execute([$entityId]);
        return $stmt->fetch() !== false;
    }
}

// This script's public-facing part now only handles GET requests.
// All write operations are driven by content updates in the notes/pages endpoints.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    
    // GET /api/v1/properties.php?entity_type=note&entity_id=123
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $validationRules = [
            'entity_type' => 'required|isValidEntityType',
            'entity_id' => 'required|isPositiveInteger'
            // 'include_hidden' is the new parameter, replacing 'include_internal'
        ];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            \App\ApiResponse::error('Invalid input parameters.', 400, $errors);
            exit;
        }

        $entityType = $_GET['entity_type'];
        $entityId = (int)$_GET['entity_id'];
        // Use 'include_hidden' to control visibility of properties based on config.
        $includeHidden = filter_input(INPUT_GET, 'include_hidden', FILTER_VALIDATE_BOOLEAN);

        try {
            $pdo = get_db_connection();
            
            if (!checkEntityExists($pdo, $entityType, $entityId)) {
                \App\ApiResponse::error($entityType === 'note' ? 'Note not found' : 'Page not found', 404);
                exit;
            }

            $dataManager = new \App\DataManager($pdo);
            $properties = null;

            if ($entityType === 'note') {
                $properties = $dataManager->getNoteProperties($entityId, $includeHidden);
            } elseif ($entityType === 'page') {
                $properties = $dataManager->getPageProperties($entityId, $includeHidden);
            }

            // The DataManager now returns the properties in the correct final format
            // as specified by the API. No further standardization is needed here.
            
            \App\ApiResponse::success($properties);
            
        } catch (Exception $e) {
            \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
        }
        exit;
    }

    // POST, PUT, DELETE methods are not supported on this endpoint.
    // Write operations are now indirect, triggered by updating Note/Page content.
    \App\ApiResponse::error('Method not allowed. This is a read-only endpoint.', 405);
}