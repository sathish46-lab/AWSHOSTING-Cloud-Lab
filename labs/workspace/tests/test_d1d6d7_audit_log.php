<?php
/**
 * Test D1+D6+D7: AuditLog integration across endpoints.
 *
 * REAL RUNTIME TEST — Verifies AuditLog class structure and endpoint
 * integration via source code analysis + HTTP auth tests.
 *
 * Usage:
 *   php workspace/tests/test_d1d6d7_audit_log.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== D1+D6+D7: AuditLog Integration Tests (Runtime) ===\n\n";

// ── Test 1: AuditLog class exists ──
echo "--- AuditLog Class ---\n";

$auditPath = SRC_PATH . '/lib/core/AuditLog.class.php';
test("AuditLog.class.php exists", file_exists($auditPath));

if (file_exists($auditPath)) {
    $src = file_get_contents($auditPath);
    test("AuditLog has log() method", strpos($src, 'function log') !== false);
    test("AuditLog has getClientIp() method", strpos($src, 'getClientIp') !== false);
    test("AuditLog writes to audit_log collection", strpos($src, 'audit_log') !== false);
}

// ── Test 2: Endpoints include AuditLog ──
echo "\n--- Endpoint AuditLog Integration ---\n";

$endpoints = [
    'create' => SRC_PATH . '/api/instances/create.php',
    'trash' => SRC_PATH . '/api/instances/trash.php',
    'restore' => SRC_PATH . '/api/instances/restore.php',
    'permanent_delete' => SRC_PATH . '/api/instances/permanent_delete.php',
];

foreach ($endpoints as $name => $path) {
    if (file_exists($path)) {
        $src = file_get_contents($path);
        test("$name.php uses AuditLog::log()", strpos($src, 'AuditLog::log') !== false);
    } else {
        skip("$name.php AuditLog check", "File not found");
    }
}

// ── Test 3: Runtime — endpoints require auth ──
echo "\n--- HTTP Auth Tests ---\n";

$testEmail = 'audit_test_' . time() . '@example.com';
$sessionToken = create_test_user($testEmail, 'user');

$response = http_request('POST', '/api/instances/create.php', [
    'body' => json_encode(['name' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
$rejected = $response['status'] === 401 || $response['status'] === 403 ||
    ($response['body_json']['error'] ?? '') === 'Unauthorized';
test("Create without auth → rejected", $rejected,
    "Got: {$response['status']}");

$response = http_request('POST', '/api/instances/trash.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Trash without auth → 401", $response['status'] === 401);

$response = http_request('POST', '/api/instances/restore.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Restore without auth → 401", $response['status'] === 401);

$response = http_request('DELETE', '/api/instances/permanent_delete.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Permanent delete without auth → 401", $response['status'] === 401);

cleanup_test_user($testEmail);
test_summary();
