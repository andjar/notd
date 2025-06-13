<?php

/**
 * Utility class for handling application-wide properties and configurations.
 */
class PropertyUtils {

    /**
     * Finds all available extensions by scanning the extensions directory,
     * then filters them based on the ACTIVE_EXTENSIONS constant from config.php.
     *
     * @return array An array of active extension details, each containing 'name' and 'featherIcon'.
     */
    public static function getActiveExtensionDetails() {
        // Ensure the ACTIVE_EXTENSIONS constant is defined and is an array.
        if (!defined('ACTIVE_EXTENSIONS') || !is_array(ACTIVE_EXTENSIONS)) {
            return [];
        }

        $active_extensions_list = ACTIVE_EXTENSIONS;
        $extensions_dir = __DIR__ . '/../extensions';
        $found_extensions = [];

        // Scan the extensions directory for subdirectories.
        if (is_dir($extensions_dir)) {
            $subdirectories = glob($extensions_dir . '/*', GLOB_ONLYDIR);

            foreach ($subdirectories as $dir) {
                $extension_name = basename($dir);
                $config_path = $dir . '/config.json';

                // Check if the discovered extension is in the active list.
                if (in_array($extension_name, $active_extensions_list) && file_exists($config_path)) {
                    $config_content = file_get_contents($config_path);
                    $config_data = json_decode($config_content, true);

                    // If the config is valid and contains a featherIcon, add it to our list.
                    if (json_last_error() === JSON_ERROR_NONE && isset($config_data['featherIcon'])) {
                        $found_extensions[] = [
                            'name' => $extension_name,
                            'featherIcon' => $config_data['featherIcon']
                        ];
                    }
                }
            }
        }

        return $found_extensions;
    }
}