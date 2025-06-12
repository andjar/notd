<?php

require_once __DIR__ . '/../config.php'; // For PROPERTY_WEIGHTS
require_once 'response_utils.php';

class DataManager {
    protected $pdo; // <-- FIX: Changed from 'private' to 'protected'

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Formats raw property results into the structure required by the API spec.
     * Groups properties by name and handles visibility based on weight configuration.
     *
     * @param array $propertiesResult Raw properties from the database (with name, value, weight, created_at).
     * @param bool $includeInternal If true, includes properties marked as not visible in view mode.
     * @return array The formatted properties array.
     */
    private function _formatProperties($propertiesResult, $includeInternal = false) {
        $formattedProperties = [];
        if (empty($propertiesResult)) {
            return $formattedProperties;
        }

        // Group properties by name first
        $groupedByName = [];
        foreach ($propertiesResult as $prop) {
            // Ensure weight is an integer for array key access
            $weight = (int)$prop['weight'];

            // Skip properties that shouldn't be visible, unless explicitly requested
            if (!$includeInternal) {
                // Default to true if weight is not defined in config
                $isVisible = PROPERTY_WEIGHTS[$weight]['visible_in_view_mode'] ?? true;
                if (!$isVisible) {
                    continue;
                }
            }
            
            // The API spec requires an array of objects for each property name
            // to support historical values from 'append' behavior.
            $groupedByName[$prop['name']][] = [
                'value' => $prop['value'],
                'weight' => $weight,
                'created_at' => $prop['created_at']
            ];
        }
        
        return $groupedByName;
    }

    /**
     * Retrieves and formats all properties for a specific page.
     *
     * @param int $pageId The ID of the page.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array The formatted properties.
     */
    public function getPageProperties($pageId, $includeInternal = false) {
        $sql = "SELECT name, value, weight, created_at FROM Properties WHERE page_id = :pageId AND active = 1 ORDER BY created_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $propertiesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->_formatProperties($propertiesResult, $includeInternal);
    }

    /**
     * Retrieves and formats all properties for a specific note.
     *
     * @param int $noteId The ID of the note.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array The formatted properties.
     */
    public function getNoteProperties($noteId, $includeInternal = false) {
        $sql = "SELECT name, value, weight, created_at FROM Properties WHERE note_id = :noteId AND active = 1 ORDER BY created_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':noteId', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $propertiesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->_formatProperties($propertiesResult, $includeInternal);
    }

    /**
     * Retrieves a single note by its ID, including its formatted properties.
     *
     * @param int $noteId The ID of the note.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array|null The note data or null if not found.
     */
    public function getNoteById($noteId, $includeInternal = false) {
        $sql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes WHERE Notes.id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':id', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($note) {
            $note['properties'] = $this->getNoteProperties($noteId, $includeInternal);
        }
        
        return $note;
    }

    /**
     * Retrieves all notes for a given page ID, including their formatted properties.
     *
     * @param int $pageId The ID of the page.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array An array of note data.
     */
    public function getNotesByPageId($pageId, $includeInternal = false) {
        $notesSql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes WHERE Notes.page_id = :pageId AND Notes.active = 1 ORDER BY Notes.order_index ASC";
        
        $stmt = $this->pdo->prepare($notesSql);
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($notes)) {
            return [];
        }

        $noteIds = array_column($notes, 'id');
        
        $propertiesByNoteId = $this->getPropertiesForNoteIds($noteIds, $includeInternal);
        
        // Embed formatted properties into each note.
        foreach ($notes as &$note) {
            $note['properties'] = $propertiesByNoteId[$note['id']] ?? [];
        }
        unset($note);
        
        return $notes;
    }

    /**
     * Retrieves a single page by its ID, including its formatted properties.
     *
     * @param int $pageId The ID of the page.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array|null The page data or null if not found.
     */
    public function getPageDetailsById($pageId, $includeInternal = false) {
        $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE id = :id AND active = 1");
        $stmt->bindParam(':id', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($page) {
            $page['properties'] = $this->getPageProperties($pageId, $includeInternal);
        }
        return $page;
    }

    /**
     * Retrieves a page and all of its notes, with formatted properties for both.
     *
     * @param int $pageId The ID of the page.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array|null An array containing page and notes, or null if the page is not found.
     */
    public function getPageWithNotes($pageId, $includeInternal = false) {
        $pageDetails = $this->getPageDetailsById($pageId, $includeInternal);

        if (!$pageDetails) {
            return null;
        }
        
        $notes = $this->getNotesByPageId($pageId, $includeInternal);
        
        return [
            'page' => $pageDetails,
            'notes' => $notes
        ];
    }
    
    /**
     * Retrieves formatted properties for multiple note IDs in a single call.
     *
     * @param array $noteIds An array of note IDs.
     * @param bool $includeInternal If true, includes properties not normally visible in view mode.
     * @return array An associative array mapping note IDs to their formatted properties.
     */
    public function getPropertiesForNoteIds(array $noteIds, $includeInternal = false) {
        if (empty($noteIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
        $propSql = "SELECT note_id, name, value, weight, created_at FROM Properties WHERE note_id IN ($placeholders) AND active = 1 ORDER BY note_id, created_at ASC";

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
        
        foreach ($noteIds as $noteId) {
            if (!isset($formattedPropertiesByNoteId[$noteId])) {
                $formattedPropertiesByNoteId[$noteId] = [];
            }
        }
        
        return $formattedPropertiesByNoteId;
    }
}