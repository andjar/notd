<?php
require_once 'db_connect.php';
require_once 'response_utils.php';

class PropertyValidation {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function validateProperty($entityType, $propertyName, $propertyValue) {
        // Check if property is required
        if ($this->isRequiredProperty($propertyName) && empty($propertyValue)) {
            return [
                'valid' => false,
                'message' => "Property '{$propertyName}' is required"
            ];
        }

        // Check property type
        $type = $this->getPropertyType($propertyName);
        if (!$this->validatePropertyType($propertyValue, $type)) {
            return [
                'valid' => false,
                'message' => "Property '{$propertyName}' must be of type '{$type}'"
            ];
        }

        // Check property constraints
        $constraints = $this->getPropertyConstraints($propertyName);
        foreach ($constraints as $constraint => $value) {
            if (!$this->validateConstraint($propertyValue, $constraint, $value)) {
                return [
                    'valid' => false,
                    'message' => "Property '{$propertyName}' failed constraint: {$constraint}"
                ];
            }
        }

        return ['valid' => true];
    }

    private function isRequiredProperty($propertyName) {
        $requiredProperties = ['title', 'type', 'status'];
        return in_array($propertyName, $requiredProperties);
    }

    private function getPropertyType($propertyName) {
        $types = [
            'title' => 'string',
            'type' => 'string',
            'status' => 'string',
            'internal' => 'boolean',
            'alias' => 'string'
        ];

        return $types[$propertyName] ?? 'string';
    }

    private function validatePropertyType($value, $type) {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'boolean':
                return is_bool($value) || in_array($value, ['0', '1', 'true', 'false'], true);
            case 'integer':
                return is_numeric($value) && is_int((int)$value);
            case 'float':
                return is_numeric($value);
            default:
                return true;
        }
    }

    private function getPropertyConstraints($propertyName) {
        $constraints = [
            'title' => [
                'minLength' => 1,
                'maxLength' => 255
            ],
            'type' => [
                'allowedValues' => ['note', 'page', 'journal']
            ],
            'status' => [
                'allowedValues' => ['draft', 'published', 'archived']
            ]
        ];

        return $constraints[$propertyName] ?? [];
    }

    private function validateConstraint($value, $constraint, $constraintValue) {
        switch ($constraint) {
            case 'minLength':
                return strlen($value) >= $constraintValue;
            case 'maxLength':
                return strlen($value) <= $constraintValue;
            case 'allowedValues':
                return in_array($value, $constraintValue);
            default:
                return true;
        }
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$propertyValidation = new PropertyValidation($pdo); 