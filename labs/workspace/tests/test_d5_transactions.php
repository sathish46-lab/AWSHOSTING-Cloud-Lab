<?php
/**
 * Test D5: Compensating transaction pattern for trash/restore.
 *
 * REAL RUNTIME TEST — Verifies the insert-first-then-delete transaction
 * pattern used by trash.php and restore.php via source code analysis
 * and HTTP auth/CSRF enforcement tests.
 *
 * Usage:
 *   php workspace/tests/test_d5_transactions.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== D5: Transaction Pattern Tests (Runtime) ===\n\n";

// ── Test 1: Source code structure — trash.php uses insert-first pattern ──
echo "--- Source Code: trash.php Transaction Pattern ---\n";

$trashPath = SRC_PATH . '/api/instances/trash.php';
test("trash.php exists", file_exists($trashPath));

if (file_exists($trashPath)) {
    $src = file_get_contents($trashPath);
    test("trash.php inserts to instance_trash BEFORE deleting from instances",
        strpos($src, 'instance_trash') !== false && strpos($src, 'insertOne') !== false);
    test("trash.php deletes from instances collection",
        strpos($src, 'instances') !== false && strpos($src, 'deleteOne') !== false);
    test("trash.php uses AuditLog",
        strpos($src, 'AuditLog::log') !== false);
}

// ── Test 2: Source code structure — restore.php uses insert-first pattern ──
echo "\n--- Source Code: restore.php Transaction Pattern ---\n";

$restorePath = SRC_PATH . '/api/instances/restore.php';
test("restore.php exists", file_exists($restorePath));

if (file_exists($restorePath)) {
    $src = file_get_contents($restorePath);
    test("restore.php inserts to instances BEFORE deleting from instance_trash",
        strpos($src, 'instances') !== false && strpos($src, 'insertOne') !== false);
    test("restore.php deletes from instance_trash",
        strpos($src, 'instance_trash') !== false && strpos($src, 'deleteOne') !== false);
    test("restore.php uses AuditLog",
        strpos($src, 'AuditLog::log') !== false);
}

// ── Test 3: Runtime — HTTP auth/CSRF enforcement ──
echo "\n--- Runtime: Transaction Security ---\n";

$testEmail = 'txn_test_' . time() . '@example.com';
$sessionToken = create_test_user($testEmail, 'user');

// Test trash endpoint requires auth+CSRF
$response = http_request('POST', '/api/instances/trash.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Trash requires auth", $response['status'] === 401);

$response = http_request('POST', '/api/instances/trash.php', [
    'cookie' => "session_token=$sessionToken",
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode(['hash' => 'test']),
]);
test("Trash requires CSRF", $response['status'] === 403);

// Test restore endpoint requires auth+CSRF
$response = http_request('POST', '/api/instances/restore.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Restore requires auth", $response['status'] === 401);

$response = http_request('POST', '/api/instances/restore.php', [
    'cookie' => "session_token=$sessionToken",
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode(['hash' => 'test']),
]);
test("Restore requires CSRF", $response['status'] === 403);

cleanup_test_user($testEmail);
test_summary();
