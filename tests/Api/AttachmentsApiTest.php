<?php
// tests/Api/AttachmentsApiTest.php

namespace Tests\Api;

require_once dirname(dirname(__DIR__)) . '/tests/BaseTestCase.php';

use BaseTestCase; // Allow unqualified class name BaseTestCase
use PDO;

class AttachmentsApiTest extends BaseTestCase
{
    protected static $testPageId;
    protected static $testNoteId;
    private $dummyFiles = [];

    protected function setUp(): void
    {
        parent::setUp(); // Calls BaseTestCase::setUp()

        // Ensure PDO is available
        if (!self::$pdo) {
            $this->fail("PDO connection not available in AttachmentsApiTest::setUp");
        }

        // Create a dummy page
        $stmt = self::$pdo->prepare("INSERT INTO Pages (name) VALUES (:name)");
        $stmt->execute([':name' => 'Test Page for Attachments']);
        self::$testPageId = self::$pdo->lastInsertId();

        // Create a dummy note associated with the dummy page
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute([':page_id' => self::$testPageId, ':content' => 'Test Note for Attachments']);
        self::$testNoteId = self::$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        // Clean up dummy files
        foreach ($this->dummyFiles as $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $this->dummyFiles = [];

        // It's good practice to clean up created records, though the in-memory DB is wiped each time.
        // If using a persistent DB for tests, this would be crucial.
        if (self::$pdo) {
            self::$pdo->exec("DELETE FROM Attachments");
            self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)self::$testNoteId);
            self::$pdo->exec("DELETE FROM Pages WHERE id = " . (int)self::$testPageId);
        }
        
        parent::tearDown(); // Calls BaseTestCase::tearDown()
    }

    /**
     * Helper method to create a dummy file for $_FILES simulation.
     *
     * @param string $filename
     * @param string $type
     * @param int $size in bytes
     * @param int $error PHP UPLOAD_ERR_* constant
     * @return array
     */
    private function createDummyFile(string $filename, string $type, int $size, int $error = UPLOAD_ERR_OK): array
    {
        $tempPath = sys_get_temp_dir() . '/' . uniqid() . $filename;
        if ($error === UPLOAD_ERR_OK) {
            $handle = fopen($tempPath, 'w');
            if ($size > 0) { // Write actual content only if size > 0
                 ftruncate($handle, $size); // Create a file of specified size (sparse)
                 // fwrite($handle, str_repeat("0", $size)); // Alternative: write actual bytes
            }
            fclose($handle);
            $this->dummyFiles[] = $tempPath; // Track for cleanup
        } else {
            // If there's an upload error, tmp_name might be empty or not exist.
            // For testing, providing a non-existent path or empty string is fine.
            $tempPath = ''; 
        }


        return [
            'name' => $filename,
            'type' => $type,
            'size' => $size,
            'tmp_name' => $tempPath,
            'error' => $error,
        ];
    }

    // --- Test Cases Start Here ---

    public function testPostUploadAttachmentSuccess()
    {
        $dummyFile = $this->createDummyFile('test_image.jpg', 'image/jpeg', 1024); // 1KB
        $filesData = ['attachmentFile' => $dummyFile]; // 'attachmentFile' is the expected key in api/attachments.php
        
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);

        $this->assertIsArray($response, "Response is not an array: " . print_r($response, true));
        $this->assertTrue($response['success'], "Response should indicate success");
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('id', $response['data']);
        $this->assertEquals('test_image.jpg', $response['data']['name']);
        $this->assertEquals(1024, $response['data']['size']);
        $this->assertEquals('image/jpeg', $response['data']['type']);
        $this->assertEquals(self::$testNoteId, $response['data']['note_id']);

        // Verify file exists in UPLOADS_DIR.
        // api/attachments.php stores path as YYYY/MM/unique_filename in 'path' column.
        // The actual file is at UPLOADS_DIR / path.
        // The response['data'] is the direct DB record.
        $this->assertArrayHasKey('path', $response['data']);
        $expectedFilePath = self::$tempUploadsDir . '/' . $response['data']['path'];
        $this->assertFileExists($expectedFilePath, "Uploaded file should exist at: " . $expectedFilePath);

        // Verify DB record content
        $stmt = self::$pdo->prepare("SELECT * FROM Attachments WHERE id = :id");
        $stmt->execute([':id' => $response['data']['id']]);
        $dbRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($dbRecord, "Attachment record should exist in DB.");
        $this->assertEquals($dummyFile['name'], $dbRecord['name'], "Original filename should be stored in 'name' column."); // 'name' from $_FILES is original filename
        $this->assertEquals($response['data']['path'], $dbRecord['path'], "DB 'path' column should match response path.");
        $this->assertEquals($dummyFile['type'], $dbRecord['type'], "File type should be stored."); // Mime type from validation
        $this->assertEquals($dummyFile['size'], $dbRecord['size'], "File size should be stored."); // Size from $_FILES
    }

    public function testPostUploadAttachmentFailureNoNoteId()
    {
        $dummyFile = $this->createDummyFile('test.txt', 'text/plain', 100);
        $filesData = ['attachmentFile' => $dummyFile];
        // No note_id in $postData
        $response = $this->request('POST', 'api/attachments.php', [], $filesData);

        $this->assertFalse($response['success']);
        $this->assertEquals('Note ID is required.', $response['error']['message']);
    }

    public function testPostUploadAttachmentFailureInvalidNoteId()
    {
        $dummyFile = $this->createDummyFile('test.doc', 'application/msword', 200);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => 99999]; // Non-existent note_id

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('Note not found.', $response['error']['message']);
    }

    public function testPostUploadAttachmentFailureNoFile()
    {
        $postData = ['note_id' => self::$testNoteId];
        // $_FILES is empty
        $response = $this->request('POST', 'api/attachments.php', $postData, []);

        $this->assertFalse($response['success']);
        $this->assertEquals('No file uploaded or upload error.', $response['error']['message']);
    }

    public function testPostUploadAttachmentFailureUploadErrorIniSize()
    {
        $dummyFile = $this->createDummyFile('large_file.zip', 'application/zip', 100000, UPLOAD_ERR_INI_SIZE);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('The uploaded file exceeds the upload_max_filesize directive in php.ini.', $response['error']['message']);
    }

    public function testPostUploadAttachmentFailureUploadErrorFormSize()
    {
        $dummyFile = $this->createDummyFile('another_large.zip', 'application/zip', 50000, UPLOAD_ERR_FORM_SIZE);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', $response['error']['message']);
    }
    
    public function testPostUploadAttachmentFailureUploadErrorPartial()
    {
        $dummyFile = $this->createDummyFile('partial.zip', 'application/zip', 50000, UPLOAD_ERR_PARTIAL);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('The uploaded file was only partially uploaded.', $response['error']['message']);
    }
    
    public function testPostUploadAttachmentFailureUploadErrorNoTmpDir()
    {
        $dummyFile = $this->createDummyFile('no_tmp.zip', 'application/zip', 50000, UPLOAD_ERR_NO_TMP_DIR);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('Missing a temporary folder.', $response['error']['message']);
    }

    // Note: Testing UPLOAD_ERR_CANT_WRITE and UPLOAD_ERR_EXTENSION would require more complex environment manipulation.
    
    public function testPostUploadAttachmentFailureFileTooLargeAppLogic()
    {
        // MAX_FILE_SIZE is 10MB in api/attachments.php. BaseTestCase defines MAX_UPLOAD_SIZE_MB as 10.
        // Let's create a file slightly larger than 10MB.
        $largeFileSize = (10 * 1024 * 1024) + 1024; // 10MB + 1KB
        $dummyFile = $this->createDummyFile('too_large_app.zip', 'application/zip', $largeFileSize);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('File exceeds maximum size limit', $response['error']['message']);
    }
    
    public function testPostUploadAttachmentFailureInvalidFileType()
    {
        // Create a dummy .exe file (assuming .exe is not in ALLOWED_MIME_TYPES)
        // The validation uses finfo or extension mapping. We'll rely on name for this test.
        $dummyFile = $this->createDummyFile('test_script.exe', 'application/octet-stream', 1024);
        // To make it more robust, ensure the 'type' in $_FILES matches what finfo might say for an unknown,
        // or ensure finfo is mocked/bypassed if testing extension fallback.
        // For now, 'application/octet-stream' is a common default for unknown types.
        // api/attachments.php ALLOWED_MIME_TYPES does not include 'application/octet-stream' or 'application/x-msdownload' for .exe
        
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', 'api/attachments.php', $postData, $filesData);
        
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('File type not allowed', $response['error']['message']);
    }


    // --- GET /api/attachments.php?note_id={id} Tests ---
    public function testGetAttachmentsForNoteSuccessWithAttachments()
    {
        // Upload a couple of attachments first
        $file1 = $this->createDummyFile('file1.pdf', 'application/pdf', 500);
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $file1]);
        $file2 = $this->createDummyFile('file2.txt', 'text/plain', 300);
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $file2]);

        $response = $this->request('GET', 'api/attachments.php', ['note_id' => self::$testNoteId]);
        
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertCount(2, $response['data']);
        $this->assertEquals('file1.pdf', $response['data'][0]['name']);
        $this->assertEquals('file2.txt', $response['data'][1]['name']);
        $this->assertStringContainsString('api/attachments.php?action=download&id=', $response['data'][0]['url']);
    }

    public function testGetAttachmentsForNoteSuccessNoAttachments()
    {
        // Use a new note that has no attachments
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute([':page_id' => self::$testPageId, ':content' => 'Note with no attachments']);
        $noteIdWithNoAttachments = self::$pdo->lastInsertId();

        $response = $this->request('GET', 'api/attachments.php', ['note_id' => $noteIdWithNoAttachments]);

        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertCount(0, $response['data']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$noteIdWithNoAttachments);
    }

    public function testGetAttachmentsForNoteFailureInvalidNoteId()
    {
        $response = $this->request('GET', 'api/attachments.php', ['note_id' => 88888]);
        // Current API returns success with empty data for non-existent note_id
        // This might be desired, or could be a 404. Test reflects current behavior.
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertCount(0, $response['data']);
    }

    // --- GET /api/attachments.php (List All Attachments) ---
    // These tests will be more involved and depend on the exact implementation
    // of pagination, filtering, and sorting in attachments.php (which seems basic for now).
    // For the current state of attachments.php (as of my last knowledge),
    // it doesn't support pagination, filtering, or sorting for GET all.
    // It primarily supports GET by note_id, or GET by attachment id (for download/view).
    // The prompt implies a more advanced GET all endpoint.
    // I will assume the endpoint GET /api/attachments.php (no params) is meant to list all.
    // Based on the current attachments.php, this is not implemented.
    // I will write a placeholder or skip if the functionality is not there.

    // public function testGetAttachmentsWithoutIdOrNoteIdFails()
    // {
    //     // Test GET /api/attachments.php without any parameters
    //     // This behavior has changed. Now it lists all attachments.
    //     $response = $this->request('GET', 'api/attachments.php');
    //     $this->assertEquals('error', $response['status']);
    //     $this->assertEquals('Required parameter (id or note_id) is missing.', $response['message']);
    // }

    public function testGetAllAttachmentsSuccessWithAttachments()
    {
        // Create a second note for broader testing
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute([':page_id' => self::$testPageId, ':content' => 'Another Test Note']);
        $anotherNoteId = self::$pdo->lastInsertId();

        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('attach1.txt', 'text/plain', 100)]);
        $this->request('POST', 'api/attachments.php', ['note_id' => $anotherNoteId], ['attachmentFile' => $this->createDummyFile('attach2.pdf', 'application/pdf', 200)]);
        
        $response = $this->request('GET', 'api/attachments.php');
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertGreaterThanOrEqual(2, count($response['data']['data']));
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$anotherNoteId); // Clean up
    }
    
    public function testGetAllAttachmentsFiltering()
    {
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('filter_me.txt', 'text/plain', 100)]);
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('another_file.pdf', 'application/pdf', 200)]);

        // Filter by name
        $responseName = $this->request('GET', 'api/attachments.php', ['filter_by_name' => 'filter_me']);
        $this->assertEquals('success', $responseName['status']);
        $this->assertCount(1, $responseName['data']['data']);
        $this->assertEquals('filter_me.txt', $responseName['data']['data'][0]['name']);

        // Filter by type
        $responseType = $this->request('GET', 'api/attachments.php', ['filter_by_type' => 'application/pdf']);
        $this->assertEquals('success', $responseType['status']);
        $this->assertCount(1, $responseType['data']['data']);
        $this->assertEquals('another_file.pdf', $responseType['data']['data'][0]['name']);
        $this->assertEquals('application/pdf', $responseType['data']['data'][0]['type']);
    }

    public function testGetAllAttachmentsSorting()
    {
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('sort_C.txt', 'text/plain', 100)]);
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('sort_A.txt', 'text/plain', 200)]);
        $this->request('POST', 'api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('sort_B.txt', 'text/plain', 150)]);

        // Sort by name ASC
        $responseSortNameAsc = $this->request('GET', 'api/attachments.php', ['sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responseSortNameAsc['status']);
        $this->assertGreaterThanOrEqual(3, count($responseSortNameAsc['data']['data']));
        $this->assertEquals('sort_A.txt', $responseSortNameAsc['data']['data'][0]['name']);
        $this->assertEquals('sort_B.txt', $responseSortNameAsc['data']['data'][1]['name']);
        $this->assertEquals('sort_C.txt', $responseSortNameAsc['data']['data'][2]['name']);
        
        // Sort by size DESC
        $responseSortSizeDesc = $this->request('GET', 'api/attachments.php', ['sort_by' => 'size', 'sort_order' => 'desc']);
        $this->assertEquals('success', $responseSortSizeDesc['status']);
        $this->assertGreaterThanOrEqual(3, count($responseSortSizeDesc['data']['data']));
        // Sizes are not directly in GET all response in the provided API, this needs adjustment.
        // The API script select includes 'size', so this should work.
        // Let's assume the createDummyFile creates files with actual size or the API populates it.
        // The API script does not populate 'size' from DB for all attachments. It's missing in SELECT.
        // Re-checking attachments.php: `SELECT id, name, path, type, size, created_at FROM Attachments` - size IS included.
        $this->assertEquals(200, $responseSortSizeDesc['data']['data'][0]['size']); // sort_A.txt
        $this->assertEquals(150, $responseSortSizeDesc['data']['data'][1]['size']); // sort_B.txt
        $this->assertEquals(100, $responseSortSizeDesc['data']['data'][2]['size']); // sort_C.txt
    }

    public function testGetAllAttachmentsPagination()
    {
        // Create 12 attachments (perPage is 10 by default in API)
        for ($i = 1; $i <= 12; $i++) {
            $this->request('POST', 'api/attachments.php', 
                ['note_id' => self::$testNoteId], 
                ['attachmentFile' => $this->createDummyFile("page_attach_{$i}.txt", 'text/plain', 100 + $i)]
            );
        }

        // Get page 1
        $responsePage1 = $this->request('GET', 'api/attachments.php', ['per_page' => 5, 'page' => 1, 'sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responsePage1['status']);
        $this->assertCount(5, $responsePage1['data']['data']);
        $this->assertEquals(1, $responsePage1['data']['pagination']['current_page']);
        $this->assertEquals(5, $responsePage1['data']['pagination']['per_page']);
        $this->assertGreaterThanOrEqual(12, $responsePage1['data']['pagination']['total_items']); // Total items includes previously created ones
        $this->assertEquals('page_attach_1.txt', $responsePage1['data']['data'][0]['name']);


        // Get page 2
        $responsePage2 = $this->request('GET', 'api/attachments.php', ['per_page' => 5, 'page' => 2, 'sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responsePage2['status']);
        $this->assertCount(5, $responsePage2['data']['data']);
        $this->assertEquals(2, $responsePage2['data']['pagination']['current_page']);
        $this->assertEquals('page_attach_6.txt', $responsePage2['data']['data'][0]['name']);
        
        // Get page 3 (should have remaining items)
        $responsePage3 = $this->request('GET', 'api/attachments.php', ['per_page' => 5, 'page' => 3, 'sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responsePage3['status']);
        $this->assertLessThanOrEqual(5, count($responsePage3['data']['data'])); // Could be less than 5
        $this->assertEquals(3, $responsePage3['data']['pagination']['current_page']);
         // Count could be 2 if only page_attach_10, page_attach_11, page_attach_12 are left after previous tests' items
    }
    
    // --- Test DELETE /api/attachments.php?id={id} ---
    public function testDeleteAttachmentSuccess()
    {
        $uploadResponseData = $this->request('POST', 'api/attachments.php', 
            ['note_id' => self::$testNoteId], 
            ['attachmentFile' => $this->createDummyFile('to_delete.txt', 'text/plain', 50)]
        );
        $this->assertEquals('success', $uploadResponseData['status']);
        $attachmentData = $uploadResponseData['data'];
        $attachmentId = $attachmentData['id'];
        $filePathOnDisk = self::$tempUploadsDir . '/' . $attachmentData['path'];
        $this->assertFileExists($filePathOnDisk, "File should exist before deletion.");

        // Simulate DELETE using POST with _method override
        $deleteResponse = $this->request('POST', 'api/attachments.php', [
            'id' => $attachmentId,
            '_method' => 'DELETE'
        ]);

        $this->assertTrue($deleteResponse['success']);
        $this->assertEquals($attachmentId, $deleteResponse['data']['deleted_attachment_id']);

        // Verify file is removed from UPLOADS_DIR
        $this->assertFileDoesNotExist($filePathOnDisk);

        // Verify DB record is removed
        $stmt = self::$pdo->prepare("SELECT * FROM Attachments WHERE id = :id");
        $stmt->execute([':id' => $attachmentId]);
        $this->assertFalse($stmt->fetch());
    }

    public function testDeleteAttachmentFailureInvalidId()
    {
        $response = $this->request('POST', 'api/attachments.php', [
            'id' => 99999, // Non-existent ID
            '_method' => 'DELETE'
        ]);
        $this->assertFalse($response['success']);
        $this->assertEquals('Attachment not found.', $response['error']['message']);
    }

    public function testDeleteAttachmentFailureNoId()
    {
        $response = $this->request('POST', 'api/attachments.php', [
            '_method' => 'DELETE' // No ID
        ]);
        $this->assertFalse($response['success']);
        $this->assertEquals('Attachment ID is required for deletion.', $response['error']['message']);
    }
}
?>
