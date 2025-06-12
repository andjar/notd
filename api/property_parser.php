<?php
// This file requires other dependencies which are loaded by the calling script.
// It should not be accessed directly.

class PropertyParser {
    private $pdo;

    /**
     * Constructor for the PropertyParser.
     *
     * @param PDO $pdo A connected PDO object.
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Parses a content string to extract all properties defined with the {key::value} syntax.
     * This parser specifically looks for bracket-enclosed properties to avoid ambiguity.
     *
     * @param string $content The text content to parse.
     * @return array An array of parsed properties, each with name, value, and weight.
     */
    public function parsePropertiesFromContent($content) {
        $properties = [];
        
        // --- THIS IS THE CORRECTED REGEX ---
        // It specifically looks for content inside curly braces {} and stops matching the
        // value at the closing brace, allowing for multiple properties on one line.
        // It captures: 1) the key, 2) the colons, 3) the value (anything not a '}')
        $regex = '/\{([a-zA-Z0-9_\.-]+)(:{2,})([^}]+)\}/m';

        preg_match_all($regex, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            // $match[0] is the full matched string, e.g., "{priority::high}"
            // $match[1] is the key, e.g., "priority"
            // $match[2] is the colons, e.g., "::"
            // $match[3] is the value, e.g., "high"
            
            $key = trim($match[1]);
            $colons = $match[2];
            $value = trim($match[3]);
            
            // The weight is determined by the number of colons.
            $weight = strlen($colons);

            $properties[] = [
                'name' => $key,
                'value' => $value,
                'weight' => $weight
            ];
        }

        return $properties;
    }
}