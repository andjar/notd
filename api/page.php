<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

try {
    $db = new SQLite3('../db/notes.db');
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }

    function getPage($id) {
        global $db;
        
        error_log("Getting page with ID: " . $id);
        
        // Get page details
        $stmt = $db->prepare('SELECT * FROM pages WHERE id = :id');
        if (!$stmt) {
            throw new Exception('Failed to prepare page query: ' . $db->lastErrorMsg());
        }
        
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if (!$result) {
            throw new Exception('Failed to execute page query: ' . $db->lastErrorMsg());
        }
        
        $page = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$page) {
            error_log("Page not found, creating new page: " . $id);
            // If page doesn't exist, create it
            $db->exec('BEGIN TRANSACTION');
            
            $stmt = $db->prepare('
                INSERT INTO pages (id, title, type)
                VALUES (:id, :title, :type)
            ');
            
            if (!$stmt) {
                throw new Exception('Failed to prepare page insert: ' . $db->lastErrorMsg());
            }
            
            // Check if pageId is a date (YYYY-MM-DD)
            $isJournal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $id);
            $type = $isJournal ? 'journal' : 'note';
            
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->bindValue(':title', $id, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            
            if (!$stmt->execute()) {
                $db->exec('ROLLBACK');
                throw new Exception('Failed to create page: ' . $db->lastErrorMsg());
            }
            
            // Add type property if it's a journal page
            if ($isJournal) {
                $stmt = $db->prepare('
                    INSERT INTO properties (page_id, property_key, property_value)
                    VALUES (:page_id, :key, :value)
                ');
                
                if (!$stmt) {
                    $db->exec('ROLLBACK');
                    throw new Exception('Failed to prepare property insert: ' . $db->lastErrorMsg());
                }
                
                $stmt->bindValue(':page_id', $id, SQLITE3_TEXT);
                $stmt->bindValue(':key', 'type', SQLITE3_TEXT);
                $stmt->bindValue(':value', 'journal', SQLITE3_TEXT);
                
                if (!$stmt->execute()) {
                    $db->exec('ROLLBACK');
                    throw new Exception('Failed to insert property: ' . $db->lastErrorMsg());
                }
            }
            
            $db->exec('COMMIT');
            
            // Fetch the newly created page
            $stmt = $db->prepare('SELECT * FROM pages WHERE id = :id');
            if (!$stmt) {
                throw new Exception('Failed to prepare page fetch: ' . $db->lastErrorMsg());
            }
            
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            if (!$result) {
                throw new Exception('Failed to fetch new page: ' . $db->lastErrorMsg());
            }
            
            $page = $result->fetchArray(SQLITE3_ASSOC);
            if (!$page) {
                throw new Exception('Failed to get new page data');
            }
        }
        
        error_log("Page data retrieved: " . json_encode($page));
        
        // Get page properties
        $stmt = $db->prepare('
            SELECT property_key, property_value
            FROM properties
            WHERE page_id = :page_id
        ');
        if (!$stmt) {
            throw new Exception('Failed to prepare properties query: ' . $db->lastErrorMsg());
        }
        
        $stmt->bindValue(':page_id', $id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if (!$result) {
            throw new Exception('Failed to execute properties query: ' . $db->lastErrorMsg());
        }
        
        $properties = [];
        while ($prop = $result->fetchArray(SQLITE3_ASSOC)) {
            $properties[$prop['property_key']] = $prop['property_value'];
        }
        $page['properties'] = $properties;
        
        error_log("Properties retrieved: " . json_encode($properties));
        
        // Get notes for the page with properties and attachments
        $stmt = $db->prepare('
            SELECT 
                n.id,
                n.page_id,
                n.content,
                n.level,
                n.parent_id,
                n.block_id,
                n.created_at,
                n.updated_at,
                GROUP_CONCAT(DISTINCT p.property_key || ":" || p.property_value) as properties,
                GROUP_CONCAT(DISTINCT a.id || ":" || a.filename || ":" || a.original_name) as attachments
            FROM notes n
            LEFT JOIN properties p ON n.id = p.note_id
            LEFT JOIN attachments a ON n.id = a.note_id
            WHERE n.page_id = :page_id
            GROUP BY n.id
            ORDER BY n.level, n.id
        ');
        if (!$stmt) {
            throw new Exception('Failed to prepare notes query: ' . $db->lastErrorMsg());
        }
        
        $stmt->bindValue(':page_id', $id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if (!$result) {
            throw new Exception('Failed to execute notes query: ' . $db->lastErrorMsg());
        }
        
        $notes = [];
        $noteMap = [];
        
        while ($note = $result->fetchArray(SQLITE3_ASSOC)) {
            // Parse properties
            $properties = [];
            if ($note['properties']) {
                foreach (explode(',', $note['properties']) as $prop) {
                    list($key, $value) = explode(':', $prop, 2);
                    $properties[$key] = $value;
                }
            }
            $note['properties'] = $properties;
            
            // Parse attachments
            $attachments = [];
            if ($note['attachments']) {
                foreach (explode(',', $note['attachments']) as $att) {
                    list($id, $filename, $original_name) = explode(':', $att, 3);
                    $attachments[] = [
                        'id' => $id,
                        'filename' => $filename,
                        'original_name' => $original_name
                    ];
                }
            }
            $note['attachments'] = $attachments;
            
            // Build note tree
            if ($note['parent_id'] === null) {
                $notes[] = $note;
                $noteMap[$note['id']] = &$notes[count($notes) - 1];
            } else {
                if (!isset($noteMap[$note['parent_id']]['children'])) {
                    $noteMap[$note['parent_id']]['children'] = [];
                }
                $noteMap[$note['parent_id']]['children'][] = $note;
                $noteMap[$note['id']] = &$noteMap[$note['parent_id']]['children'][count($noteMap[$note['parent_id']]['children']) - 1];
            }
        }
        
        $page['notes'] = $notes;
        error_log("Notes retrieved: " . json_encode($notes));
        
        return $page;
    }

    function createPage($data) {
        global $db;
        
        try {
            error_log("Creating page with data: " . json_encode($data));
            
            $db->exec('BEGIN TRANSACTION');
            
            // Insert the page
            $stmt = $db->prepare('
                INSERT INTO pages (id, title, type)
                VALUES (:id, :title, :type)
            ');
            
            $stmt->bindValue(':id', $data['id'], SQLITE3_TEXT);
            $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
            $stmt->bindValue(':type', $data['type'] ?? 'note', SQLITE3_TEXT);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create page: ' . $db->lastErrorMsg());
            }
            
            error_log("Page created successfully");
            
            // Insert properties if any
            if (isset($data['properties']) && !empty($data['properties'])) {
                error_log("Properties to insert: " . json_encode($data['properties']));
                
                $stmt = $db->prepare('
                    INSERT INTO properties (page_id, property_key, property_value)
                    VALUES (:page_id, :key, :value)
                ');
                
                foreach ($data['properties'] as $key => $value) {
                    error_log("Inserting property - page_id: {$data['id']}, key: $key, value: $value");
                    
                    $stmt->bindValue(':page_id', $data['id'], SQLITE3_TEXT);
                    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to insert property ' . $key . ': ' . $db->lastErrorMsg());
                    }
                    error_log("Property inserted successfully");
                }
            } else {
                error_log("No properties to insert");
            }
            
            $db->exec('COMMIT');
            error_log("Transaction committed successfully");
            
            $result = getPage($data['id']);
            error_log("Final page data: " . json_encode($result));
            return $result;
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            error_log("Error in createPage: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    function updatePage($id, $data) {
        global $db;
        
        try {
            error_log('Updating page: ' . $id);
            error_log('Data received: ' . json_encode($data));
            
            $db->exec('BEGIN TRANSACTION');
            
            // Update page details
            $stmt = $db->prepare('
                UPDATE pages
                SET title = :title,
                    type = :type,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ');
            
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
            $stmt->bindValue(':type', $data['type'], SQLITE3_TEXT);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update page: ' . $db->lastErrorMsg());
            }
            
            // Update properties if provided
            if (isset($data['properties'])) {
                error_log('Updating properties: ' . json_encode($data['properties']));
                
                // Delete existing properties
                $stmt = $db->prepare('DELETE FROM properties WHERE page_id = :page_id');
                $stmt->bindValue(':page_id', $id, SQLITE3_TEXT);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete existing properties: ' . $db->lastErrorMsg());
                }
                
                // Insert new properties
                if (!empty($data['properties'])) {
                    $stmt = $db->prepare('
                        INSERT INTO properties (page_id, property_key, property_value)
                        VALUES (:page_id, :key, :value)
                    ');
                    
                    foreach ($data['properties'] as $key => $value) {
                        error_log("Inserting property: $key = $value");
                        $stmt->bindValue(':page_id', $id, SQLITE3_TEXT);
                        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
                        
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to insert property ' . $key . ': ' . $db->lastErrorMsg());
                        }
                    }
                }
            }
            
            $db->exec('COMMIT');
            error_log('Page update successful');
            return getPage($id);
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            error_log('Page update error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // Handle the request
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    error_log("Request received - Method: $method, ID: $id");

    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        error_log("Received input: " . $input);
        
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        if (!$data) {
            throw new Exception('Invalid data');
        }

        $action = $data['action'] ?? 'create';
        
        switch ($action) {
            case 'create':
                $result = createPage($data);
                break;
                
            case 'update':
                if (!$id) {
                    throw new Exception('Page ID required');
                }
                $result = updatePage($id, $data);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
        error_log("Sending response: " . json_encode($result));
        echo json_encode($result);
    } else if ($method === 'GET') {
        if (!$id) {
            throw new Exception('Page ID required');
        }
        $result = getPage($id);
        error_log("Sending response: " . json_encode($result));
        echo json_encode($result);
    } else {
        throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    error_log("Error in page.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 