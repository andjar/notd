<?php
// Unified Pattern Processing System
require_once 'db_connect.php';
require_once 'property_triggers.php';

/**
 * Pattern Processing Registry and Engine
 * Handles all content patterns in a unified way with pluggable handlers
 */
class PatternProcessor {
    private $handlers = [];
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
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
        $this->registerHandler('properties', '/\{([^:}]+)::([^}]+)\}/', 
            [$this, 'handleProperties'], 
            ['priority' => 10]
        );
        
        // Page link patterns: [[page_name]]
        $this->registerHandler('page_links', '/\[\[([^\]]+)\]\]/', 
            [$this, 'handlePageLinks'], 
            ['priority' => 20]
        );
        
        // Task status patterns: TODO, DONE, CANCELLED, DOING, SOMEDAY, WAITING, NLR at start of line
        $this->registerHandler('task_status', '/^(TODO|DONE|CANCELLED|DOING|SOMEDAY|WAITING|NLR)\s+(.*)$/m', 
            [$this, 'handleTaskStatus'], 
            ['priority' => 5]
        );
        
        // Block reference patterns: !{{block_ref}}
        $this->registerHandler('block_refs', '/!{{([^}]+)}}/', 
            [$this, 'handleBlockReferences'], 
            ['priority' => 30]
        );
    }
    
    /**
     * Handler for property patterns {key::value}
     */
    public function handleProperties($matches, $content, $entityType, $entityId, $context, $pdo) {
        error_log("[PATTERN_PROCESSOR_DEBUG] Processing property matches: " . json_encode($matches));
        
        // Use an associative array to only keep the last value for each property name
        $propertiesByName = [];
        
        foreach ($matches as $match) {
            $propertyName = trim($match[1]);
            $propertyValue = trim($match[2]);
            
            error_log("[PATTERN_PROCESSOR_DEBUG] Processing property: {$propertyName}::{$propertyValue}");
            
            if (!empty($propertyName) && !empty($propertyValue)) {
                // Store in associative array - later values will overwrite earlier ones
                $propertiesByName[$propertyName] = [
                    'name' => $propertyName,
                    'value' => $propertyValue,
                    'type' => 'property',
                    'raw_match' => $match[0]
                ];
                error_log("[PATTERN_PROCESSOR_DEBUG] Stored property: " . json_encode($propertiesByName[$propertyName]));
            } else {
                error_log("[PATTERN_PROCESSOR_DEBUG] Skipping empty property name or value");
            }
        }
        
        // Convert back to indexed array
        $result = ['properties' => array_values($propertiesByName)];
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
                    'raw_match' => $match[0]
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
        $properties = [];
        $allTaskMetadata = []; // Accumulate metadata for all tasks
        
        foreach ($matches as $match) {
            $status = $match[1]; // TODO, DONE, CANCELLED, DOING, SOMEDAY, WAITING, NLR
            $taskContent = trim($match[2]);
            
            // Save status as an internal property
            $properties[] = [
                'name' => 'status',
                'value' => $status,
                'type' => 'task_status',
                'raw_match' => $match[0],
                'internal' => 1 // Mark as internal property
            ];
            
            // Add done_at timestamp for DONE tasks
            if ($status === 'DONE') {
                $properties[] = [
                    'name' => 'done_at',
                    'value' => date('Y-m-d H:i:s'),
                    'type' => 'timestamp',
                    'raw_match' => $match[0],
                    'internal' => 1 // Mark as internal property
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
                    'raw_match' => $match[0]
                ];
            }
        }
        
        return ['properties' => $properties];
    }
    
    /**
     * Save processed properties to database
     * Note: This method assumes the caller has already deleted any existing properties
     * that should be removed. It will use REPLACE INTO to handle both insert and update
     */
    public function saveProperties($properties, $entityType, $entityId) {
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
                // Determine internal status once per property name
                $internal = isset($propertyGroup[0]['internal']) ? $propertyGroup[0]['internal'] : 0;
                error_log("[PATTERN_PROCESSOR_DEBUG] Processing property group '{$name}' with internal={$internal}");
                
                // For status properties, we want to keep history, so use INSERT
                // For done_at and other properties, use REPLACE to handle both insert and update
                $isStatusProperty = ($name === 'status');
                
                if ($isStatusProperty) {
                    $sql = "
                        INSERT INTO Properties (note_id, page_id, name, value, internal)
                        VALUES (?, NULL, ?, ?, ?)
                    ";
                } else {
                    $sql = "
                        REPLACE INTO Properties (note_id, page_id, name, value, internal)
                        VALUES (?, NULL, ?, ?, ?)
                    ";
                }
                
                error_log("[PATTERN_PROCESSOR_DEBUG] Using SQL: " . $sql);
                $stmt = $this->pdo->prepare($sql);
                
                foreach ($propertyGroup as $property) {
                    $params = [
                        $entityId,
                        $property['name'],
                        $property['value'],
                        $internal
                    ];
                    
                    error_log("[PATTERN_PROCESSOR_DEBUG] Executing with params: " . json_encode($params));
                    $stmt->execute($params);
                    
                    // Dispatch triggers only once per property name
                    if ($property === reset($propertyGroup)) {
                        error_log("[PATTERN_PROCESSOR_DEBUG] Dispatching trigger for {$name}");
                        dispatchPropertyTriggers($this->pdo, $entityType, $entityId, $name, $property['value']);
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
        $processor = new PatternProcessor(get_db_connection());
    }
    return $processor;
} 