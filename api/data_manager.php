<?php

require_once 'response_utils.php';

class DataManager {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Formats properties according to the API specification:
    // {"property_name": [{"value": "...", "internal": 0/1}, ...]}
    private function _formatProperties($propertiesResult, $includeInternal = false) {
        $formattedProperties = [];
        if (empty($propertiesResult)) {
            return $formattedProperties;
        }

        foreach ($propertiesResult as $prop) {
            $isInternal = (int)$prop['internal'];
            
            // If we are not including internal properties, and this one is internal, skip it.
            if (!$includeInternal && $isInternal == 1) {
                continue;
            }

            $propName = $prop['name'];
            $propValue = $prop['value'];

            if (!isset($formattedProperties[$propName])) {
                $formattedProperties[$propName] = [];
            }
            
            $formattedProperties[$propName][] = [
                'value' => $propValue,
                'internal' => $isInternal
            ];
        }
        return $formattedProperties;
    }

    public function getPageProperties($pageId, $includeInternal = false) {
        error_log("[DEBUG] getPageProperties called for pageId: " . $pageId . ", includeInternal: " . ($includeInternal ? 'true' : 'false'));
        
        $sql = "SELECT name, value, internal FROM Properties WHERE page_id = :pageId AND note_id IS NULL";
        if (!$includeInternal) {
            $sql .= " AND internal = 0";
        }
        $sql .= " ORDER BY name"; 
        error_log("[DEBUG] SQL query: " . $sql);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $propertiesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("[DEBUG] Raw properties from database: " . json_encode($propertiesResult));
        
        $formattedProperties = $this->_formatProperties($propertiesResult, $includeInternal);
        error_log("[DEBUG] Formatted properties: " . json_encode($formattedProperties));
        
        return $formattedProperties;
    }

    public function getNoteProperties($noteId, $includeInternal = false) {
        $sql = "SELECT name, value, internal FROM Properties WHERE note_id = :noteId";
        if (!$includeInternal) {
            $sql .= " AND internal = 0";
        }
        $sql .= " ORDER BY name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':noteId', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $propertiesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->_formatProperties($propertiesResult, $includeInternal);
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
            if (!$includeInternal && $note['internal'] == 1) {
                return null; // If the note itself is internal and we are not including internal items.
            }
            $note['properties'] = $this->getNoteProperties($noteId, $includeInternal);
        }
        
        return $note;
    }

    public function getNotesByPageId($pageId, $includeInternal = false, $pageNumber = 1, $perPage = null) {
        $params = [':pageId' => $pageId];
        $countSql = "SELECT COUNT(*) FROM Notes WHERE page_id = :pageId";
        if (!$includeInternal) {
            $countSql .= " AND internal = 0";
        }

        $notesSql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes WHERE Notes.page_id = :pageId";
        if (!$includeInternal) {
            $notesSql .= " AND Notes.internal = 0";
        }
        $notesSql .= " ORDER BY Notes.order_index ASC";

        $paginationResult = null;

        if ($perPage !== null && $perPage > 0) {
            $totalNotesStmt = $this->pdo->prepare($countSql);
            $totalNotesStmt->execute([':pageId' => $pageId]); // Assuming internal filtering is part of count query
            $totalItems = (int)$totalNotesStmt->fetchColumn();

            $perPage = max(1, (int)$perPage);
            $pageNumber = max(1, (int)$pageNumber);
            $offset = ($pageNumber - 1) * $perPage;
            $totalPages = (int)ceil($totalItems / $perPage);
            
            $notesSql .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            
            $paginationResult = [
                'total_items' => $totalItems,
                'per_page' => $perPage,
                'current_page' => $pageNumber,
                'total_pages' => $totalPages,
            ];
        }
        
        error_log("[DEBUG] getNotesByPageId called for pageId: {$pageId}, includeInternal: " . ($includeInternal ? 'true' : 'false') . ", page: {$pageNumber}, perPage: " . ($perPage ?? 'all'));
        error_log("[DEBUG] SQL query: " . $notesSql . " with params: " . json_encode($params));
        
        $stmt = $this->pdo->prepare($notesSql);
        // Bind parameters dynamically based on what's included
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        if (strpos($notesSql, ':limit') !== false) {
            $stmt->bindParam(':limit', $perPage, PDO::PARAM_INT);
        }
        if (strpos($notesSql, ':offset') !== false) {
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute(); // Execute without passing params array directly, as they are bound
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("[DEBUG] Found " . count($notes) . " notes for pageId: " . $pageId);
        
        if (empty($notes)) {
            return ['notes' => [], 'pagination' => $paginationResult];
        }

        $noteIds = array_column($notes, 'id');
        
        // Fetch all properties for these notes in a single query
        $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
        $propSql = "SELECT note_id, name, value, internal FROM Properties WHERE note_id IN ($placeholders)";
        if (!$includeInternal) {
            $propSql .= " AND internal = 0";
        }
        $propSql .= " ORDER BY name";

        $stmtProps = $this->pdo->prepare($propSql);
        $stmtProps->execute($noteIds);
        $allPropertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);
        
        // Group properties by note_id
        $propertiesByNoteId = [];
        foreach ($allPropertiesResult as $prop) {
            $propertiesByNoteId[$prop['note_id']][] = $prop;
        }
        
        // Embed properties into each note
        foreach ($notes as &$note) {
            $currentNoteProperties = $propertiesByNoteId[$note['id']] ?? [];
            $note['properties'] = $this->_formatProperties($currentNoteProperties, $includeInternal);
        }
        unset($note); // Break the reference
        
        return $notes;
    }

    public function getPageDetailsById($pageId, $includeInternal = false) {
        error_log("[DEBUG] getPageDetailsById called for pageId: " . $pageId);
        
        $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE id = :id");
        $stmt->bindParam(':id', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("[DEBUG] Raw page data from database: " . json_encode($page));

        if ($page) {
            error_log("[DEBUG] Getting page properties");
            $page['properties'] = $this->getPageProperties($pageId, $includeInternal);
            error_log("[DEBUG] Page properties: " . json_encode($page['properties']));
        }
        return $page;
    }

    public function getPageWithNotes($pageId, $includeInternal = false, $notesPageNumber = 1, $notesPerPage = null) {
        error_log("[DEBUG] getPageWithNotes called for pageId: {$pageId}, includeInternal: " . ($includeInternal ? 'true' : 'false') . ", notesPage: {$notesPageNumber}, notesPerPage: " . ($notesPerPage ?? 'all'));
        
        $pageDetails = $this->getPageDetailsById($pageId, $includeInternal);
        error_log("[DEBUG] getPageDetailsById result: " . json_encode($pageDetails));

        if (!$pageDetails) {
            error_log("[DEBUG] Page not found by getPageDetailsById for ID: " . $pageId . ". Returning null from getPageWithNotes.");
            return null;
        }
        
        $notesResult = $this->getNotesByPageId($pageId, $includeInternal, $notesPageNumber, $notesPerPage);
        error_log("[DEBUG] getNotesByPageId result: " . json_encode($notesResult));
        
        return [
            'page' => $pageDetails,
            'notes_data' => [ // New structure
                'notes' => $notesResult['notes'],
                'pagination' => $notesResult['pagination']
            ]
        ];
    }

    public function getPropertiesForNoteIds(array $noteIds, $includeInternal = false) {
        if (empty($noteIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
        $propSql = "SELECT note_id, name, value, internal FROM Properties WHERE note_id IN ($placeholders)";
        
        // Consider if properties themselves should be filtered by their own 'internal' flag here
        // For now, this method fetches all properties and relies on _formatProperties to handle the includeInternal display logic.
        // If an `AND internal = 0` clause is needed here based on $includeInternal, it would be:
        // if (!$includeInternal) { $propSql .= " AND internal = 0"; }
        // However, _formatProperties is designed to handle this at the formatting stage.

        $propSql .= " ORDER BY note_id, name"; // Order by note_id for easier grouping

        $stmtProps = $this->pdo->prepare($propSql);
        $stmtProps->execute($noteIds);
        $allPropertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

        $propertiesByNoteIdRaw = [];
        foreach ($allPropertiesResult as $prop) {
            $propertiesByNoteIdRaw[$prop['note_id']][] = $prop;
        }

        $formattedPropertiesByNoteId = [];
        foreach ($propertiesByNoteIdRaw as $noteId => $props) {
            $formattedPropertiesByNoteId[$noteId] = $this->_formatProperties($props, $includeInternal);
        }
        
        return $formattedPropertiesByNoteId;
    }
}