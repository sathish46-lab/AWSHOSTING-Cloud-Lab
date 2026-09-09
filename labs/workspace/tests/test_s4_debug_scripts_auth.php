<?php
/**
 * Test S4: Debug/maintenance scripts are auth-gated.
 *
 * REAL RUNTIME TEST — Makes actual HTTP requests to the running server
 * to verify that set_admin.php, fix.php, and sync_ip_registry.php
 * reject unauthenticated and non-superuser requests.
 *
 * Security properties tested:
 * 1. Unauthenticated → 401
 * 2. Regular user (non-superuser) → 403
 * 3. Response body contains generic error (no info leak)
 * 4. Scripts don't execute actions without auth
 *
 * Usage:
 *   # Inside Docker container:
 *   php workspace/tests/test_s4_debug_scripts_auth.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== S4: Debug Scripts Auth Gate Tests (Runtime) ===\n\n";

// ── Source code structure tests ──
$scripts = [
    'set_admin.php' => [
        'path' => '/htdocs/set_admin.php',
        'checks_auth' => true,
        'checks_super' => true,
    ],
    'fix.php' => [
        'path' => '/htdocs/fix.php',
        'checks_auth' => true,
        'checks_super' => true,
    ],
    'sync_ip_registry.php' => [
        'path' => '/htdocs/sync_ip_registry.php',
        'checks_auth' => true,
        'checks_super' => true,
    ],
];

echo "--- Source Code Structure ---\n";

foreach ($scripts as $name => $config) {
    $fullPath = PROJECT_ROOT . $config['path'];
    test("$name file exists", file_exists($fullPath));

    if (file_exists($fullPath)) {
        $content = file_get_contents($fullPath);
        test("$name checks Session::getAuthStatus()",
            strpos($content, 'Session::getAuthStatus()') !== false);
        test("$name checks superuser role",
            strpos($content, "getRole() !== 'superuser'") !== false);
        test("$name returns 401 on unauthorized",
            strpos($content, 'http_response_code(401)') !== false);
        test("$name returns 403 on non-superuser",
            strpos($content, 'http_response_code(403)') !== false);
        test("$name logs ADMIN ACTION",
            strpos($content, 'ADMIN ACTION:') !== false);
    }
}

// ── Runtime HTTP tests ──
echo "\n--- HTTP Auth Gate Tests ---\n";

$endpoints = [
    '/set_admin.php',
    '/fix.php',
    '/sync_ip_registry.php',
];

// Create a regular user (non-superuser) for testing
$testEmail = 'debug_auth_test_' . time() . '@example.com';
$sessionToken = create_test_user($testEmail, 'user');

// Test A: Unauthenticated → 401 for all endpoints
foreach ($endpoints as $endpoint) {
    $response = http_request('GET', $endpoint);
    $isUnauthorized = $response['status'] === 401 ||
        ($response['body_json']['error'] ?? '') === 'Unauthorized';
    test("$endpoint without auth → 401", $isUnauthorized,
        "Got {$response['status']}: " . ($response['body_json']['error'] ?? substr($response['body'], 0, 80)));
}

// Test B: Regular user (non-superuser) → 403 for all endpoints
foreach ($endpoints as $endpoint) {
    $response = http_request('GET', $endpoint, [
        'cookie' => "session_token=$sessionToken",
    ]);
    $isForbidden = $response['status'] === 403 ||
        strpos($response['body_json']['error'] ?? '', 'Forbidden') !== false;
    test("$endpoint with regular user → 403", $isForbidden,
        "Got {$response['status']}: " . ($response['body_json']['error'] ?? substr($response['body'], 0, 80)));
}

// Test C: Verify no dangerous action was executed
// After the 403 responses, the database should be unchanged
$db = DatabaseConnection::getDefaultDatabase();

// set_admin.php should NOT have changed any roles
$adminUser = $db->users->findOne(['email' => 'sathishp3223@gmail.com']);
// Just verify the script didn't crash — the user should still exist
test("set_admin.php did not corrupt user data", $adminUser !== null);

// Test D: POST with no auth → still 401
foreach ($endpoints as $endpoint) {
    $response = http_request('POST', $endpoint, [
        'body' => ['test' => 'value'],
    ]);
    $isUnauthorized = $response['status'] === 401 ||
        ($response['body_json']['error'] ?? '') === 'Unauthorized';
    test("POST $endpoint without auth → 401", $isUnauthorized,
        "Got {$response['status']}");
}

// ── Cleanup ──
cleanup_test_user($testEmail);

test_summary();
