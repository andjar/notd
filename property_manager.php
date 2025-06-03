<?php
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/property_triggers.php'; // Include the trigger system

$pdo = get_db_connection();

// Check if PropertyDefinitions table exists
$tableExists = false;
try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='PropertyDefinitions'");
    $tableExists = (bool)$stmt->fetch();
} catch (Exception $e) {
    // Table doesn't exist
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_internal') {
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['internal_status']) && is_array($_POST['internal_status'])) {
            foreach ($_POST['internal_status'] as $propertyId => $internalValue) {
                $internal = ($internalValue === '1') ? 1 : 0;
                
                // First get the property details for triggers
                $stmt = $pdo->prepare("SELECT name, value, note_id, page_id FROM Properties WHERE id = ?");
                $stmt->execute([(int)$propertyId]);
                $propertyData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($propertyData) {
                    // Update the internal status
                    $stmt = $pdo->prepare("UPDATE Properties SET internal = ? WHERE id = ?");
                    $stmt->execute([$internal, (int)$propertyId]);
                    
                    // Trigger any property handlers
                    $entityType = $propertyData['note_id'] ? 'note' : 'page';
                    $entityId = $propertyData['note_id'] ?: $propertyData['page_id'];
                    
                    dispatchPropertyTriggers($pdo, $entityType, $entityId, $propertyData['name'], $propertyData['value']);
                }
            }
        }
        
        $pdo->commit();
        $success_message = "Property internal status updated successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error updating properties: " . $e->getMessage();
    }
}

// Fetch all properties with counts
$query = "
    SELECT 
        p.id,
        p.name,
        p.value,
        p.internal,
        COUNT(CASE WHEN p.note_id IS NOT NULL THEN p.note_id END) as note_count,
        COUNT(CASE WHEN p.page_id IS NOT NULL THEN p.page_id END) as page_count,
        COUNT(*) as total_count
    FROM Properties p
    GROUP BY p.name, p.value, p.internal
    ORDER BY p.name, p.value
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group properties by name for better display
$groupedProperties = [];
foreach ($properties as $property) {
    $name = $property['name'];
    if (!isset($groupedProperties[$name])) {
        $groupedProperties[$name] = [];
    }
    $groupedProperties[$name][] = $property;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Manager</title>
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
            background: #2563eb;
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
        
        .controls {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background-color: #2563eb;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        
        .btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .property-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .property-table th,
        .property-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .property-table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        
        .property-table tr:hover {
            background-color: #f9fafb;
        }
        
        .property-name {
            font-weight: 600;
            color: #1f2937;
        }
        
        .property-value {
            font-family: 'Monaco', 'Menlo', monospace;
            background-color: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        
        .count-badge {
            display: inline-block;
            background-color: #3b82f6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .internal-checkbox {
            transform: scale(1.2);
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
            color: #2563eb;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .checkbox-column {
            width: 60px;
            text-align: center;
        }
        
        .narrow-column {
            width: 80px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Property Manager</h1>
            <p>Manage internal status for all properties in the system</p>
        </div>
        
        <div class="content">
            <?php if (isset($success_message)): ?>
                <div class="alert success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= count($properties) ?></div>
                    <div class="stat-label">Total Property Instances</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count($groupedProperties) ?></div>
                    <div class="stat-label">Unique Property Names</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count(array_filter($properties, fn($p) => $p['internal'] == 1)) ?></div>
                    <div class="stat-label">Internal Properties</div>
                </div>
            </div>
            
            <form method="POST" id="propertyForm">
                <input type="hidden" name="action" value="update_internal">
                
                <div class="controls">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="selectAll()">Select All Internal</button>
                    <button type="button" class="btn btn-secondary" onclick="deselectAll()">Deselect All</button>
                </div>
                
                <table class="property-table">
                    <thead>
                        <tr>
                            <th>Property Name</th>
                            <th>Value</th>
                            <th class="narrow-column">Notes</th>
                            <th class="narrow-column">Pages</th>
                            <th class="narrow-column">Total</th>
                            <th class="checkbox-column">Internal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupedProperties as $propertyName => $propertyVariants): ?>
                            <?php $isFirst = true; ?>
                            <?php foreach ($propertyVariants as $property): ?>
                                <tr>
                                    <?php if ($isFirst): ?>
                                        <td rowspan="<?= count($propertyVariants) ?>" class="property-name">
                                            <?= htmlspecialchars($propertyName) ?>
                                        </td>
                                        <?php $isFirst = false; ?>
                                    <?php endif; ?>
                                    
                                    <td>
                                        <span class="property-value"><?= htmlspecialchars($property['value']) ?></span>
                                    </td>
                                    
                                    <td class="narrow-column">
                                        <?php if ($property['note_count'] > 0): ?>
                                            <span class="count-badge"><?= $property['note_count'] ?></span>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">0</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="narrow-column">
                                        <?php if ($property['page_count'] > 0): ?>
                                            <span class="count-badge"><?= $property['page_count'] ?></span>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">0</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="narrow-column">
                                        <span class="count-badge"><?= $property['total_count'] ?></span>
                                    </td>
                                    
                                    <td class="checkbox-column">
                                        <input 
                                            type="checkbox" 
                                            name="internal_status[<?= $property['id'] ?>]" 
                                            value="1" 
                                            class="internal-checkbox"
                                            <?= $property['internal'] ? 'checked' : '' ?>
                                        >
                                        <input 
                                            type="hidden" 
                                            name="internal_status[<?= $property['id'] ?>]" 
                                            value="0"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <script>
        function selectAll() {
            const checkboxes = document.querySelectorAll('.internal-checkbox');
            checkboxes.forEach(cb => cb.checked = true);
        }
        
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.internal-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
        }
        
        // Confirm before submitting if many changes
        document.getElementById('propertyForm').addEventListener('submit', function(e) {
            const checkedCount = document.querySelectorAll('.internal-checkbox:checked').length;
            const totalCount = document.querySelectorAll('.internal-checkbox').length;
            
            if (checkedCount > totalCount * 0.5) {
                if (!confirm(`You're about to mark ${checkedCount} properties as internal. This may hide them from normal views. Continue?`)) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html> 