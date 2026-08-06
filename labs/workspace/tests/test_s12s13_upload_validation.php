<?php
/**
 * Test: S12+S13 — File upload validation and MinIO ACL fix
 * 
 * Verifies:
 * 1. Storage::upload() uses private ACL by default
 * 2. Storage::getSignedUrl() method exists
 * 3. upload_file.php has file type allowlist
 * 4. file_upload.php has file type allowlist
 * 5. Both endpoints block double-extension attacks
 */

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

// Test 1: Storage::upload() uses private ACL
$storageContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/Storage.class.php');
test('Storage::upload() defaults to private ACL', strpos($storageContent, "\$acl = 'private'") !== false);
test('Storage::upload() no longer hardcodes public-read', strpos($storageContent, "'ACL' => 'public-read'") === false);

// Test 2: getSignedUrl method exists
test('Storage::getSignedUrl() method exists', strpos($storageContent, 'function getSignedUrl') !== false);

// Test 3: upload_file.php has allowlist
$uploadContent = file_get_contents(__DIR__ . '/../../htdocs/src/api/account/upload_file.php');
test('upload_file.php has allowedExtensions array', strpos($uploadContent, 'allowedExtensions') !== false);
test('upload_file.php blocks double-extension', strpos($uploadContent, 'count($parts) > 2') !== false);

// Test 4: file_upload.php has allowlist
$fileUploadContent = file_get_contents(__DIR__ . '/../../htdocs/src/api/instances/file_upload.php');
test('file_upload.php has allowedExtensions array', strpos($fileUploadContent, 'allowedExtensions') !== false);
test('file_upload.php blocks double-extension', strpos($fileUploadContent, 'count($parts) > 2') !== false);

// Test 5: Dangerous extensions are NOT in allowlists
$dangerousExts = ['php', 'phtml', 'php5', 'php7', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'msi', 'jsp', 'asp', 'aspx'];
foreach ($dangerousExts as $ext) {
    // For file_upload.php, .sh is allowed (lab context) but others should be blocked
    if ($ext !== 'sh') {
        test("upload_file.php blocks .{$ext}", strpos($uploadContent, "'{$ext}'") === false);
    }
}

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
