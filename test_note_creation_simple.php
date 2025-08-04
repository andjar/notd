<?php
// Simple test to isolate the note creation failure
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Note Creation Test</h1>";

try {
    require_once 'api/db_connect.php';
    require_once 'api/DataManager.php';
    require_once 'api/uuid_utils.php';
    
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Test 1: Generate UUID
    $testUuid = \App\UuidUtils::generateUuidV7();
    echo "<p><strong>Generated UUID:</strong> $testUuid</p>";
    
    // Test 2: Check if page exists (we need a valid page_id)
    $stmt = $pdo->query("SELECT id FROM Pages LIMIT 1");
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$page) {
        echo "<p style='color: red;'>❌ No pages found in database</p>";
        exit;
    }
    
    $pageId = $page['id'];
    echo "<p><strong>Using page ID:</strong> $pageId</p>";
    
    // Test 3: Try simple note insertion without properties
    echo "<h3>Testing Simple Note Insertion</h3>";
    
    $noteId = \App\UuidUtils::generateUuidV7();
    $content = 'Test note content';
    
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
    $result = $stmt->execute([$noteId, $pageId, $content]);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Simple note insertion successful</p>";
        
        // Test 4: Try to fetch the note with DataManager
        echo "<h3>Testing DataManager.getNoteById</h3>";
        
        $note = $dataManager->getNoteById($noteId, false, false);
        
        if ($note) {
            echo "<p style='color: green;'>✅ DataManager.getNoteById successful</p>";
            echo "<pre>" . json_encode($note, JSON_PRETTY_PRINT) . "</pre>";
        } else {
            echo "<p style='color: red;'>❌ DataManager.getNoteById failed</p>";
        }
        
        // Clean up
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        echo "<p style='color: green;'>✅ Test data cleaned up</p>";
        
    } else {
        echo "<p style='color: red;'>❌ Simple note insertion failed</p>";
    }
    
    // Test 5: Test the exact batch operation logic
    echo "<h3>Testing Batch Operation Logic</h3>";
    
    $noteId2 = \App\UuidUtils::generateUuidV7();
    $content2 = 'Test note with properties {test::value}';
    
    // Step 1: Create the note record
    $sqlFields = ['id', 'page_id', 'content'];
    $sqlParams = [':id' => $noteId2, ':page_id' => $pageId, ':content' => $content2];
    
    $sql = "INSERT INTO Notes (" . implode(', ', $sqlFields) . ") VALUES (:" . implode(', :', $sqlFields) . ")";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($sqlParams);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Batch-style note insertion successful</p>";
        
        // Step 2: Test property indexing (this might be where the error occurs)
        echo "<h4>Testing Property Indexing</h4>";
        
        if (trim($content2) !== '') {
            try {
                require_once 'api/PatternProcessor.php';
                $patternProcessor = new \App\PatternProcessor($pdo);
                
                $processedData = $patternProcessor->processContent($content2, 'note', $noteId2, ['pdo' => $pdo]);
                echo "<p style='color: green;'>✅ Property processing successful</p>";
                
                if (!empty($processedData['properties'])) {
                    $patternProcessor->saveProperties($processedData['properties'], 'note', $noteId2);
                    echo "<p style='color: green;'>✅ Property saving successful</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Property processing failed: " . $e->getMessage() . "</p>";
            }
        }
        
        // Clean up
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$noteId2]);
        echo "<p style='color: green;'>✅ Test data cleaned up</p>";
        
    } else {
        echo "<p style='color: red;'>❌ Batch-style note insertion failed</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 