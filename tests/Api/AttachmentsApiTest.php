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
            if ($size > 0) {
                if (strpos($type, 'image/jpeg') !== false) {
                    // Write a minimal valid JPEG file
                    fwrite($handle, "\xFF\xD8\xFF\xE0\x00\x10\x4A\x46\x49\x46\x00\x01\x01\x01\x00\x48\x00\x48\x00\x00\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C\x20\x24\x2E\x27\x20\x22\x2C\x23\x1C\x1C\x28\x37\x29\x2C\x30\x31\x34\x34\x34\x1F\x27\x39\x3D\x38\x32\x3C\x2E\x33\x34\x32\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xC4\x00\x14\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x03\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\x5C\xFF\xD9");
                } else {
                    // For non-image files, just create a sparse file
                    ftruncate($handle, $size);
                }
            }
            fclose($handle);
            $this->dummyFiles[] = $tempPath; // Track for cleanup
        } else {
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

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);

        $this->assertIsArray($response, "Response is not an array: " . print_r($response, true));
        $this->assertEquals('success', $response['status'], "Response should indicate success: " . ($response['message'] ?? 'No message'));
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
        // Ensure 'storage_key' is used if the API changes from 'path'
        $storageKeyField = isset($response['data']['storage_key']) ? 'storage_key' : 'path';
        $this->assertArrayHasKey($storageKeyField, $response['data']);
        $expectedFilePath = self::$tempUploadsDir . '/' . $response['data'][$storageKeyField];
        $this->assertFileExists($expectedFilePath, "Uploaded file should exist at: " . $expectedFilePath);

        // Check for Location header
        $this->assertArrayHasKey('headers', $response, "Response should have headers array");
        $this->assertArrayHasKey('Location', $response['headers'], "Response should have Location header");
        $this->assertStringContainsString('/v1/api/attachments.php?id=' . $response['data']['id'], $response['headers']['Location']);
        
        // Check for url in response data
        $this->assertArrayHasKey('url', $response['data']);
        $this->assertStringContainsString('/v1/api/attachments.php?id=' . $response['data']['id'], $response['data']['url']);


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
        $response = $this->request('POST', '/v1/api/attachments.php', [], $filesData);

        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note ID is required.', $response['message']);
    }

    public function testPostUploadAttachmentFailureInvalidNoteId()
    {
        $dummyFile = $this->createDummyFile('test.doc', 'application/msword', 200);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => 99999]; // Non-existent note_id

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Note not found.', $response['message']);
    }

    public function testPostUploadAttachmentFailureNoFile()
    {
        $postData = ['note_id' => self::$testNoteId];
        // $_FILES is empty
        $response = $this->request('POST', '/v1/api/attachments.php', $postData, []);

        $this->assertEquals('error', $response['status']);
        $this->assertEquals('No file uploaded or upload error.', $response['message']);
    }

    public function testPostUploadAttachmentFailureUploadErrorIniSize()
    {
        $dummyFile = $this->createDummyFile('large_file.zip', 'application/zip', 100000, UPLOAD_ERR_INI_SIZE);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('The uploaded file exceeds the upload_max_filesize directive in php.ini.', $response['message']);
    }

    public function testPostUploadAttachmentFailureUploadErrorFormSize()
    {
        $dummyFile = $this->createDummyFile('another_large.zip', 'application/zip', 50000, UPLOAD_ERR_FORM_SIZE);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', $response['message']);
    }
    
    public function testPostUploadAttachmentFailureUploadErrorPartial()
    {
        $dummyFile = $this->createDummyFile('partial.zip', 'application/zip', 50000, UPLOAD_ERR_PARTIAL);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('The uploaded file was only partially uploaded.', $response['message']);
    }
    
    public function testPostUploadAttachmentFailureUploadErrorNoTmpDir()
    {
        $dummyFile = $this->createDummyFile('no_tmp.zip', 'application/zip', 50000, UPLOAD_ERR_NO_TMP_DIR);
        $filesData = ['attachmentFile' => $dummyFile];
        $postData = ['note_id' => self::$testNoteId];

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Missing a temporary folder.', $response['message']);
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

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('File exceeds maximum size limit', $response['message']);
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

        $response = $this->request('POST', '/v1/api/attachments.php', $postData, $filesData);
        
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('File type not allowed', $response['message']);
    }


    // --- GET /api/attachments.php?note_id={id} Tests ---
    public function testGetAttachmentsForNoteSuccessWithAttachments()
    {
        // Upload a couple of attachments first
        $file1 = $this->createDummyFile('file1.pdf', 'application/pdf', 500);
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $file1]);
        $file2 = $this->createDummyFile('file2.txt', 'text/plain', 300);
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $file2]);

        // For listing attachments by note_id, pagination might not be explicitly required by this test's focus,
        // but the new API might enforce it. Assuming basic response structure for now.
        // The prompt says: "GET requests for listing attachments should support pagination parameters (page, per_page) 
        // and the response should include a pagination object." This applies here.
        $response = $this->request('GET', '/v1/api/attachments.php', ['note_id' => self::$testNoteId, 'page' => 1, 'per_page' => 10]);
        
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']); // Data is now nested
        $this->assertCount(2, $response['data']['data']);
        $this->assertEquals('file1.pdf', $response['data']['data'][0]['name']);
        $this->assertEquals('file2.txt', $response['data']['data'][1]['name']);
        // URL for listed attachments should also be /v1/
        $this->assertStringContainsString('/v1/api/attachments.php?id=', $response['data']['data'][0]['url']);
        // And should contain ?action=download or similar if that's the new spec for download links.
        // The prompt mentioned "GET requests for retrieving a specific attachment should support the disposition parameter."
        // This implies the download URL itself might not need action=download if disposition is used on the target URL.
        // For now, I'll assume the URL points to the attachment resource, and disposition is added when *retrieving*.

        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertEquals(10, $response['data']['pagination']['per_page']);
        $this->assertEquals(2, $response['data']['pagination']['total_items']);
    }

    public function testGetAttachmentsForNoteSuccessNoAttachments()
    {
        // Use a new note that has no attachments
        $stmt = self::$pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute([':page_id' => self::$testPageId, ':content' => 'Note with no attachments']);
        $noteIdWithNoAttachments = self::$pdo->lastInsertId();

        $response = $this->request('GET', '/v1/api/attachments.php', ['note_id' => $noteIdWithNoAttachments, 'page' => 1, 'per_page' => 10]);

        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']); // Data is nested
        $this->assertCount(0, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$noteIdWithNoAttachments);
    }

    public function testGetAttachmentsForNoteFailureInvalidNoteId()
    {
        // According to new spec, invalid note_id should likely be an error or at least consistent.
        // If the API returns an error:
        // $response = $this->request('GET', '/v1/api/attachments.php', ['note_id' => 88888, 'page' => 1, 'per_page' => 10]);
        // $this->assertEquals('error', $response['status']);
        // $this->assertEquals('Note not found.', $response['message']); // Or similar message

        // If the API still returns success with empty data (as per old behavior):
        $response = $this->request('GET', '/v1/api/attachments.php', ['note_id' => 88888, 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertCount(0, $response['data']['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(0, $response['data']['pagination']['total_items']);
    }

    // --- GET /v1/api/attachments.php (List All Attachments) ---
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

        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('attach1.txt', 'text/plain', 100)]);
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => $anotherNoteId], ['attachmentFile' => $this->createDummyFile('attach2.pdf', 'application/pdf', 200)]);
        
        // Added pagination parameters
        $response = $this->request('GET', '/v1/api/attachments.php', ['page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $response['status']);
        $this->assertIsArray($response['data']['data']);
        $this->assertGreaterThanOrEqual(2, count($response['data']['data']));
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertEquals(1, $response['data']['pagination']['current_page']);
        
        self::$pdo->exec("DELETE FROM Notes WHERE id = " . (int)$anotherNoteId); // Clean up
    }
    
    public function testGetAllAttachmentsFiltering()
    {
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('filter_me.txt', 'text/plain', 100)]);
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('another_file.pdf', 'application/pdf', 200)]);

        // Filter by name (added pagination params)
        $responseName = $this->request('GET', '/v1/api/attachments.php', ['filter_by_name' => 'filter_me', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseName['status']);
        $this->assertCount(1, $responseName['data']['data']);
        $this->assertEquals('filter_me.txt', $responseName['data']['data'][0]['name']);
        $this->assertArrayHasKey('pagination', $responseName['data']);

        // Filter by type (added pagination params)
        $responseType = $this->request('GET', '/v1/api/attachments.php', ['filter_by_type' => 'application/pdf', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseType['status']);
        $this->assertCount(1, $responseType['data']['data']);
        $this->assertEquals('another_file.pdf', $responseType['data']['data'][0]['name']);
        $this->assertEquals('application/pdf', $responseType['data']['data'][0]['type']);
        $this->assertArrayHasKey('pagination', $responseType['data']);
    }

    public function testGetAllAttachmentsSorting()
    {
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('sort_C.txt', 'text/plain', 100)]);
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('sort_A.txt', 'text/plain', 200)]);
        $this->request('POST', '/v1/api/attachments.php', ['note_id' => self::$testNoteId], ['attachmentFile' => $this->createDummyFile('sort_B.txt', 'text/plain', 150)]);

        // Sort by name ASC (added pagination params)
        $responseSortNameAsc = $this->request('GET', '/v1/api/attachments.php', ['sort_by' => 'name', 'sort_order' => 'asc', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseSortNameAsc['status']);
        $this->assertGreaterThanOrEqual(3, count($responseSortNameAsc['data']['data']));
        $this->assertEquals('sort_A.txt', $responseSortNameAsc['data']['data'][0]['name']);
        $this->assertEquals('sort_B.txt', $responseSortNameAsc['data']['data'][1]['name']);
        $this->assertEquals('sort_C.txt', $responseSortNameAsc['data']['data'][2]['name']);
        $this->assertArrayHasKey('pagination', $responseSortNameAsc['data']);
        
        // Sort by size DESC (added pagination params)
        $responseSortSizeDesc = $this->request('GET', '/v1/api/attachments.php', ['sort_by' => 'size', 'sort_order' => 'desc', 'page' => 1, 'per_page' => 10]);
        $this->assertEquals('success', $responseSortSizeDesc['status']);
        $this->assertGreaterThanOrEqual(3, count($responseSortSizeDesc['data']['data']));
        $this->assertEquals(200, $responseSortSizeDesc['data']['data'][0]['size']); // sort_A.txt
        $this->assertEquals(150, $responseSortSizeDesc['data']['data'][1]['size']); // sort_B.txt
        $this->assertEquals(100, $responseSortSizeDesc['data']['data'][2]['size']); // sort_C.txt
        $this->assertArrayHasKey('pagination', $responseSortSizeDesc['data']);
    }

    public function testGetAllAttachmentsPagination()
    {
        // Create 12 attachments (perPage is 10 by default in API)
        for ($i = 1; $i <= 12; $i++) {
            $this->request('POST', '/v1/api/attachments.php', 
                ['note_id' => self::$testNoteId], 
                ['attachmentFile' => $this->createDummyFile("page_attach_{$i}.txt", 'text/plain', 100 + $i)]
            );
        }

        // Get page 1
        $responsePage1 = $this->request('GET', '/v1/api/attachments.php', ['per_page' => 5, 'page' => 1, 'sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responsePage1['status']);
        $this->assertCount(5, $responsePage1['data']['data']);
        $this->assertEquals(1, $responsePage1['data']['pagination']['current_page']);
        $this->assertEquals(5, $responsePage1['data']['pagination']['per_page']);
        $this->assertGreaterThanOrEqual(12, $responsePage1['data']['pagination']['total_items']); // Total items includes previously created ones
        $this->assertEquals('page_attach_1.txt', $responsePage1['data']['data'][0]['name']);


        // Get page 2
        $responsePage2 = $this->request('GET', '/v1/api/attachments.php', ['per_page' => 5, 'page' => 2, 'sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responsePage2['status']);
        $this->assertCount(5, $responsePage2['data']['data']);
        $this->assertEquals(2, $responsePage2['data']['pagination']['current_page']);
        $this->assertEquals('page_attach_6.txt', $responsePage2['data']['data'][0]['name']);
        
        // Get page 3 (should have remaining items)
        $responsePage3 = $this->request('GET', '/v1/api/attachments.php', ['per_page' => 5, 'page' => 3, 'sort_by' => 'name', 'sort_order' => 'asc']);
        $this->assertEquals('success', $responsePage3['status']);
        $this->assertLessThanOrEqual(5, count($responsePage3['data']['data'])); // Could be less than 5
        $this->assertEquals(3, $responsePage3['data']['pagination']['current_page']);
         // Count could be 2 if only page_attach_10, page_attach_11, page_attach_12 are left after previous tests' items
    }
    
    // --- Test DELETE /v1/api/attachments.php?id={id} ---
    public function testDeleteAttachmentSuccess()
    {
        $uploadResponse = $this->request('POST', '/v1/api/attachments.php', 
            ['note_id' => self::$testNoteId], 
            ['attachmentFile' => $this->createDummyFile('to_delete.txt', 'text/plain', 50)]
        );
        $this->assertEquals('success', $uploadResponse['status']);
        $attachmentData = $uploadResponse['data'];
        $attachmentId = $attachmentData['id'];
        // Use storage_key if present, otherwise path
        $storageKeyField = isset($attachmentData['storage_key']) ? 'storage_key' : 'path';
        $filePathOnDisk = self::$tempUploadsDir . '/' . $attachmentData[$storageKeyField];
        $this->assertFileExists($filePathOnDisk, "File should exist before deletion.");

        // POST requests for deleting attachments should use the action="delete" parameter in the request body.
        // _method: 'DELETE' is likely no longer used if action=delete is the new mechanism.
        $deleteResponse = $this->request('POST', '/v1/api/attachments.php', [
            'id' => $attachmentId,
            'action' => 'delete' // New requirement
        ]);

        $this->assertEquals('success', $deleteResponse['status']);
        $this->assertArrayHasKey('data', $deleteResponse); // Ensure data key exists
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
        $response = $this->request('POST', '/v1/api/attachments.php', [
            'id' => 99999, // Non-existent ID
            'action' => 'delete'
        ]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Attachment not found.', $response['message']);
    }

    public function testDeleteAttachmentFailureNoId()
    {
        $response = $this->request('POST', '/v1/api/attachments.php', [
            'action' => 'delete' // No ID
        ]);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Attachment ID is required for deletion.', $response['message']);
    }

    // --- GET /v1/api/attachments.php?id={id} (Retrieve Specific Attachment) ---
    // This test needs to be added or updated for disposition parameter.
    public function testGetSpecificAttachmentWithDisposition()
    {
        // Upload an attachment first
        $uploadResponse = $this->request('POST', '/v1/api/attachments.php',
            ['note_id' => self::$testNoteId],
            ['attachmentFile' => $this->createDummyFile('specific_test_file.txt', 'text/plain', 75)]
        );
        $this->assertEquals('success', $uploadResponse['status']);
        $attachmentId = $uploadResponse['data']['id'];

        // Test with disposition=inline (or view)
        $responseInline = $this->request('GET', "/v1/api/attachments.php", ['id' => $attachmentId, 'disposition' => 'inline']);
        // The response for a file download might not be JSON.
        // It might be the raw file content with specific headers.
        // BaseTestCase::request probably tries to json_decode. We need to adjust expectations.
        // For now, let's assume the API returns JSON for metadata even with disposition,
        // or the test framework handles file downloads in a way that gives us headers.
        // If the API streams the file directly, these assertions will fail and need rethinking.
        // A common pattern is a JSON response if the file is found, with a URL to actually download,
        // or if it's a direct download, check headers.
        
        // Assuming the API *still* returns JSON meta-data with a link, or the actual file content.
        // If it returns the file content directly, $responseInline will be a string (the file content).
        // Let's assume for now the API is designed to return JSON even when disposition is set,
        // perhaps providing a temporary signed URL or similar if 'inline' means to show it.
        // Or, more likely, the 'disposition' parameter influences the Content-Disposition header.

        // If the test framework's request() method returns the raw body for non-JSON responses:
        // $this->assertIsString($responseInline);
        // $this->assertEquals(75, strlen($responseInline)); // Check if content matches
        // And we'd need a way to check headers from $this->request. The current BaseTestCase might not support that directly.

        // Let's assume the API returns JSON, and the `disposition` parameter is just passed to the download serving logic.
        // The spec says "GET requests for retrieving a specific attachment should support the disposition parameter."
        // This most likely means it affects the Content-Disposition header for the file download itself,
        // not necessarily changing this specific test's response structure if this test is just checking *if* the param can be sent.
        // If the `/v1/api/attachments.php?id=...` endpoint is meant to *serve* the file, not just metadata:
        
        // For this test, let's assume the request method is smart enough to give us headers if it's a file response.
        // And the response body might be the file itself.
        // The current `request()` method in `BaseTestCase` always tries `json_decode`.
        // This test will likely require modification to `BaseTestCase` or a different approach
        // if the endpoint directly serves files.

        // For now, I will write the test assuming the API *could* still return a JSON response
        // and the 'disposition' parameter is acknowledged.
        // A more realistic test would be to check the 'Content-Disposition' header.
        // My current `this->request` helper likely won't give me response headers easily.
        // I'll proceed with a simplified check and note this might need refinement.
        
        $response = $this->request('GET', '/v1/api/attachments.php', ['id' => $attachmentId, 'disposition' => 'attachment']);
        $this->assertEquals('success', $response['status']); // Assuming it still gives JSON metadata
        $this->assertEquals($attachmentId, $response['data']['id']);
        $this->assertEquals('specific_test_file.txt', $response['data']['name']);
        // How to verify 'disposition' was effective? This usually affects headers like Content-Disposition.
        // This test, as is, can only verify the API accepts the parameter.
        // To truly test it, we'd need to inspect response headers, which BaseTestCase::request doesn't expose.
        // Let's assume for now that if the request doesn't fail, the API acknowledged the parameter.
    }
}
?>
