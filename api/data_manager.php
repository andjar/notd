<?php

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
    public function getPropertiesForNoteIds(array $noteIds, bool $includeInternal = false): array {
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

        $results = array_fill_keys($noteIds, []);
        foreach ($allProps as $prop) {
            $noteId = $prop['note_id'];
            $key = $prop['name'];
            if (!isset($results[$noteId][$key])) {
                $results[$noteId][$key] = [];
            }
            $results[$noteId][$key][] = [
                'value' => $prop['value'],
                'internal' => (int)($prop['weight'] ?? 2) > 2
            ];
        }
        return $results;
    }

    /**
     * Retrieves a single note by its ID, including its formatted properties.
     */
    public function getNoteById(int $noteId, bool $includeInternal = false): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM Notes WHERE id = :id AND active = 1");
        $stmt->execute([':id' => $noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($note) {
            $note['properties'] = $this->getNoteProperties($noteId, $includeInternal);
        }
        return $note ?: null;
    }


    /**
     * Retrieves all notes for a page, with properties embedded.
     */
    public function getNotesByPageId(int $pageId, bool $includeInternal = false): array {
        $notesStmt = $this->pdo->prepare("SELECT * FROM Notes WHERE page_id = :pageId AND active = 1 ORDER BY order_index ASC");
        $notesStmt->execute([':pageId' => $pageId]);
        $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($notes)) return [];

        $noteIds = array_column($notes, 'id');
        $propertiesByNoteId = $this->getPropertiesForNoteIds($noteIds, $includeInternal);

        foreach ($notes as &$note) {
            $note['properties'] = $propertiesByNoteId[$note['id']] ?? [];
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
}