<?php

require_once 'response_utils.php';

class DataManager {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Formats properties into the structure: ['propertyName' => [{value: 'v1', colon_count: 2}, {value: 'v2', colon_count: 2}]]
    // The $includeInternal parameter is no longer used here for filtering, as visibility is now based on colon_count 
    // and handled by the caller using PROPERTY_BEHAVIORS_BY_COLON_COUNT.
    // This function will format all properties passed to it.
    private function _formatProperties($propertiesResult) {
        $formattedProperties = [];
        if (empty($propertiesResult)) {
            return $formattedProperties;
        }

        foreach ($propertiesResult as $prop) {
            // Ensure colon_count is an integer.
            $colon_count = isset($prop['colon_count']) ? (int)$prop['colon_count'] : 2; // Default to 2 if not set
            
            if (!isset($formattedProperties[$prop['name']])) {
                $formattedProperties[$prop['name']] = [];
            }
            $formattedProperties[$prop['name']][] = [
                'value' => $prop['value'], 
                'colon_count' => $colon_count
            ];
        }
        return $formattedProperties;
    }

    public function getPageProperties($pageId, $includeInternal = false /* Kept for signature compatibility, but not used for filtering here */) {
        // error_log("[DEBUG] getPageProperties called for pageId: " . $pageId);
        
        // Fetches all active properties for the page, caller will filter based on colon_count behavior.
        $sql = "SELECT name, value, colon_count FROM Properties WHERE page_id = :pageId AND note_id IS NULL AND active = 1 ORDER BY name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $propertiesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // error_log("[DEBUG] Raw properties from database for page $pageId: " . json_encode($propertiesResult));
        
        // The $includeInternal parameter is not strictly used by _formatProperties for filtering anymore.
        // Visibility decisions are pushed to the API endpoint layer (e.g., notes.php) based on colon_count.
        $formattedProperties = $this->_formatProperties($propertiesResult);
        // error_log("[DEBUG] Formatted properties for page $pageId: " . json_encode($formattedProperties));
        
        return $formattedProperties;
    }

    public function getNoteProperties($noteId, $includeInternal = false /* Kept for signature compatibility */) {
        // Fetches all active properties for the note.
        $sql = "SELECT name, value, colon_count FROM Properties WHERE note_id = :noteId AND active = 1 ORDER BY name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':noteId', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $propertiesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->_formatProperties($propertiesResult);
    }

    public function getNoteById($noteId, $includeInternal = false) {
        $sql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes WHERE Notes.id = :id";
        if (!$includeInternal) {
            // This condition needs to be carefully considered.
            // If a note itself is marked internal, should it be excluded here?
            // The original api/notes.php had "AND internal = 0" for the note itself.
            // Let's assume for now that `includeInternal` refers to properties primarily,
            // but if a note itself is internal, it might be filtered by the calling context or a direct SQL clause.
            // For now, let's stick to the provided signature and focus on properties' internal status.
            // The `internal` column on the Notes table itself will be returned as is.
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':id', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($note) {
            // This filter is for the Note's own 'internal' flag, not its properties.
            // This specific behavior is retained as it's outside the direct scope of property formatting.
            if (!$includeInternal && isset($note['internal']) && $note['internal'] == 1) {
                return null; 
            }
            // Pass $includeInternal to getNoteProperties for its signature, though it won't filter by internal flag there anymore.
            // The actual filtering based on colon_count behavior will happen in the API endpoint.
            $note['properties'] = $this->getNoteProperties($noteId, $includeInternal);
        }
        
        return $note;
    }

    public function getNotesByPageId($pageId, $includeInternal = false) {
        $notesSql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes WHERE Notes.page_id = :pageId";
        // Filter notes based on their own 'internal' flag
        if (!$includeInternal) {
            $notesSql .= " AND Notes.internal = 0"; 
        }
        $notesSql .= " ORDER BY Notes.order_index ASC"; 
        
        // error_log("[DEBUG] getNotesByPageId SQL: " . $notesSql);
        
        $stmt = $this->pdo->prepare($notesSql);
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($notes)) {
            return [];
        }

        $noteIds = array_column($notes, 'id');
        
        // Fetch all active properties for these notes in a single query
        $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
        // Fetches all active properties; formatting and visibility decisions based on colon_count are for the caller.
        $propSql = "SELECT note_id, name, value, colon_count FROM Properties WHERE note_id IN ($placeholders) AND active = 1 ORDER BY name";

        $stmtProps = $this->pdo->prepare($propSql);
        $stmtProps->execute($noteIds);
        $allPropertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);
        
        // Group properties by note_id
        $propertiesByNoteIdRaw = [];
        foreach ($allPropertiesResult as $prop) {
            $propertiesByNoteIdRaw[$prop['note_id']][] = $prop;
        }
        
        // Embed properties into each note
        foreach ($notes as &$note) {
            $currentNoteProperties = $propertiesByNoteIdRaw[$note['id']] ?? [];
            // $includeInternal is passed but _formatProperties now primarily uses colon_count
            $note['properties'] = $this->_formatProperties($currentNoteProperties);
        }
        unset($note); // Break the reference
        
        return $notes;
    }

    public function getPageDetailsById($pageId, $includeInternal = false) {
        // error_log("[DEBUG] getPageDetailsById called for pageId: " . $pageId);
        
        $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE id = :id");
        $stmt->bindParam(':id', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        // error_log("[DEBUG] Raw page data from database: " . json_encode($page));

        if ($page) {
            // error_log("[DEBUG] Getting page properties");
            // $includeInternal is passed but not used for filtering in getPageProperties anymore
            $page['properties'] = $this->getPageProperties($pageId, $includeInternal);
            // error_log("[DEBUG] Page properties: " . json_encode($page['properties']));
        }
        return $page;
    }

    public function getPageWithNotes($pageId, $includeInternal = false) {
        // error_log("[DEBUG] getPageWithNotes called for pageId: " . $pageId);
        
        $pageDetails = $this->getPageDetailsById($pageId, $includeInternal);
        // error_log("[DEBUG] getPageDetailsById result: " . json_encode($pageDetails));

        if (!$pageDetails) {
            // error_log("[DEBUG] Page not found by getPageDetailsById for ID: " . $pageId . ". Returning null from getPageWithNotes.");
            return null; 
        }
        
        $notes = $this->getNotesByPageId($pageId, $includeInternal);
        // error_log("[DEBUG] getNotesByPageId result: " . json_encode($notes));
        
        return [
            'page' => $pageDetails,
            'notes' => $notes
        ];
    }

    public function getPropertiesForNoteIds(array $noteIds, $includeInternal = false /* Kept for signature compatibility */) {
        if (empty($noteIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
        // Fetches all active properties; formatting and visibility decisions based on colon_count are for the caller.
        $propSql = "SELECT note_id, name, value, colon_count FROM Properties WHERE note_id IN ($placeholders) AND active = 1 ORDER BY note_id, name";

        $stmtProps = $this->pdo->prepare($propSql);
        $stmtProps->execute($noteIds);
        $allPropertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

        $propertiesByNoteIdRaw = [];
        foreach ($allPropertiesResult as $prop) {
            $propertiesByNoteIdRaw[$prop['note_id']][] = $prop;
        }

        $formattedPropertiesByNoteId = [];
        foreach ($propertiesByNoteIdRaw as $noteId => $props) {
            // $includeInternal is passed but _formatProperties now primarily uses colon_count
            $formattedPropertiesByNoteId[$noteId] = $this->_formatProperties($props);
        }
        
        return $formattedPropertiesByNoteId;
    }
}