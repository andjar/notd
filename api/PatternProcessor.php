<?php

namespace App;

// Unified Pattern Processing System
require_once 'db_connect.php';
require_once 'PropertyTriggerService.php'; // New trigger service

/**
 * Pattern Processing Registry and Engine
 * Handles all content patterns in a unified way with pluggable handlers
 */
class PatternProcessor {
    private $handlers = [];
    private $pdo;
    private $propertyTriggerService; // Add service instance
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->propertyTriggerService = new \App\PropertyTriggerService($pdo); // Instantiate service
        $this->registerDefaultHandlers();
    }
    
    /**
     * Register a pattern handler
     * @param string $name Handler name
     * @param string $pattern Regex pattern
     * @param callable $handler Handler function
     * @param array $options Handler options
     */
    public function registerHandler($name, $pattern, $handler, $options = []) {
        $this->handlers[$name] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'options' => array_merge([
                'extract_properties' => true,
                'modify_content' => false,
                'priority' => 50
            ], $options)
        ];
    }
    
    /**
     * Process content through all registered handlers
     * @param string $content Content to process
     * @param string $entityType Entity type ('note' or 'page')
     * @param int $entityId Entity ID
     * @param array $context Additional context
     * @return array Results with properties and modified content
     */
    public function processContent($content, $entityType, $entityId, $context = []) {
        error_log("[PATTERN_PROCESSOR_DEBUG] Starting content processing for {$entityType} {$entityId}");
        error_log("[PATTERN_PROCESSOR_DEBUG] Content: " . $content);
        
        $results = [
            'properties' => [],
            'content' => $content,
            'metadata' => []
        ];
        
        // Sort handlers by priority
        $sortedHandlers = $this->handlers;
        uasort($sortedHandlers, function($a, $b) {
            return $a['options']['priority'] <=> $b['options']['priority'];
        });
        
        error_log("[PATTERN_PROCESSOR_DEBUG] Sorted handlers: " . json_encode(array_keys($sortedHandlers)));
        
        foreach ($sortedHandlers as $name => $handler) {
            try {
                error_log("[PATTERN_PROCESSOR_DEBUG] Executing handler: {$name}");
                error_log("[PATTERN_PROCESSOR_DEBUG] Pattern: " . $handler['pattern']);
                
                $handlerResult = $this->executeHandler($name, $handler, $results['content'], $entityType, $entityId, $context);
                
                if ($handlerResult) {
                    error_log("[PATTERN_PROCESSOR_DEBUG] Handler {$name} returned: " . json_encode($handlerResult));
                    
                    // Only merge properties if the handler is configured to extract them
                    if (isset($handlerResult['properties']) && $handler['options']['extract_properties']) {
                        $results['properties'] = array_merge($results['properties'], $handlerResult['properties']);
                        error_log("[PATTERN_PROCESSOR_DEBUG] Merged properties from {$name}: " . json_encode($handlerResult['properties']));
                        error_log("[PATTERN_PROCESSOR_DEBUG] Current total properties: " . json_encode($results['properties']));
                    }
                    
                    // Update content if handler modifies it
                    if (isset($handlerResult['content']) && $handler['options']['modify_content']) {
                        $results['content'] = $handlerResult['content'];
                        error_log("[PATTERN_PROCESSOR_DEBUG] Updated content from {$name}");
                    }
                    
                    // Store metadata
                    if (isset($handlerResult['metadata'])) {
                        $results['metadata'][$name] = $handlerResult['metadata'];
                        error_log("[PATTERN_PROCESSOR_DEBUG] Stored metadata from {$name}");
                    }
                } else {
                    error_log("[PATTERN_PROCESSOR_DEBUG] Handler {$name} returned no results");
                }
            } catch (Exception $e) {
                error_log("[PATTERN_PROCESSOR_ERROR] Handler '{$name}' failed: " . $e->getMessage());
                error_log("[PATTERN_PROCESSOR_ERROR] Stack trace: " . $e->getTraceAsString());
                throw $e; // Re-throw to be caught by caller
            }
        }
        
        error_log("[PATTERN_PROCESSOR_DEBUG] Final results: " . json_encode($results));
        return $results;
    }
    
    /**
     * Execute a single pattern handler
     */
    private function executeHandler($name, $handler, $content, $entityType, $entityId, $context) {
        error_log("[PATTERN_PROCESSOR_DEBUG] Executing handler {$name} with pattern: " . $handler['pattern']);
        error_log("[PATTERN_PROCESSOR_DEBUG] Content to match: " . $content);
        
        if (preg_match_all($handler['pattern'], $content, $matches, PREG_SET_ORDER)) {
            error_log("[PATTERN_PROCESSOR_DEBUG] Found " . count($matches) . " matches for handler {$name}");
            error_log("[PATTERN_PROCESSOR_DEBUG] Matches: " . json_encode($matches));
            return call_user_func($handler['handler'], $matches, $content, $entityType, $entityId, $context, $this->pdo);
        }
        error_log("[PATTERN_PROCESSOR_DEBUG] No matches found for handler {$name}");
        return null;
    }
    
    /**
     * Register default pattern handlers
     */
    private function registerDefaultHandlers() {
        // Property patterns: {key::value}
        $this->registerHandler('properties', '/\{([a-zA-Z0-9_\.-]+)(:{2,})([^}]+)\}/m', 
            [$this, 'handleProperties'], 
            ['priority' => 10]
        );
        
        // Page link patterns: [[page_name]]
        $this->registerHandler('page_links', '/\[\[([^\]]+)\]\]/', 
            [$this, 'handlePageLinks'], 
            ['priority' => 20]
        );
        
        // Task status patterns: TODO, DONE, CANCELLED, DOING, SOMEDAY, WAITING, NLR at start of line
        // Dynamically generate the regex for task status patterns
        if (defined('TASK_STATES') && is_array(TASK_STATES) && !empty(TASK_STATES)) {
            $taskStatusPattern = '/^(' . implode('|', TASK_STATES) . ')\s+(.*)$/m';
        } else {
            // Fallback to a default pattern if TASK_STATES is not defined or empty
            error_log("[PATTERN_PROCESSOR_WARNING] TASK_STATES not defined or empty. Using default task status pattern.");
            $taskStatusPattern = '/^(TODO)\s+(.*)$/m';
        }
        $this->registerHandler('task_status', $taskStatusPattern, 
            [$this, 'handleTaskStatus'], 
            ['priority' => 5]
        );
        
        // Block reference patterns: !{{block_ref}}
        $this->registerHandler('block_refs', '/!{{([^}]+)}}/', 
            [$this, 'handleBlockReferences'], 
            ['priority' => 30]
        );

        // SQL query patterns: SQL{query}
        $this->registerHandler('sql_queries', '/SQL\{([^}]+)\}/',
            [$this, 'handleSqlQueries'],
            [
                'priority' => 40,
                'extract_properties' => true,
                'modify_content' => false
            ]
        );

        // External URL patterns: http:// or https:// URLs
        $this->registerHandler('external_urls', '/(https?:\/\/[^\s<>"]+|www\.[^\s<>"]+)/',
            [$this, 'handleExternalUrls'],
            [
                'priority' => 25,
                'extract_properties' => true,
                'modify_content' => false
            ]
        );
    }
    
    /**
     * Handler for property patterns {key::value}
     */
    public function handleProperties($matches, $content, $entityType, $entityId, $context, $pdo) {
        error_log("[PATTERN_PROCESSOR_DEBUG] Processing property matches with new regex: " . json_encode($matches));
        
        // Use an array to preserve all properties, including duplicates
        $properties = [];
        
        foreach ($matches as $match) {
            // $match[0] is the full matched string e.g. "{key::value}" or "{key:::value}"
            // $match[1] is the key
            // $match[2] is the colons (e.g., "::", ":::")
            // $match[3] is the value
            
            $propertyName = trim($match[1]);
            $colons = $match[2];
            $propertyValue = trim($match[3]);
            $weight = strlen($colons);
            
            error_log("[PATTERN_PROCESSOR_DEBUG] Processing property: {$propertyName}, Colons: {$colons}, Value: {$propertyValue}, Weight: {$weight}");
            
            // It's possible $propertyName or $propertyValue could be empty if regex allows, though current one requires them.
            if (!empty($propertyName)) { // Value can be empty
                // Store all properties in array - preserve duplicates
                $properties[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue,
                    'weight' => $weight, // Store the calculated weight
                    'type' => 'property', // Retain type if useful, or simplify
                    'raw_match' => $match[0]
                ];
                error_log("[PATTERN_PROCESSOR_DEBUG] Stored property: " . json_encode(end($properties)));
            } else {
                error_log("[PATTERN_PROCESSOR_DEBUG] Skipping empty property name.");
            }
        }
        
        // Return all properties as indexed array
        $result = ['properties' => $properties];
        error_log("[PATTERN_PROCESSOR_DEBUG] Returning properties: " . json_encode($result));
        return $result;
    }
    
    /**
     * Handler for page link patterns [[page_name]]
     */
    public function handlePageLinks($matches, $content, $entityType, $entityId, $context, $pdo) {
        $properties = [];
        $linkedPages = [];
        
        foreach ($matches as $match) {
            $pageName = trim($match[1]);
            
            if (!empty($pageName) && !in_array($pageName, $linkedPages)) {
                $properties[] = [
                    'name' => 'links_to_page',
                    'value' => $pageName,
                    'type' => 'page_link',
                    'raw_match' => $match[0],
                    'weight' => defined('SPECIAL_STATE_WEIGHTS') && isset(SPECIAL_STATE_WEIGHTS['LINK']) ? SPECIAL_STATE_WEIGHTS['LINK'] : 4

                ];
                $linkedPages[] = $pageName;
            }
        }
        
        return ['properties' => $properties];
    }
    
    /**
     * Handler for task status patterns
     * Supports the following statuses:
     * - TODO: Task to be done
     * - DOING: Task in progress
     * - SOMEDAY: Future task
     * - DONE: Completed task
     * - CANCELLED: Cancelled task
     * - WAITING: Task waiting on something
     * - NLR: No longer required task
     */
    public function handleTaskStatus($matches, $content, $entityType, $entityId, $context, $pdo) {
        require_once __DIR__ . '/../config.php'; // Ensure config is loaded for SPECIAL_STATE_WEIGHTS
        $properties = [];
        $allTaskMetadata = []; // Accumulate metadata for all tasks
        
        foreach ($matches as $match) {
            $status = $match[1]; // TODO, DONE, CANCELLED, DOING, SOMEDAY, WAITING, NLR
            $taskContent = trim($match[2]);

            // Save status as a property with weight
            $properties[] = [
                'name' => 'status',
                'value' => $status,
                'type' => 'task_status',
                'raw_match' => $match[0],
                'weight' => defined('SPECIAL_STATE_WEIGHTS') && isset(SPECIAL_STATE_WEIGHTS['TASK']) ? SPECIAL_STATE_WEIGHTS['TASK'] : 4
            ];
            // Add done_at timestamp for DONE tasks
            if ($status === 'DONE') {
                $properties[] = [
                    'name' => 'done_at',
                    'value' => date('Y-m-d H:i:s'),
                    'type' => 'timestamp',
                    'raw_match' => $match[0],
                    'weight' => defined('SPECIAL_STATE_WEIGHTS') && isset(SPECIAL_STATE_WEIGHTS['DONE_AT']) ? SPECIAL_STATE_WEIGHTS['DONE_AT'] : 4
                ];
            }
            // Store metadata for each task
            $allTaskMetadata[] = [
                'status' => $status,
                'content' => $taskContent,
                'raw_match' => $match[0],
                'done_at' => ($status === 'DONE' ? date('Y-m-d H:i:s') : null)
            ];
        }
        return [
            'properties' => $properties,
            'metadata' => ['tasks' => $allTaskMetadata]
        ];
    }
    
    /**
     * Handler for block reference patterns
     */
    public function handleBlockReferences($matches, $content, $entityType, $entityId, $context, $pdo) {
        $properties = [];
        $metadata = [];
        
        foreach ($matches as $match) {
            $blockRef = trim($match[1]);
            
            if (!empty($blockRef)) {
                $properties[] = [
                    'name' => 'references_block',
                    'value' => $blockRef,
                    'type' => 'block_reference',
                    'raw_match' => $match[0],
                    'weight' => defined('SPECIAL_STATE_WEIGHTS') && isset(SPECIAL_STATE_WEIGHTS['TRANSCLUSION']) ? SPECIAL_STATE_WEIGHTS['TRANSCLUSION'] : 4
                ];
            }
        }
        
        return ['properties' => $properties];
    }

    /**
     * Handler for SQL query patterns SQL{query}
     */
    private function handleSqlQueries($matches, $content, $entityType, $entityId, $context, $pdo) {
        error_log("[PATTERN_PROCESSOR_DEBUG] Processing SQL query matches: " . json_encode($matches));
        $properties = [];

        foreach ($matches as $match) {
            $sqlQuery = trim($match[1]); // Captured SQL query
            
            if (!empty($sqlQuery)) {
                error_log("[PATTERN_PROCESSOR_DEBUG] Processing SQL query: " . $sqlQuery);
                $properties[] = [
                    'name' => 'sql_query', // Or a more descriptive name like 'dynamic_sql_query'
                    'value' => $sqlQuery,
                    'type' => 'sql_query',
                    'raw_match' => $match[0], // The full SQL{...} match
                    'weight' => defined('SPECIAL_STATE_WEIGHTS') && isset(SPECIAL_STATE_WEIGHTS['SQL']) ? SPECIAL_STATE_WEIGHTS['SQL'] : 4
                ];
                error_log("[PATTERN_PROCESSOR_DEBUG] Stored SQL query property: " . json_encode(end($properties)));
            } else {
                error_log("[PATTERN_PROCESSOR_DEBUG] Skipping empty SQL query.");
            }
        }
        
        $result = ['properties' => $properties];
        error_log("[PATTERN_PROCESSOR_DEBUG] Returning SQL query properties: " . json_encode($result));
        return $result;
    }
    
    /**
     * Handler for external URL patterns
     * Matches URLs starting with http:// or https://
     */
    private function handleExternalUrls($matches, $content, $entityType, $entityId, $context, $pdo) {
        error_log("[PATTERN_PROCESSOR_DEBUG] Processing external URL matches: " . json_encode($matches));
        $properties = [];

        foreach ($matches as $match) {
            $url = trim($match[0]); // The full matched URL
            
            if (!empty($url)) {
                // Ensure URL starts with http:// or https://
                if (strpos($url, 'www.') === 0) {
                    $url = 'https://' . $url;
                }
                
                error_log("[PATTERN_PROCESSOR_DEBUG] Processing URL: " . $url);
                $properties[] = [
                    'name' => 'external_url',
                    'value' => $url,
                    'type' => 'url',
                    'raw_match' => $match[0],
                    'weight' => defined('SPECIAL_STATE_WEIGHTS') && isset(SPECIAL_STATE_WEIGHTS['URL']) ? SPECIAL_STATE_WEIGHTS['URL'] : 4
                ];
                error_log("[PATTERN_PROCESSOR_DEBUG] Stored URL property: " . json_encode(end($properties)));
            } else {
                error_log("[PATTERN_PROCESSOR_DEBUG] Skipping empty URL.");
            }
        }
        
        $result = ['properties' => $properties];
        error_log("[PATTERN_PROCESSOR_DEBUG] Returning URL properties: " . json_encode($result));
        return $result;
    }
    
    /**
     * Save processed properties to database
     * Note: This method assumes the caller has already deleted any existing properties
     * that should be removed. It will use PROPERTY_WEIGHTS['update_behavior'] for each property group to decide between INSERT (append) and REPLACE (replace)
     */
    public function saveProperties($properties, $entityType, $entityId) {
        require_once __DIR__ . '/../config.php'; // Ensure PROPERTY_WEIGHTS is loaded
        error_log("[PATTERN_PROCESSOR_DEBUG] Saving properties for {$entityType} {$entityId}: " . json_encode($properties));
        // Group properties by name for efficient processing
        $propertiesByName = [];
        foreach ($properties as $property) {
            $name = $property['name'];
            if (!isset($propertiesByName[$name])) {
                $propertiesByName[$name] = [];
            }
            $propertiesByName[$name][] = $property;
        }
        error_log("[PATTERN_PROCESSOR_DEBUG] Grouped properties: " . json_encode($propertiesByName));
        // Process each property group
        foreach ($propertiesByName as $name => $propertyGroup) {
            try {
                $weight = isset($propertyGroup[0]['weight']) ? $propertyGroup[0]['weight'] : 3;
                $updateBehavior = (defined('PROPERTY_WEIGHTS') && isset(PROPERTY_WEIGHTS[$weight]['update_behavior']))
                    ? PROPERTY_WEIGHTS[$weight]['update_behavior']
                    : 'replace';
                $idColumn = ($entityType === 'note') ? 'note_id' : 'page_id';
                $otherIdColumn = ($entityType === 'note') ? 'page_id' : 'note_id';
                if ($updateBehavior === 'append') {
                    $sql = "INSERT INTO Properties ({$idColumn}, {$otherIdColumn}, name, value, weight) VALUES (?, NULL, ?, ?, ?)";
                    error_log("[PATTERN_PROCESSOR_DEBUG] Using SQL for property '{$name}': " . trim($sql));
                    $stmt = $this->pdo->prepare($sql);
                    foreach ($propertyGroup as $property) {
                        $params = [
                            $entityId,
                            $property['name'],
                            $property['value'],
                            $weight
                        ];
                        error_log("[PATTERN_PROCESSOR_DEBUG] Executing with params: " . json_encode($params));
                        $stmt->execute($params);
                        if ($property === reset($propertyGroup)) {
                            error_log("[PATTERN_PROCESSOR_DEBUG] Dispatching trigger for {$name}");
                            $this->propertyTriggerService->dispatch($entityType, $entityId, $name, $property['value']);
                        }
                    }
                } else { // replace
                    // First, delete all existing properties for this entity and name
                    $deleteSql = "DELETE FROM Properties WHERE {$idColumn} = ? AND name = ?";
                    $deleteStmt = $this->pdo->prepare($deleteSql);
                    $deleteStmt->execute([$entityId, $name]);
                    error_log("[PATTERN_PROCESSOR_DEBUG] Deleted existing properties for {$idColumn}={$entityId}, name='{$name}'");
                    
                    // For each property in the group, insert it with its individual weight
                    foreach ($propertyGroup as $property) {
                        $propertyWeight = isset($property['weight']) ? $property['weight'] : 3;
                        
                        // Insert new property
                        $insertSql = "INSERT INTO Properties ({$idColumn}, {$otherIdColumn}, name, value, weight, created_at, updated_at) 
                                    VALUES (?, NULL, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                        $insertStmt = $this->pdo->prepare($insertSql);
                        $insertStmt->execute([$entityId, $property['name'], $property['value'], $propertyWeight]);
                        
                        if ($insertStmt->rowCount() > 0) {
                            error_log("[PATTERN_PROCESSOR_DEBUG] Inserted property '{$property['name']}' with value '{$property['value']}' and weight {$propertyWeight}");
                        } else {
                            error_log("[PATTERN_PROCESSOR_DEBUG] Failed to insert property '{$property['name']}'");
                        }
                        
                        // Dispatch trigger for the first property in the group
                        if ($property === reset($propertyGroup)) {
                            error_log("[PATTERN_PROCESSOR_DEBUG] Dispatching trigger for {$name}");
                            $this->propertyTriggerService->dispatch($entityType, $entityId, $name, $property['value']);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("[PATTERN_PROCESSOR_ERROR] Error saving property group '$name' for $entityType $entityId: " . $e->getMessage());
                error_log("[PATTERN_PROCESSOR_ERROR] Stack trace: " . $e->getTraceAsString());
                throw $e; // Re-throw to allow transaction rollback
            }
        }
    }
}

/**
 * Create global pattern processor instance
 */
function getPatternProcessor() {
    static $processor = null;
    if ($processor === null) {
        $processor = new \App\PatternProcessor(get_db_connection());
    }
    return $processor;
} 