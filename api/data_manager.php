<?php

namespace App;

use PDO;

require_once __DIR__ . '/../config.php';

class DataManager {
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Formats raw property results into the API structure, grouping by name.
     */
    private function _formatProperties(array $propertiesResult): array {
        $formatted = [];
        foreach ($propertiesResult as $prop) {
            $key = $prop['name'];
            if (!isset($formatted[$key])) {
                $formatted[$key] = [];
            }
            $formatted[$key][] = [
                'value' => $prop['value'],
                'internal' => (int)($prop['weight'] ?? 2) > 2 // Simplified check
            ];
        }
        return $formatted;
    }

    /**
     * Retrieves properties for a single page.
     */
    public function getPageProperties(int $pageId, bool $includeInternal = false): array {
        $sql = "SELECT name, value, weight, created_at FROM Properties WHERE page_id = :pageId AND active = 1";
        if (!$includeInternal) {
            // Assuming weight 3+ is internal, consistent with config.php
            $sql .= " AND weight < 3";
        }
        $sql .= " ORDER BY created_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pageId' => $pageId]);
        return $this->_formatProperties($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    /**
     * Retrieves properties for a single note.
     */
    public function getNoteProperties(int $noteId, bool $includeInternal = false): array {
        $sql = "SELECT name, value, weight, created_at FROM Properties WHERE note_id = :noteId AND active = 1";
        if (!$includeInternal) {
            $sql .= " AND weight < 3";
        }
        $sql .= " ORDER BY created_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':noteId' => $noteId]);
        return $this->_formatProperties($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    /**
     * Efficiently fetches properties for multiple note IDs.
     */
    public function getPropertiesForNoteIds(array $noteIds, bool $includeInternal = false, bool $includeParentProperties = false): array {
        if (empty($noteIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $sql = "SELECT note_id, name, value, weight, created_at FROM Properties WHERE note_id IN ($placeholders) AND active = 1";
        if (!$includeInternal) {
            $sql .= " AND weight < 3";
        }
        $sql .= " ORDER BY note_id, created_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($noteIds);
        $allProps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($noteIds as $id) {
            $results[$id] = ['parent_properties' => []]; // Initialize with parent_properties
        }

        foreach ($allProps as $prop) {
            $noteId = $prop['note_id'];
            $key = $prop['name'];
            // Ensure the base structure for the note exists, though it should by the loop above
            if (!isset($results[$noteId])) {
                $results[$noteId] = ['parent_properties' => []];
            }
            if (!isset($results[$noteId][$key])) {
                $results[$noteId][$key] = [];
            }
            $results[$noteId][$key][] = [
                'value' => $prop['value'],
                'internal' => (int)($prop['weight'] ?? 2) > 2
            ];
        }

        if ($includeParentProperties) {
            // Fetch parent_note_id for all relevant notes
            $noteParentMap = [];
            $notesToFetchParentsFor = $noteIds; // Initially, all notes
            $currentLevelNotes = $notesToFetchParentsFor;

            // Prepare a statement to get note details (specifically parent_note_id)
            // This is done outside the loop for efficiency if fetching many notes' parents
            $parentNoteIdStmt = $this->pdo->prepare("SELECT id, parent_note_id FROM Notes WHERE id = :id AND active = 1");

            foreach ($noteIds as $noteId) {
                $parentPropertiesData = [];
                $currentParentId = null;

                // Get the initial parent_note_id for the current noteId
                // We need to fetch the note itself to find its parent_note_id first
                $initialParentStmt = $this->pdo->prepare("SELECT parent_note_id FROM Notes WHERE id = :id AND active = 1");
                $initialParentStmt->execute([':id' => $noteId]);
                $noteDetails = $initialParentStmt->fetch(PDO::FETCH_ASSOC);
                $currentParentId = $noteDetails ? $noteDetails['parent_note_id'] : null;

                $visitedParentIds = []; // To prevent infinite loops

                while ($currentParentId !== null && !isset($visitedParentIds[$currentParentId])) {
                    $visitedParentIds[$currentParentId] = true;

                    // Fetch details of the current parent note
                    $parentNoteIdStmt->execute([':id' => $currentParentId]);
                    $parentNode = $parentNoteIdStmt->fetch(PDO::FETCH_ASSOC);

                    if ($parentNode) {
                        // Fetch properties for this parent node
                        $properties = $this->getNoteProperties($parentNode['id'], $includeInternal);
                        foreach ($properties as $name => $valuesArray) {
                            if (!isset($parentPropertiesData[$name])) {
                                $parentPropertiesData[$name] = [];
                            }
                            foreach ($valuesArray as $propValueItem) {
                                // Ensure uniqueness of property values directly
                                if (!in_array($propValueItem['value'], array_column($parentPropertiesData[$name], 'value'))) {
                                     $parentPropertiesData[$name][] = ['value' => $propValueItem['value']];
                                }
                            }
                        }
                        $currentParentId = $parentNode['parent_note_id'];
                    } else {
                        break;
                    }
                }
                // Assign collected parent properties to the result for the current noteId
                // The structure should be $parentPropertiesData['prop_name'] = [['value' => 'val1'], ['value' => 'val2']]
                $results[$noteId]['parent_properties'] = $parentPropertiesData;
            }
        }
        return $results;
    }

    /**
     * Retrieves a single note by its ID, including its formatted properties.
     */
    public function getNoteById(int $noteId, bool $includeInternal = false, bool $includeParentProperties = false): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM Notes WHERE id = :id AND active = 1");
        $stmt->execute([':id' => $noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($note) {
            // Check for attachments
            $attachmentStmt = $this->pdo->prepare("SELECT EXISTS(SELECT 1 FROM Attachments WHERE note_id = :note_id AND active = 1 LIMIT 1)");
            $attachmentStmt->execute([':note_id' => $noteId]);
            $note['has_attachments'] = (bool) $attachmentStmt->fetchColumn();

            $note['properties'] = $this->getNoteProperties($noteId, $includeInternal);

            if ($includeParentProperties) {
                $parentPropertiesData = [];
                $currentParentId = $note['parent_note_id'];
                $visitedParentIds = []; // To prevent infinite loops in case of cyclic parentage

                while ($currentParentId !== null && !isset($visitedParentIds[$currentParentId])) {
                    $visitedParentIds[$currentParentId] = true; // Mark as visited
                    $parentStmt = $this->pdo->prepare("SELECT id, parent_note_id FROM Notes WHERE id = :id AND active = 1");
                    $parentStmt->execute([':id' => $currentParentId]);
                    $parentNode = $parentStmt->fetch(PDO::FETCH_ASSOC);

                    if ($parentNode) {
                        $properties = $this->getNoteProperties($parentNode['id'], $includeInternal);
                        foreach ($properties as $name => $valuesArray) {
                            if (!isset($parentPropertiesData[$name])) {
                                $parentPropertiesData[$name] = [];
                            }
                            foreach ($valuesArray as $propValueItem) {
                                // Add value if not already present for this property name
                                if (!in_array($propValueItem['value'], $parentPropertiesData[$name])) {
                                    $parentPropertiesData[$name][] = $propValueItem['value'];
                                }
                            }
                        }
                        $currentParentId = $parentNode['parent_note_id'];
                    } else {
                        break; // Parent not found or not active, stop traversal
                    }
                }

                // Format $parentPropertiesData into the desired structure
                $formattedParentProperties = [];
                foreach ($parentPropertiesData as $name => $values) {
                    $formattedParentProperties[$name] = array_map(function($value) {
                        return ['value' => $value];
                    }, $values);
                }
                $note['parent_properties'] = $formattedParentProperties;
            } else {
                $note['parent_properties'] = null; // Or [] for an empty array
            }
        }
        return $note ?: null;
    }


    /**
     * Retrieves all notes for a page, with properties embedded.
     */
    public function getNotesByPageId(int $pageId, bool $includeInternal = false): array {
        // First check if the active column exists
        $checkColumnStmt = $this->pdo->query("PRAGMA table_info(Notes)");
        $columns = $checkColumnStmt->fetchAll(PDO::FETCH_COLUMN, 1);
        $hasActiveColumn = in_array('active', $columns);

        // Build the SQL query based on whether active column exists
        $sql = "SELECT * FROM Notes WHERE page_id = :pageId";
        if ($hasActiveColumn) {
            $sql .= " AND active = 1";
        }
        $sql .= " ORDER BY order_index ASC";
        
        $notesStmt = $this->pdo->prepare($sql);
        $notesStmt->execute([':pageId' => $pageId]);
        $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($notes)) return [];

        $noteIds = array_column($notes, 'id');
        $propertiesByNoteId = $this->getPropertiesForNoteIds($noteIds, $includeInternal);

        // Fetch attachment status for all notes in this page
        $notesWithAttachmentsMap = [];
        if (!empty($noteIds)) {
            $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
            $attachmentSql = "SELECT note_id FROM Attachments WHERE note_id IN ($placeholders)";
            if ($hasActiveColumn) {
                $attachmentSql .= " AND active = 1";
            }
            $attachmentSql .= " GROUP BY note_id";
            
            $attachmentStmt = $this->pdo->prepare($attachmentSql);
            // Bind each note ID as an integer
            $params = array_map('intval', $noteIds);
            $attachmentStmt->execute($params);
            $notesWithAttachments = $attachmentStmt->fetchAll(PDO::FETCH_COLUMN);
            $notesWithAttachmentsMap = array_flip($notesWithAttachments);
        }

        foreach ($notes as &$note) {
            $note['properties'] = $propertiesByNoteId[$note['id']] ?? [];
            $note['has_attachments'] = isset($notesWithAttachmentsMap[$note['id']]);
        }

        return $notes;
    }

    /**
     * Retrieves a single page by its ID, with properties.
     */
    public function getPageById(int $pageId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE id = :id AND active = 1");
        $stmt->execute([':id' => $pageId]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($page) {
            $page['properties'] = $this->getPageProperties($pageId, true); // Get all properties for page details
        }
        return $page ?: null;
    }
    
    /**
     * Retrieves page details by name, optionally including notes.
     */
    public function getPageDetailsByName(string $name, bool $includeNotes = false): ?array {
        $page = $this->getPageByName($name);
        if ($page && $includeNotes) {
            $page['notes'] = $this->getNotesByPageId($page['id']);
        }
        return $page;
    }
    
    /**
     * Retrieves page details by ID, optionally including notes.
     */
    public function getPageDetailsById(int $id, bool $includeNotes = false): ?array {
        $page = $this->getPageById($id);
        if ($page && $includeNotes) {
            $page['notes'] = $this->getNotesByPageId($page['id']);
        }
        return $page;
    }

    /**
     * Retrieves a single page by its name, with properties.
     */
    public function getPageByName(string $name): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(:name) AND active = 1");
        $stmt->execute([':name' => $name]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($page) {
            $page['properties'] = $this->getPageProperties($page['id'], true);
        }
        return $page ?: null;
    }

    /**
     * Retrieves a list of pages with pagination.
     */
    public function getPages(int $page = 1, int $per_page = 20, array $options = []): array {
        $offset = ($page - 1) * $per_page;
        $baseSql = "FROM Pages WHERE active = 1";
        $params = [];

        if (!empty($options['exclude_journal'])) {
            // Note: This check relies on a {type::journal} property being in the page content.
            // A more robust way would be to check the Properties table if available.
            $baseSql .= " AND (content IS NULL OR content NOT LIKE '%{type::journal}%')";
        }

        $countSql = "SELECT COUNT(*) " . $baseSql;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        $dataSql = "SELECT id, name, content, alias, updated_at " . $baseSql . " ORDER BY updated_at DESC LIMIT :limit OFFSET :offset";
        $dataStmt = $this->pdo->prepare($dataSql);
        $dataStmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key + 1, $value);
        }
        $dataStmt->execute();
        $pages = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $pages,
            'pagination' => [
                'total_items' => $totalItems,
                'per_page' => $per_page,
                'current_page' => $page,
                'total_pages' => (int)ceil($totalItems / $per_page)
            ]
        ];
    }

    /**
     * Retrieves pages that have a 'date' property matching a given date.
     * @param string $date The date in 'YYYY-MM-DD' format.
     * @return array A list of pages found.
     */
    public function getPagesByDate(string $date): array {
        // Find page IDs that have a 'date' property with the specified value
        $sql = "
            SELECT p.id, p.name, p.content, p.alias, p.updated_at
            FROM Pages p
            JOIN Properties prop ON p.id = prop.page_id
            WHERE prop.name = 'date' AND prop.value = :date AND p.active = 1 AND prop.active = 1
            ORDER BY p.name ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':date' => $date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves pages that are direct children of a given namespace.
     * @param string $namespace The parent page/namespace name.
     * @return array A list of child pages found.
     */
    public function getChildPages(string $namespace): array {
        // We are looking for pages under the namespace, e.g., "Namespace/Child"
        $prefix = rtrim($namespace, '/') . '/';

        // This query finds pages that are direct children of the namespace.
        // It avoids matching deeper descendants (e.g., ns/child/grandchild)
        // by checking that there are no additional slashes in the name after the prefix.
        $sql = "
            SELECT id, name, updated_at
            FROM Pages
            WHERE
                LOWER(name) LIKE LOWER(:prefix) || '%' AND
                SUBSTR(LOWER(name), LENGTH(LOWER(:prefix)) + 1) NOT LIKE '%/%' AND
                active = 1
            ORDER BY name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':prefix' => $prefix]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}