<?php
require_once 'db_connect.php';
require_once 'response_utils.php';

class PropertyUtils {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getPropertyValue($entityType, $entityId, $propertyName) {
        $table = $this->getTableForEntityType($entityType);
        $idColumn = $this->getIdColumnForEntityType($entityType);
        
        $stmt = $this->pdo->prepare("SELECT value FROM Properties WHERE {$idColumn} = ? AND name = ?");
        $stmt->execute([$entityId, $propertyName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['value'] : null;
    }

    public function setPropertyValue($entityType, $entityId, $propertyName, $propertyValue) {
        $table = $this->getTableForEntityType($entityType);
        $idColumn = $this->getIdColumnForEntityType($entityType);
        
        // Check if property exists
        $stmt = $this->pdo->prepare("SELECT id FROM Properties WHERE {$idColumn} = ? AND name = ?");
        $stmt->execute([$entityId, $propertyName]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing property
            $stmt = $this->pdo->prepare("UPDATE Properties SET value = ? WHERE {$idColumn} = ? AND name = ?");
            $stmt->execute([$propertyValue, $entityId, $propertyName]);
        } else {
            // Insert new property
            $stmt = $this->pdo->prepare("INSERT INTO Properties ({$idColumn}, name, value) VALUES (?, ?, ?)");
            $stmt->execute([$entityId, $propertyName, $propertyValue]);
        }
        
        return true;
    }

    public function deleteProperty($entityType, $entityId, $propertyName) {
        $table = $this->getTableForEntityType($entityType);
        $idColumn = $this->getIdColumnForEntityType($entityType);
        
        $stmt = $this->pdo->prepare("DELETE FROM Properties WHERE {$idColumn} = ? AND name = ?");
        $stmt->execute([$entityId, $propertyName]);
        
        return true;
    }

    public function getAllProperties($entityType, $entityId) {
        $table = $this->getTableForEntityType($entityType);
        $idColumn = $this->getIdColumnForEntityType($entityType);
        
        $stmt = $this->pdo->prepare("SELECT name, value FROM Properties WHERE {$idColumn} = ?");
        $stmt->execute([$entityId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($properties as $prop) {
            $result[$prop['name']] = $prop['value'];
        }
        
        return $result;
    }

    private function getTableForEntityType($entityType) {
        $tables = [
            'note' => 'Notes',
            'page' => 'Pages'
        ];
        
        return $tables[$entityType] ?? null;
    }

    private function getIdColumnForEntityType($entityType) {
        $columns = [
            'note' => 'note_id',
            'page' => 'page_id'
        ];
        
        return $columns[$entityType] ?? null;
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$propertyUtils = new PropertyUtils($pdo); 