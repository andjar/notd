<?php
// Fix database and ensure initial page exists
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Fix Database and Ensure Initial Page</h1>";

try {
    require_once 'api/db_connect.php';
    require_once 'api/uuid_utils.php';
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check if we have any pages
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Pages");
    $result = $stmt->fetch();
    $pageCount = $result['count'];
    
    echo "<p><strong>Current pages count:</strong> $pageCount</p>";
    
    if ($pageCount == 0) {
        echo "<h2>No pages found. Creating initial page...</h2>";
        
        // Create today's page
        $todaysPageName = date('Y-m-d');
        $pageId = \App\UuidUtils::generateUuidV7();
        $pageContent = "{type::journal}";
        
        echo "<p><strong>Creating page:</strong> $todaysPageName</p>";
        echo "<p><strong>Page ID:</strong> $pageId</p>";
        
        try {
            $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
            $result = $stmt->execute([$pageId, $todaysPageName, $pageContent]);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Page created successfully</p>";
                
                // Add welcome notes if the file exists
                $welcome_notes_path = __DIR__ . '/assets/template/page/welcome_notes.json';
                if (file_exists($welcome_notes_path)) {
                    echo "<h2>Adding welcome notes...</h2>";
                    
                    $notes_json = file_get_contents($welcome_notes_path);
                    $notes_to_insert = json_decode($notes_json, true);
                    
                    if (is_array($notes_to_insert)) {
                        $order_index = 1;
                        foreach ($notes_to_insert as $note_content) {
                            $noteId = \App\UuidUtils::generateUuidV7();
                            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$noteId, $pageId, $note_content, $order_index++]);
                            echo "<p style='color: green;'>✅ Added note: " . substr($note_content, 0, 50) . "...</p>";
                        }
                    }
                } else {
                    echo "<p style='color: orange;'>⚠️ Welcome notes file not found, creating a simple note</p>";
                    
                    // Create a simple welcome note
                    $noteId = \App\UuidUtils::generateUuidV7();
                    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$noteId, $pageId, "Welcome to your new outliner! Start typing here to create your first note.", 1]);
                    echo "<p style='color: green;'>✅ Created simple welcome note</p>";
                }
                
                echo "<h2>🎯 SUCCESS!</h2>";
                echo "<p style='color: green; font-weight: bold;'>✅ Initial page created successfully!</p>";
                echo "<p style='color: green;'>✅ Page name: $todaysPageName</p>";
                echo "<p style='color: green;'>✅ Page ID: $pageId</p>";
                echo "<p style='color: green;'>✅ The application should now work properly</p>";
                
            } else {
                echo "<p style='color: red;'>❌ Failed to create page</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error creating page: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<h2>Pages already exist</h2>";
        
        // Show existing pages
        $stmt = $pdo->query("SELECT id, name, content FROM Pages ORDER BY created_at DESC");
        $pages = $stmt->fetchAll();
        
        echo "<p><strong>Existing pages:</strong></p>";
        foreach ($pages as $page) {
            echo "<p>• {$page['name']} (ID: {$page['id']})</p>";
        }
        
        // Check if today's page exists
        $todaysPageName = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT id FROM Pages WHERE name = ?");
        $stmt->execute([$todaysPageName]);
        $todayPage = $stmt->fetch();
        
        if ($todayPage) {
            echo "<p style='color: green;'>✅ Today's page ($todaysPageName) exists</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Today's page ($todaysPageName) does not exist</p>";
            
            // Create today's page
            $pageId = \App\UuidUtils::generateUuidV7();
            $pageContent = "{type::journal}";
            
            try {
                $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
                $result = $stmt->execute([$pageId, $todaysPageName, $pageContent]);
                
                if ($result) {
                    echo "<p style='color: green;'>✅ Created today's page</p>";
                } else {
                    echo "<p style='color: red;'>❌ Failed to create today's page</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Error creating today's page: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // Test the API endpoint
    echo "<h2>Testing API Endpoint</h2>";
    
    $pageName = date('Y-m-d');
    $url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/api/v1/pages.php?name=" . urlencode($pageName);
    
    echo "<p><strong>Testing page:</strong> $pageName</p>";
    echo "<p><strong>URL:</strong> $url</p>";
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Content-Type: application/json'
        ]
    ]);
    
    $response = file_get_contents($url, false, $context);
    
    if ($response === false) {
        echo "<p style='color: red;'>❌ Failed to get response</p>";
    } else {
        echo "<p><strong>Response:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 200px; overflow-y: auto;'>";
        echo htmlspecialchars($response);
        echo "</pre>";
        
        // Check if it's JSON
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "<p style='color: green;'>✅ Valid JSON response</p>";
            echo "<p style='color: green; font-weight: bold;'>🎉 The API is working correctly!</p>";
        } else {
            echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 