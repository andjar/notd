<?php
// test_multi_upload.php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    // Output an HTML form with two dummy files (PNG and JSON) using data URLs
    echo "<html><body>\n";
    echo "<form id='uploadForm' method='POST' enctype='multipart/form-data'>\n";
    echo "<input type='hidden' name='note_id' value='123' />\n";
    // Single file input with multiple attribute
    echo "<input type='file' name='attachmentFile' id='fileInput' multiple />\n";
    echo "<input type='submit' value='Upload' />\n";
    echo "</form>\n";
    echo "<script>\n";
    echo "function dataURLtoFile(dataurl, filename) {\n";
    echo "  var arr = dataurl.split(','), mime = arr[0].match(/:(.*?);/)[1], bstr = atob(arr[1]), n = bstr.length, u8arr = new Uint8Array(n);\n";
    echo "  while(n--){ u8arr[n] = bstr.charCodeAt(n); }\n";
    echo "  return new File([u8arr], filename, {type:mime});\n";
    echo "}\n";
    // PNG: 1x1 transparent pixel
    echo "const pngFile = dataURLtoFile('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAn8B9pQn2wAAAABJRU5ErkJggg==', 'dummy.png');\n";
    // JSON: simple Excalidraw
    echo "const jsonFile = new File([JSON.stringify({type:'excalidraw',elements:[],appState:{}})], 'dummy.excalidraw', {type:'application/json'});\n";
    echo "const dt = new DataTransfer();\n";
    echo "dt.items.add(pngFile);\n";
    echo "dt.items.add(jsonFile);\n";
    echo "document.getElementById('fileInput').files = dt.files;\n";
    echo "setTimeout(function(){ document.getElementById('uploadForm').submit(); }, 500);\n";
    echo "</script>\n";
    echo "</body></html>\n";
    exit;
}
header('Content-Type: text/plain; charset=utf-8');

// Print POST data
if (!empty($_POST)) {
    echo "POST data:\n";
    print_r($_POST);
} else {
    echo "No POST data received.\n";
}

// Print FILES data
if (!empty($_FILES)) {
    echo "\nFILES data:\n";
    print_r($_FILES);
    // For each file, print details
    if (isset($_FILES['attachmentFile'])) {
        $files = $_FILES['attachmentFile'];
        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                echo "\nFile #$i:\n";
                echo "  name:      " . $files['name'][$i] . "\n";
                echo "  type:      " . $files['type'][$i] . "\n";
                echo "  tmp_name:  " . $files['tmp_name'][$i] . "\n";
                echo "  error:     " . $files['error'][$i] . "\n";
                echo "  size:      " . $files['size'][$i] . "\n";
                if (file_exists($files['tmp_name'][$i])) {
                    echo "  Temp file exists and is " . filesize($files['tmp_name'][$i]) . " bytes\n";
                } else {
                    echo "  Temp file does NOT exist!\n";
                }
            }
        } else {
            echo "\nSingle file:\n";
            echo "  name:      " . $files['name'] . "\n";
            echo "  type:      " . $files['type'] . "\n";
            echo "  tmp_name:  " . $files['tmp_name'] . "\n";
            echo "  error:     " . $files['error'] . "\n";
            echo "  size:      " . $files['size'] . "\n";
            if (file_exists($files['tmp_name'])) {
                echo "  Temp file exists and is " . filesize($files['tmp_name']) . " bytes\n";
            } else {
                echo "  Temp file does NOT exist!\n";
            }
        }
    }
} else {
    echo "No FILES data received.\n";
}
?> 