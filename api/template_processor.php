<?php

namespace App;

class TemplateProcessor {
    private $template_dir;
    private $placeholders = [
        'date' => ['format' => 'Y-m-d', 'callback' => 'date'],
        'datetime' => ['format' => 'Y-m-d H:i:s', 'callback' => 'date'],
        'time' => ['format' => 'H:i:s', 'callback' => 'date'],
        'year' => ['format' => 'Y', 'callback' => 'date'],
        'month' => ['format' => 'm', 'callback' => 'date'],
        'day' => ['format' => 'd', 'callback' => 'date'],
        'weekday' => ['format' => 'l', 'callback' => 'date'],
        'timestamp' => ['callback' => 'time'],
        'random' => ['callback' => 'rand'],
        'uuid' => ['callback' => 'uniqid']
    ];

    public function __construct($type = 'note') {
        if (!in_array($type, ['note', 'page'])) {
            throw new Exception("Invalid template type: $type");
        }

        $this->template_dir = __DIR__ . '/../assets/template/' . $type;
        
        if (!file_exists($this->template_dir)) {
            if (!mkdir($this->template_dir, 0777, true)) {
                throw new Exception("Failed to create template directory: " . $this->template_dir);
            }
        }
        
        // Verify directory is readable
        if (!is_readable($this->template_dir)) {
            throw new Exception("Template directory is not readable: " . $this->template_dir);
        }
    }

    /**
     * Get list of available templates
     * @return array List of template names without extension
     */
    public function getAvailableTemplates() {
        $templates = [];
        
        if (!is_dir($this->template_dir)) {
            return $templates;
        }
        
        if ($handle = opendir($this->template_dir)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != ".." && pathinfo($entry, PATHINFO_EXTENSION) == 'php') {
                    $templates[] = pathinfo($entry, PATHINFO_FILENAME);
                }
            }
            closedir($handle);
        }
        
        return $templates;
    }

    /**
     * Process a template with given data
     * @param string $template_name Name of the template without extension
     * @param array $data Additional data to pass to the template
     * @return string Processed template content
     */
    public function processTemplate($template_name, $data = []) {
        $template_path = $this->template_dir . '/' . $template_name . '.php';
        
        if (!file_exists($template_path)) {
            throw new Exception("Template not found: $template_name");
        }
        
        if (!is_readable($template_path)) {
            throw new Exception("Template not readable: $template_name");
        }

        try {
            // Read template content as text (not as PHP)
            $content = file_get_contents($template_path);
            
            if ($content === false) {
                throw new Exception("Failed to read template content");
            }

            // Process placeholders
            $content = $this->processPlaceholders($content);
            
            // Process any custom data
            foreach ($data as $key => $value) {
                $content = str_replace('{{' . $key . '}}', $value, $content);
            }

            return $content;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Process placeholders in the template content
     * @param string $content Template content
     * @return string Processed content
     */
    private function processPlaceholders($content) {
        foreach ($this->placeholders as $placeholder => $config) {
            $pattern = '/{{' . $placeholder . '(?::([^}]+))?}}/';
            $content = preg_replace_callback($pattern, function($matches) use ($config) {
                if (isset($matches[1])) {
                    // Custom format provided
                    return $config['callback']($matches[1]);
                } else if (isset($config['format'])) {
                    // Use default format
                    return $config['callback']($config['format']);
                } else {
                    // No format needed
                    return $config['callback']();
                }
            }, $content);
        }
        return $content;
    }

    /**
     * Add a new template
     * @param string $name Template name
     * @param string $content Template content
     * @return bool Success status
     */
    public function addTemplate($name, $content) {
        $template_path = $this->template_dir . '/' . $name . '.php';
        return file_put_contents($template_path, $content) !== false;
    }

    /**
     * Delete a template
     * @param string $name Template name
     * @return bool Success status
     */
    public function deleteTemplate($name) {
        $template_path = $this->template_dir . '/' . $name . '.php';
        if (file_exists($template_path)) {
            return unlink($template_path);
        }
        return false;
    }
} 