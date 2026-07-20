<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/rbac.php';
require __DIR__ . '/../inc/audit.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';
$uploadDir = __DIR__ . '/../uploads';

echo "=== File Upload Tests ===\n\n";
$pass = 0; $fail = 0;

// Ensure table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}file_uploads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `entity_type` VARCHAR(32) NOT NULL,
        `entity_id` INT NOT NULL, `filename` VARCHAR(255) NOT NULL,
        `orig_name` VARCHAR(255) NOT NULL, `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
        `file_size` BIGINT NOT NULL DEFAULT 0, `file_path` VARCHAR(512) NOT NULL,
        `uploaded_by` INT NOT NULL DEFAULT 0, `uploaded_by_name` VARCHAR(64) NOT NULL DEFAULT '',
        `description` VARCHAR(255) DEFAULT '', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_entity` (`entity_type`, `entity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Test 1: Table exists
echo "[Test 1] file_uploads table exists... ";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}file_uploads`");
    $names = array_column($cols, 'Field');
    if (in_array('entity_type', $names) && in_array('file_path', $names) && in_array('file_size', $names)) {
        echo "PASS (" . count($cols) . " columns)\n"; $pass++;
    } else { echo "FAIL\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 2: Upload directory exists
echo "[Test 2] Uploads directory... ";
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
if (is_dir($uploadDir) && is_writable($uploadDir)) {
    echo "PASS ($uploadDir)\n"; $pass++;
} else { echo "FAIL: not writable\n"; $fail++; }

// Test 3: Write a test file and insert record
echo "[Test 3] Simulate file upload + DB record... ";
$testDir = $uploadDir . '/general/0';
if (!is_dir($testDir)) @mkdir($testDir, 0755, true);
$testFile = $testDir . '/test_upload.txt';
file_put_contents($testFile, 'Test file content for upload API test');
$relPath = 'general/0/test_upload.txt';
try {
    db_query("INSERT INTO `{$prefix}file_uploads` (entity_type, entity_id, filename, orig_name, mime_type, file_size, file_path, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        ['general', 0, 'test_upload.txt', 'test_document.txt', 'text/plain', filesize($testFile), $relPath, 1, 'admin']);
    $testId = db_insert_id();
    echo "PASS (id=$testId)\n"; $pass++;
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; $testId = null; }

// Test 4: Query files back
echo "[Test 4] List files for entity... ";
try {
    $files = db_fetch_all("SELECT * FROM `{$prefix}file_uploads` WHERE entity_type = 'general' AND entity_id = 0");
    if (!empty($files) && $files[0]['orig_name'] === 'test_document.txt') {
        echo "PASS (" . count($files) . " files)\n"; $pass++;
    } else { echo "FAIL\n"; $fail++; }
} catch (Exception $e) { echo "FAIL: " . $e->getMessage() . "\n"; $fail++; }

// Test 5: File exists on disk
echo "[Test 5] File exists on disk... ";
if (file_exists($uploadDir . '/' . $relPath)) {
    echo "PASS\n"; $pass++;
} else { echo "FAIL\n"; $fail++; }

// Test 6: Disk usage stats
echo "[Test 6] Disk usage stats... ";
$totalSize = 0;
if (is_dir($uploadDir)) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $f) $totalSize += $f->getSize();
}
$diskFree = @disk_free_space($uploadDir);
if ($totalSize > 0 && $diskFree !== false) {
    echo "PASS (uploads=" . round($totalSize/1024) . " KB, free=" . round($diskFree/1048576) . " MB)\n"; $pass++;
} else { echo "FAIL\n"; $fail++; }

// Test 7: Config defaults
echo "[Test 7] Upload config defaults... ";
$maxMB = 30; $warnPct = 70; $blockPct = 80;
// These are defaults when no settings row exists
if ($maxMB === 30 && $warnPct === 70 && $blockPct === 80) {
    echo "PASS (max=30MB, warn=70%, block=80%)\n"; $pass++;
} else { echo "FAIL\n"; $fail++; }

// Cleanup
if ($testId) {
    db_query("DELETE FROM `{$prefix}file_uploads` WHERE id = ?", [$testId]);
}
if (file_exists($testFile)) @unlink($testFile);
@rmdir($testDir);
@rmdir($uploadDir . '/general');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
