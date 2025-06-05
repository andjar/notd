<?php
require_once 'config.php';
require_once 'api/db_connect.php';
// Required for _updateOrAddPropertyAndDispatchTriggers and its dependencies
require_once 'api/properties.php'; 

$pdo = get_db_connection();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_definition':
                    $name = trim($_POST['name']);
                    $internal = isset($_POST['internal']) ? 1 : 0;
                    $description = trim($_POST['description']);
                    $autoApply = isset($_POST['auto_apply']) ? 1 : 0;
                    
                    if (empty($name)) {
                        throw new Exception('Property name is required');
                    }
                    
                    // Ensure the core function is available
                    if (!function_exists('_updateOrAddPropertyAndDispatchTriggers')) {
                        throw new Exception('Core property update function _updateOrAddPropertyAndDispatchTriggers is missing. Ensure api/properties.php is included correctly.');
                    }

                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("
                        INSERT OR REPLACE INTO PropertyDefinitions (name, internal, description, auto_apply)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $internal, $description, $autoApply]);
                    
                    $processedCount = 0;
                    if ($autoApply) {
                        // Fetch existing properties with this name that need their internal status updated
                        $propStmt = $pdo->prepare("
                            SELECT id, value, note_id, page_id 
                            FROM Properties 
                            WHERE name = ? AND internal != ?
                        ");
                        $propStmt->execute([$name, $internal]);
                        $propertiesToUpdate = $propStmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($propertiesToUpdate as $property) {
                            $entityType = $property['note_id'] ? 'note' : 'page';
                            $entityId = $property['note_id'] ?: $property['page_id'];
                            
                            _updateOrAddPropertyAndDispatchTriggers(
                                $pdo,
                                $entityType,
                                $entityId,
                                $name,             // Property name from the definition
                                $property['value'], // Existing value of the property
                                $internal          // Internal status from the new definition
                            );
                            $processedCount++;
                        }
                    }
                    
                    $pdo->commit();
                    $message = "Property definition saved successfully. Processed {$processedCount} existing properties for update based on auto-apply setting.";
                    $messageType = 'success';
                    break;
                    
                case 'delete_definition':
                    $id = (int)$_POST['definition_id'];
                    $stmt = $pdo->prepare("DELETE FROM PropertyDefinitions WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "Property definition deleted successfully.";
                    $messageType = 'success';
                    break;
                    
                case 'apply_all':
                    // Ensure the core function is available
                    if (!function_exists('_updateOrAddPropertyAndDispatchTriggers')) {
                        throw new Exception('Core property update function _updateOrAddPropertyAndDispatchTriggers is missing. Ensure api/properties.php is included correctly.');
                    }

                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("SELECT name, internal FROM PropertyDefinitions WHERE auto_apply = 1");
                    $stmt->execute();
                    $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $totalProcessed = 0;
                    foreach ($definitions as $definition) {
                        $defName = $definition['name'];
                        $defInternal = (int)$definition['internal'];

                        // Fetch existing properties that need to be updated according to this definition
                        $propStmt = $pdo->prepare("
                            SELECT id, value, note_id, page_id 
                            FROM Properties 
                            WHERE name = ? AND internal != ?
                        ");
                        $propStmt->execute([$defName, $defInternal]);
                        $propertiesToUpdate = $propStmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($propertiesToUpdate as $property) {
                            $entityType = $property['note_id'] ? 'note' : 'page';
                            $entityId = $property['note_id'] ?: $property['page_id'];

                            _updateOrAddPropertyAndDispatchTriggers(
                                $pdo,
                                $entityType,
                                $entityId,
                                $defName,
                                $property['value'],
                                $defInternal
                            );
                            $totalProcessed++;
                        }
                    }
                    
                    $pdo->commit();
                    $message = "Applied all auto-apply property definitions. Processed {$totalProcessed} existing properties for update.";
                    $messageType = 'success';
                    break;
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch all property definitions
$stmt = $pdo->prepare("SELECT * FROM PropertyDefinitions ORDER BY name");
$stmt->execute();
$definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT name) as unique_names,
        COUNT(*) as total_properties,
        COUNT(CASE WHEN internal = 1 THEN 1 END) as internal_properties
    FROM Properties
");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get property names not yet in definitions
$stmt = $pdo->prepare("
    SELECT DISTINCT p.name, COUNT(*) as usage_count
    FROM Properties p
    LEFT JOIN PropertyDefinitions pd ON p.name = pd.name
    WHERE pd.name IS NULL
    GROUP BY p.name
    ORDER BY usage_count DESC, p.name
");
$stmt->execute();
$undefinedProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Definitions Manager</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: #059669;
            color: white;
            padding: 20px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        
        .header p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }
        
        .content {
            padding: 20px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert.success {
            background-color: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }
        
        .alert.error {
            background-color: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #059669;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section h2 {
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #374151;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            transform: scale(1.2);
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: #059669;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #047857;
        }
        
        .btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .btn-danger {
            background-color: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b91c1c;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        
        .table tr:hover {
            background-color: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-green {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-red {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .badge-blue {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .actions {
            display: flex;
            gap: 5px;
        }
        
        .quick-add {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .quick-add button {
            padding: 4px 8px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Property Definitions Manager</h1>
            <p>Define which property names should be internal and apply rules automatically</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="alert <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= count($definitions) ?></div>
                    <div class="stat-label">Property Definitions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['unique_names'] ?></div>
                    <div class="stat-label">Unique Property Names</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['internal_properties'] ?></div>
                    <div class="stat-label">Internal Properties</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count($undefinedProperties) ?></div>
                    <div class="stat-label">Undefined Properties</div>
                </div>
            </div>
            
            <div class="section">
                <h2>Add Property Definition</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add_definition">
                    <div class="form-grid">
                        <div>
                            <div class="form-group">
                                <label for="name">Property Name</label>
                                <input type="text" id="name" name="name" required placeholder="e.g., debug, system, internal">
                            </div>
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" rows="3" placeholder="Describe what this property is used for"></textarea>
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="internal" name="internal" value="1">
                                    <label for="internal">Mark as Internal</label>
                                </div>
                                <small style="color: #6b7280; margin-top: 5px; display: block;">
                                    Internal properties are hidden from normal API responses
                                </small>
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="auto_apply" name="auto_apply" value="1" checked>
                                    <label for="auto_apply">Auto-apply to existing properties</label>
                                </div>
                                <small style="color: #6b7280; margin-top: 5px; display: block;">
                                    Automatically update existing properties with this name
                                </small>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">Add Definition</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php if (count($undefinedProperties) > 0): ?>
            <div class="section">
                <h2>Quick Add Undefined Properties</h2>
                <p style="color: #6b7280; margin-bottom: 15px;">
                    These property names exist in your system but don't have definitions yet:
                </p>
                <div class="quick-add">
                    <?php foreach ($undefinedProperties as $prop): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="add_definition">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($prop['name']) ?>">
                            <input type="hidden" name="description" value="Auto-generated definition for existing property">
                            <input type="hidden" name="auto_apply" value="1">
                            <button type="submit" class="btn btn-secondary" title="Used <?= $prop['usage_count'] ?> times">
                                <?= htmlspecialchars($prop['name']) ?> (<?= $prop['usage_count'] ?>)
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="section">
                <h2>Property Definitions</h2>
                <div style="margin-bottom: 15px;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="apply_all">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Apply all property definitions to existing properties?')">
                            Apply All Definitions to Existing Properties
                        </button>
                    </form>
                    <a href="property_manager.php" class="btn btn-secondary" style="margin-left: 10px;">
                        View Individual Properties
                    </a>
                </div>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th>Property Name</th>
                            <th>Status</th>
                            <th>Auto-Apply</th>
                            <th>Description</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($definitions as $definition): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($definition['name']) ?></strong>
                            </td>
                            <td>
                                <?php if ($definition['internal']): ?>
                                    <span class="badge badge-red">Internal</span>
                                <?php else: ?>
                                    <span class="badge badge-green">Public</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($definition['auto_apply']): ?>
                                    <span class="badge badge-blue">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-red">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($definition['description'] ?: 'No description') ?>
                            </td>
                            <td>
                                <?= date('M j, Y', strtotime($definition['created_at'])) ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_definition">
                                        <input type="hidden" name="definition_id" value="<?= $definition['id'] ?>">
                                        <button type="submit" class="btn btn-danger" 
                                                onclick="return confirm('Delete this property definition?')"
                                                title="Delete definition">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($definitions)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #6b7280; padding: 40px;">
                                No property definitions yet. Add some above to get started!
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html> 