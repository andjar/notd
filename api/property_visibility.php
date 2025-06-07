<?php
require_once 'db_connect.php';
require_once 'response_utils.php';

class PropertyVisibility {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function isPropertyVisible($entityType, $entityId, $propertyName) {
        // Check if property is internal
        if ($this->isInternalProperty($propertyName)) {
            return false;
        }

        // Check if entity is internal
        if ($this->isInternalEntity($entityType, $entityId)) {
            return false;
        }

        // Check if property has visibility override
        $visibility = $this->getPropertyVisibility($entityType, $entityId, $propertyName);
        if ($visibility !== null) {
            return $visibility;
        }

        return true;
    }

    private function isInternalProperty($propertyName) {
        $internalProperties = ['internal', '_system', '_meta'];
        return in_array($propertyName, $internalProperties) || strpos($propertyName, '_') === 0;
    }

    private function isInternalEntity($entityType, $entityId) {
        $table = $this->getTableForEntityType($entityType);
        $idColumn = $this->getIdColumnForEntityType($entityType);
        
        $stmt = $this->pdo->prepare("SELECT internal FROM {$table} WHERE {$idColumn} = ?");
        $stmt->execute([$entityId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && $result['internal'];
    }

    private function getPropertyVisibility($entityType, $entityId, $propertyName) {
        $table = $this->getTableForEntityType($entityType);
        $idColumn = $this->getIdColumnForEntityType($entityType);
        
        $stmt = $this->pdo->prepare("SELECT visibility FROM Properties WHERE {$idColumn} = ? AND name = ?");
        $stmt->execute([$entityId, $propertyName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['visibility'] : null;
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
$propertyVisibility = new PropertyVisibility($pdo); 