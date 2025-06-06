<?php
// Initialize variables
$pageData = null;
$error_message = '';
$api_debug_info = ''; // For storing messages about API call process

// 1. Retrieve Page Identifier
$page_id = isset($_GET['id']) ? $_GET['id'] : null;
$page_name = isset($_GET['name']) ? $_GET['name'] : null;

if (!$page_id && !$page_name) {
    $error_message = "Error: Page ID or name not specified in the URL.";
} else {
    // 2. Construct API URL
    $query_params = ['include_details' => '1'];
    if ($page_id) {
        $query_params['id'] = $page_id;
    } elseif ($page_name) {
        $query_params['name'] = $page_name;
    }
    $query_string = http_build_query($query_params);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $api_base_url = $scheme . '://' . $host . '/api/pages.php';
    $api_url = $api_base_url . '?' . $query_string;
    $api_debug_info = "Attempting to fetch from: " . htmlspecialchars($api_url);

    // 3. Fetch Data
    $context_options = [
        'http' => [
            'timeout' => 10, 
            'ignore_errors' => true 
        ]
    ];
    $context = stream_context_create($context_options);
    $response = @file_get_contents($api_url, false, $context);

    if ($response === false) {
        $error_message = "Error: Could not retrieve page data from the API. file_get_contents failed.";
    } else {
        // 4. Decode and Store Data
        $responseData = json_decode($response, true);
        $json_error_code = json_last_error();

        if ($json_error_code !== JSON_ERROR_NONE) {
            $error_message = "Error: Invalid JSON response from API. Code: $json_error_code. Message: " . json_last_error_msg();
        } else {
            $status_line = $http_response_header[0] ?? '';
            preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
            $http_status = isset($match[1]) ? intval($match[1]) : 0;

            if ($http_status >= 200 && $http_status < 300) {
                if (isset($responseData['error'])) {
                    $error_message = "API Error: " . htmlspecialchars($responseData['error']);
                } elseif (is_array($responseData)) {
                    if (count($responseData) === 1 && isset($responseData[0])) {
                        $pageData = $responseData[0];
                    } elseif (count($responseData) > 1) {
                        $pageData = $responseData[0]; 
                    } elseif (empty($responseData) && $http_status === 200) {
                        $error_message = "Page not found.";
                    } else {
                        // This handles empty array or non-standard successful response.
                        // If $responseData is an empty array, $pageData remains null, leading to "Page not found" or "No data" message.
                        // If it's some other structure, it might lead to errors if not handled by JS, but JS is being simplified.
                        // For PHP templating, $pageData being null or not having expected keys is handled by `??` or `isset`.
                         $error_message = "API returned successfully but with unexpected data structure or no specific page data found.";
                    }
                } else {
                     $error_message = "API returned unexpected data format. Expected an array of page data.";
                }
            } else { 
                $error_message = "API request failed with HTTP status: " . $http_status . ". ";
                if (isset($responseData['error'])) {
                    $error_message .= "API Error: " . htmlspecialchars($responseData['error']);
                } elseif (isset($responseData['message'])) {
                    $error_message .= "API Message: " . htmlspecialchars($responseData['message']);
                } else {
                    $error_message .= "No specific error message from API.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($pageData['name'] ?? ($error_message ? 'Error' : 'Page Print View')); ?></title>
    <style>
        body {
            font-family: sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        #page-title-h1 { /* Renamed ID to avoid conflict if JS still targets 'page-title' */
            color: #333;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .page-properties {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 4px;
        }
        .page-properties dt {
            font-weight: bold;
            color: #555;
        }
        .page-properties dd {
            margin-left: 20px;
            margin-bottom: 5px;
        }
        #notes-container {
            margin-top: 20px;
        }
        .note {
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
        }
        .note-type-info {
            border-left: 3px solid #007bff;
        }
        .note-type-warning {
            border-left: 3px solid #ffc107;
        }
        .buttons {
            margin-bottom: 20px;
            text-align: right;
        }
        .buttons button {
            padding: 10px 15px;
            margin-left: 10px;
            border: none;
            background-color: #007bff;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }
        .buttons button:hover {
            background-color: #0056b3;
        }
        .error-message {
            color: #D8000C;
            background-color: #FFD2D2;
            border: 1px solid #D8000C;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .api-debug-info {
            font-size: 0.8em;
            color: #777;
            background-color: #eee;
            padding: 5px;
            margin-bottom:10px;
        }

        @media print {
            body {
                background-color: #fff;
                color: #000;
                font-family: serif;
            }
            .container {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
                border-radius: 0;
                padding: 0;
            }
            .buttons, .error-message, .api-debug-info {
                display: none;
            }
            #page-title-h1 {
                border-bottom: 1px solid #000;
            }
            .page-properties {
                border: 1px solid #ccc;
                background-color: #fff;
            }
            .note {
                border: 1px solid #ccc;
            }
            a {
                text-decoration: underline;
                color: #000;
            }
            a[href]:after {
                content: " (" attr(href) ")";
                font-size: 0.9em;
                color: #555;
            }
            * {
                background-image: none !important;
                background-color: transparent !important;
                color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
        }
    </style>
    <script>
        // Server error message is still useful for JS to know if it needs to do anything
        const serverErrorMessage = <?php echo json_encode($error_message); ?>;

        function saveAsHtml() {
            // Get page title from the document.title which is set by PHP
            let pageTitleForFile = document.title;
            if (pageTitleForFile === 'Error' || pageTitleForFile === 'Page Print View') {
                 // Fallback if title isn't specific, try H1
                 const h1Title = document.getElementById('page-title-h1');
                 if (h1Title && h1Title.textContent !== 'Error Loading Page' && h1Title.textContent !== 'Loading...') {
                    pageTitleForFile = h1Title.textContent;
                 } else {
                    pageTitleForFile = 'page'; // Generic fallback
                 }
            }

            const safeName = pageTitleForFile.replace(/[^a-z0-9]/gi, '_').toLowerCase() || 'page';
            const filename = safeName + '.html';
            
            const htmlContent = document.documentElement.outerHTML;
            const blob = new Blob([htmlContent], { type: 'text/html' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        }

        window.onload = function() {
            const errorDisplay = document.getElementById('error-display');
            const apiDebugDisplay = document.getElementById('api-debug-display');
            const phpApiDebugInfo = <?php echo json_encode($api_debug_info); ?>;


            if (apiDebugDisplay && phpApiDebugInfo) {
                 apiDebugDisplay.innerHTML = phpApiDebugInfo; // Already HTML escaped by PHP if needed
                 apiDebugDisplay.style.display = 'block';
            }

            if (serverErrorMessage) {
                if (errorDisplay) {
                    errorDisplay.innerHTML = serverErrorMessage; // Already HTML escaped by PHP
                    errorDisplay.style.display = 'block';
                }
                // If there's an error, the H1 title might already be "Error Loading Page" or similar from PHP.
                // No further JS action needed to display content as it's server-rendered.
            }
            // No need to call a render function, content is already in HTML from PHP.
        };
    </script>
</head>
<body>
    <div class="container">
        <div class="buttons">
            <button onclick="window.print()">Print</button>
            <button onclick="saveAsHtml()">Save as HTML</button>
        </div>

        <?php if ($api_debug_info): ?>
            <div id="api-debug-display" class="api-debug-info"><?php echo $api_debug_info; /* No need to re-echo if JS handles it, but can be direct */ ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div id="error-display" class="error-message"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <h1 id="page-title-h1">
            <?php 
            if ($pageData && isset($pageData['name'])) {
                echo htmlspecialchars($pageData['name']);
            } elseif ($error_message) {
                echo 'Error Loading Page';
            } else {
                echo 'Page Title'; // Default if no data and no error (e.g. params missing)
            }
            ?>
        </h1>

        <div id="page-properties" class="page-properties">
            <?php if ($pageData && isset($pageData['properties']) && is_array($pageData['properties']) && !empty($pageData['properties'])): ?>
                <dl>
                    <?php foreach ($pageData['properties'] as $key => $value): ?>
                        <?php if (is_string($value) || is_numeric($value) || is_bool($value)): ?>
                            <dt><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($key))); ?></dt>
                            <dd><?php echo htmlspecialchars($value); ?></dd>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </dl>
            <?php elseif (!$error_message && $pageData): // $pageData exists but no properties or empty ?>
                <p>No properties to display for this page.</p>
            <?php elseif (!$error_message && !$pageData && !isset($_GET['id']) && !isset($_GET['name'])): // No error, no data, no params ?>
                <p>Please specify a page ID or name.</p>
            <?php endif; ?>
            <?php // If $error_message is set, this section remains empty or shows above message. ?>
        </div>

        <div id="notes-container">
            <?php if ($pageData && isset($pageData['notes']) && is_array($pageData['notes']) && !empty($pageData['notes'])): ?>
                <?php foreach ($pageData['notes'] as $note): ?>
                    <?php if (isset($note['content'])): ?>
                        <div class="note <?php echo isset($note['type']) ? 'note-type-' . htmlspecialchars(strtolower(str_replace(' ', '-', $note['type']))) : ''; ?>">
                            <?php echo $note['content']; // Assuming content is safe HTML. Sanitize if necessary. ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php elseif (!$error_message && $pageData): // $pageData exists but no notes or empty ?>
                <p>No notes available for this page.</p>
            <?php elseif (!$error_message && !$pageData && !isset($_GET['id']) && !isset($_GET['name'])): // No error, no data, no params ?>
                <?php // Message handled by overall error check or lack of data ?>
            <?php endif; ?>
            <?php if ($error_message && !$pageData): /* If there was an error and no page data loaded */ ?>
                <p>Content could not be loaded due to an error.</p>
            <?php elseif (!$pageData && !isset($_GET['id']) && !isset($_GET['name']) && !$error_message): ?>
                 <p>Loading content...</p> <?php // Or "Please specify page ID/name" if that's the state ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
