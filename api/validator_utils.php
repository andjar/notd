<?php

if (!class_exists('Validator')) {
    class Validator {

        public static function sanitizeString($str) {
        return trim((string)$str);
    }

    public static function isInteger($value) {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public static function isPositiveInteger($value) {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    public static function isNotEmpty($value) {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return !empty($value);
        }
        return $value !== null && $value !== '';
    }

    public static function isValidEntityType($type) {
        return in_array($type, ['note', 'page']);
    }

    public static function isValidPropertyNames($value) {
        if ($value === '*') {
            return true;
        }
        
        if (is_array($value)) {
            foreach ($value as $name) {
                if (!is_string($name) || empty($name)) {
                    return false;
                }
            }
            return true;
        }
        
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
                return self::isValidPropertyNames($decoded);
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }

    public static function isValidEventTypes($value) {
        $validEventTypes = ['property_change', 'entity_created', 'entity_updated', 'entity_deleted'];
        
        if (is_array($value)) {
            foreach ($value as $type) {
                if (!is_string($type) || !in_array($type, $validEventTypes)) {
                    return false;
                }
            }
            return true;
        }
        
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
                return self::isValidEventTypes($decoded);
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }

    /**
     * Validates fields in a data array based on a rules array.
     *
     * @param array $data The data to validate (e.g., $_POST, $_GET, json input).
     * @param array $rules Associative array where keys are field names.
     *                     Values can be:
     *                     - A string: 'required|ruleName' (e.g., 'required|isPositiveInteger')
     *                                 'optional|ruleName' (e.g., 'optional|isInteger')
     *                                 'ruleName' (implies required, e.g., 'isPositiveInteger')
     *                     - An array: ['rule' => 'ruleName', 'message' => 'Custom error message', 'optional' => true/false]
     * @return array Empty if all validations pass, otherwise an array of error messages.
     */
    public static function validate(array $data, array $rules): array {
        $errors = [];

        foreach ($rules as $field => $ruleEntry) {
            $isOptional = false;
            $validationRule = '';
            $customMessage = "Invalid value for {$field}.";

            if (is_string($ruleEntry)) {
                $parts = explode('|', $ruleEntry);
                if (count($parts) > 1) {
                    if ($parts[0] === 'required') {
                        $validationRule = $parts[1];
                    } elseif ($parts[0] === 'optional') {
                        $isOptional = true;
                        $validationRule = $parts[1] ?? null; // Allow optional without a rule
                    } else { // Assumes rule implies required if not 'optional'
                        $validationRule = $ruleEntry;
                    }
                } else {
                    if ($ruleEntry === 'optional') {
                        $isOptional = true;
                        $validationRule = null;
                    } else {
                        $validationRule = $ruleEntry; // Default to required
                    }
                }
            } elseif (is_array($ruleEntry)) {
                $validationRule = $ruleEntry['rule'] ?? null;
                if (isset($ruleEntry['message'])) {
                    $customMessage = $ruleEntry['message'];
                }
                if (isset($ruleEntry['optional']) && $ruleEntry['optional'] === true) {
                    $isOptional = true;
                }
            } else {
                // Should not happen if rules are defined correctly
                $errors[$field] = "Invalid validation rule definition for {$field}.";
                continue;
            }
            
            $value = $data[$field] ?? null;

            // Handle 'required' check
            if (!$isOptional && !isset($data[$field])) {
                $errors[$field] = "Field {$field} is required.";
                continue; 
            }
            
            // Skip validation for optional fields if they are not present
            if ($isOptional && !isset($data[$field])) {
                continue;
            }
            
            // Skip validation if no validation rule is specified
            if ($validationRule === null) {
                continue;
            }
            
            // Specific rule checks
            switch ($validationRule) {
                case 'isInteger':
                    if (!self::isInteger($value)) {
                        $errors[$field] = $customMessage;
                    }
                    break;
                case 'isPositiveInteger':
                    if (!self::isPositiveInteger($value)) {
                        $errors[$field] = $customMessage;
                    }
                    break;
                case 'isNotEmpty':
                    if (!self::isNotEmpty($value)) {
                        $errors[$field] = $customMessage;
                    }
                    break;
                case 'isValidEntityType':
                    if (!self::isValidEntityType($value)) {
                        $errors[$field] = $customMessage;
                    }
                    break;
                case 'isBooleanLike': // For 0 or 1
                    if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1' && !is_bool($value)) {
                        $errors[$field] = $customMessage . " Must be 0 or 1.";
                    }
                    break;
                // Add more cases for other validation rules as needed
                default:
                    if (method_exists(__CLASS__, $validationRule)) {
                        if (!self::$validationRule($value)) {
                             $errors[$field] = $customMessage;
                        }
                    } else {
                        // This could be an error in rule definition
                        // For now, let's assume it's a custom function not found, or handle as needed
                    }
                    break;
            }
        }
        return $errors;
        }
    }
}
?>
