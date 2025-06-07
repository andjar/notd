<?php

require_once 'response_utils.php';

class DataManager {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Placeholder for _formatProperties helper method
    private function _formatProperties($propertiesResult, $includeInternal = false) {
        $formattedProperties = [];
        if (empty($propertiesResult)) {
            return $formattedProperties;
        }

        // Group properties by name first
        $groupedByName = [];
        foreach ($propertiesResult as $prop) {
            $groupedByName[$prop['name']][] = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
        }

        foreach ($groupedByName as $name => $values) {
            if (count($values) === 1) {
                // If only one property value
                if (!$includeInternal && $values[0]['internal'] == 0) {
                    // If not including internal and the property is not internal, simplify to value
                    $formattedProperties[$name] = $values[0]['value'];
                } else {
                    // Otherwise, keep as an object to show internal flag or if it's an internal property
                    $formattedProperties[$name] = $values[0];
                }
            } else {
                // For multiple values (lists)
                if (!$includeInternal) {
                    // Filter out internal properties if not included
                    $filteredValues = array_filter($values, function($value) {
                        return $value['internal'] == 0;
                    });
                    // If all were internal and filtered out, this property might become empty or just not be set.
                    // If after filtering, only one non-internal item remains, simplify it.
                    if (count($filteredValues) === 1) {
                         $singleValue = array_values($filteredValues)[0]; // Get the single item
                         $formattedProperties[$name] = $singleValue['value'];
                    } elseif (count($filteredValues) > 1) {
                        // If multiple non-internal items, return array of values
                        $formattedProperties[$name] = array_map(function($v) { return $v['value']; }, $filteredValues);
                    } else {
                        // If all values were internal and includeInternal is false, the property is effectively empty or not shown
                        // Depending on desired behavior, one might choose to add an empty array or skip the property.
                        // For now, let's skip it if all are internal and not included.
                    }
                } else {
                    // If including internal, return all values as an array of objects
                    $formattedProperties[$name] = $values;
                }
            }
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
        $sql = "SELECT * FROM Notes WHERE id = :id";
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

    public function getNotesByPageId($pageId, $includeInternal = false) {
        $notesSql = "SELECT * FROM Notes WHERE page_id = :pageId";
        if (!$includeInternal) {
            $notesSql .= " AND internal = 0";
        }
        $notesSql .= " ORDER BY order_index ASC";
        
        error_log("[DEBUG] getNotesByPageId called for pageId: " . $pageId . ", includeInternal: " . ($includeInternal ? 'true' : 'false'));
        error_log("[DEBUG] SQL query: " . $notesSql);
        
        $stmt = $this->pdo->prepare($notesSql);
        $stmt->bindParam(':pageId', $pageId, PDO::PARAM_INT);
        $stmt->execute();
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("[DEBUG] Found " . count($notes) . " notes for pageId: " . $pageId);
        if (!empty($notes)) {
            error_log("[DEBUG] First note: " . json_encode($notes[0]));
        }
        
        if (empty($notes)) {
            return [];
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

    public function getPageWithNotes($pageId, $includeInternal = false) {
        error_log("[DEBUG] getPageWithNotes called for pageId: " . $pageId);
        
        $pageDetails = $this->getPageDetailsById($pageId, $includeInternal);
        error_log("[DEBUG] getPageDetailsById result: " . json_encode($pageDetails));

        if (!$pageDetails) {
            error_log("[DEBUG] Page not found by getPageDetailsById for ID: " . $pageId . ". Returning null from getPageWithNotes."); // Updated log
            return null; // Added this line
        }
        
        // If $pageDetails was null, the function now exits above.
        // The original code continued here:
        // error_log("[DEBUG] Page not found for ID: " . $pageId); // This log might be confusing if pageDetails is null and we proceed.
        // We should ensure this part is only reached if $pageDetails is valid.

        $notes = $this->getNotesByPageId($pageId, $includeInternal);
        error_log("[DEBUG] getNotesByPageId result: " . json_encode($notes));
        
        return [
            'page' => $pageDetails,
            'notes' => $notes
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

?>
