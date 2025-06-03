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
        
        foreach ($sortedHandlers as $name => $handler) {
            try {
                $handlerResult = $this->executeHandler($name, $handler, $results['content'], $entityType, $entityId, $context);
                
                if ($handlerResult) {
                    // Merge properties
                    if (isset($handlerResult['properties'])) {
                        $results['properties'] = array_merge($results['properties'], $handlerResult['properties']);
                    }
                    
                    // Update content if handler modifies it
                    if (isset($handlerResult['content']) && $handler['options']['modify_content']) {
                        $results['content'] = $handlerResult['content'];
                    }
                    
                    // Store metadata
                    if (isset($handlerResult['metadata'])) {
                        $results['metadata'][$name] = $handlerResult['metadata'];
                    }
                }
            } catch (Exception $e) {
                error_log("Pattern handler '$name' failed: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Execute a single pattern handler
     */
    private function executeHandler($name, $handler, $content, $entityType, $entityId, $context) {
        if (preg_match_all($handler['pattern'], $content, $matches, PREG_SET_ORDER)) {
            return call_user_func($handler['handler'], $matches, $content, $entityType, $entityId, $context, $this->pdo);
        }
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
        
        // Task status patterns: TODO, DONE, CANCELLED at start of line
        $this->registerHandler('task_status', '/^(TODO|DONE|CANCELLED)\s+(.*)$/m', 
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
        $properties = [];
        
        foreach ($matches as $match) {
            $propertyName = trim($match[1]);
            $propertyValue = trim($match[2]);
            
            if (!empty($propertyName) && !empty($propertyValue)) {
                $properties[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue,
                    'type' => 'property',
                    'raw_match' => $match[0]
                ];
            }
        }
        
        return ['properties' => $properties];
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
     */
    public function handleTaskStatus($matches, $content, $entityType, $entityId, $context, $pdo) {
        $properties = [];
        $metadata = [];
        
        foreach ($matches as $match) {
            $status = $match[1]; // TODO, DONE, CANCELLED
            $taskContent = trim($match[2]);
            
            $properties[] = [
                'name' => 'status',
                'value' => $status,
                'type' => 'task_status',
                'raw_match' => $match[0]
            ];
            
            // Add done_at timestamp for DONE tasks
            if ($status === 'DONE') {
                $properties[] = [
                    'name' => 'done_at',
                    'value' => date('Y-m-d H:i:s'),
                    'type' => 'timestamp',
                    'raw_match' => $match[0]
                ];
            }
            
            $metadata['task_status'] = $status;
            $metadata['task_content'] = $taskContent;
        }
        
        return [
            'properties' => $properties,
            'metadata' => $metadata
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
     */
    public function saveProperties($properties, $entityType, $entityId) {
        foreach ($properties as $property) {
            try {
                if ($entityType === 'page') {
                    $stmt = $this->pdo->prepare("
                        REPLACE INTO Properties (page_id, note_id, name, value, internal)
                        VALUES (?, NULL, ?, ?, 0)
                    ");
                } else {
                    $stmt = $this->pdo->prepare("
                        REPLACE INTO Properties (note_id, page_id, name, value, internal)
                        VALUES (?, NULL, ?, ?, 0)
                    ");
                }
                
                $stmt->execute([$entityId, $property['name'], $property['value']]);
                
                // Dispatch triggers
                dispatchPropertyTriggers($this->pdo, $entityType, $entityId, $property['name'], $property['value']);
                
            } catch (Exception $e) {
                error_log("Error saving property {$property['name']} for $entityType $entityId: " . $e->getMessage());
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