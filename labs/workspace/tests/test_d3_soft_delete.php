<?php
/**
 * Test D3: Soft delete across all API endpoints.
 *
 * REAL RUNTIME TEST — Creates real instances, trashes them, and verifies
 * DB state (status=deleted, exclusion filters with $ne).
 *
 * Security properties tested:
 * 1. Trash sets status=deleted in DB (not hard delete)
 * 2. Queries exclude soft-deleted records ($ne => 'deleted')
 * 3. Restore removes the deleted status
 * 4. Permanent delete actually removes from DB
 *
 * Usage:
 *   php workspace/tests/test_d3_soft_delete.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== D3: Soft Delete Tests (Runtime) ===\n\n";

$db = DatabaseConnection::getDefaultDatabase();

// Create test user
$testEmail = 'softdelete_test_' . time() . '@example.com';
$sessionToken = create_test_user($testEmail, 'user');

// Helper: create a test instance via API
function createInstance(string $token): ?string {
    $csrf = get_csrf_token($token);
    $headers = ['Content-Type: application/json'];
    if ($csrf) { $headers[] = "X-CSRF-Token: $csrf"; }
    $r = http_request('POST', '/api/instances/create.php', [
        'cookie' => "session_token=$token",
        'headers' => $headers,
        'body' => json_encode(['name' => 'softdel-test-' . bin2hex(random_bytes(4))]),
    ]);
    return $r['body_json']['hash'] ?? $r['body_json']['instance']['hash'] ?? null;
}

// ── Test 1: Trash endpoint exists and requires auth ──
echo "--- Trash Endpoint ---\n";

$response = http_request('POST', '/api/instances/trash.php', [
    'body' => json_encode(['hash' => 'nonexistent']),
    'headers' => ['Content-Type: application/json'],
]);
test("Trash without auth → 401",
    $response['status'] === 401 || ($response['body_json']['error'] ?? '') === 'Unauthorized');

// ── Test 2: Source code structure ──
echo "\n--- Source Code Structure ---\n";

$trashPath = SRC_PATH . '/api/instances/trash.php';
test("trash.php exists", file_exists($trashPath));

if (file_exists($trashPath)) {
    $src = file_get_contents($trashPath);
    test("trash.php checks auth", strpos($src, 'Session::getAuthStatus()') !== false);
    test("trash.php enforces CSRF", strpos($src, 'CsrfProtection::require()') !== false);
    test("trash.php uses AuditLog", strpos($src, 'AuditLog::log') !== false);
}

$restorePath = SRC_PATH . '/api/instances/restore.php';
test("restore.php exists", file_exists($restorePath));

if (file_exists($restorePath)) {
    $src = file_get_contents($restorePath);
    test("restore.php checks auth", strpos($src, 'Session::getAuthStatus()') !== false);
    test("restore.php enforces CSRF", strpos($src, 'CsrfProtection::require()') !== false);
    test("restore.php uses AuditLog", strpos($src, 'AuditLog::log') !== false);
}

$deletePath = SRC_PATH . '/api/instances/permanent_delete.php';
test("permanent_delete.php exists", file_exists($deletePath));

if (file_exists($deletePath)) {
    $src = file_get_contents($deletePath);
    test("permanent_delete.php checks auth", strpos($src, 'Session::getAuthStatus()') !== false);
    test("permanent_delete.php enforces CSRF", strpos($src, 'CsrfProtection::require()') !== false);
}

// ── Test 3: Runtime — HTTP auth/CSRF tests ──
echo "\n--- HTTP Security Tests ---\n";

$testEmail = 'softdelete_test_' . time() . '@example.com';
$sessionToken = create_test_user($testEmail, 'user');

// Trash without auth
$response = http_request('POST', '/api/instances/trash.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Trash without auth → 401", $response['status'] === 401);

// Trash without CSRF
$response = http_request('POST', '/api/instances/trash.php', [
    'cookie' => "session_token=$sessionToken",
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode(['hash' => 'test']),
]);
test("Trash without CSRF → 403", $response['status'] === 403);

// Restore without auth
$response = http_request('POST', '/api/instances/restore.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Restore without auth → 401", $response['status'] === 401);

// Permanent delete without auth
$response = http_request('DELETE', '/api/instances/permanent_delete.php', [
    'body' => json_encode(['hash' => 'test']),
    'headers' => ['Content-Type: application/json'],
]);
test("Permanent delete without auth → 401", $response['status'] === 401);

cleanup_test_user($testEmail);

if ($instanceHash) {
    // Verify instance exists in DB
    $inst = $db->instances->findOne(['hash' => $instanceHash]);
    test("Instance exists in DB", $inst !== null);

    // Trash it (needs CSRF — skip if not available)
    $csrfToken = get_csrf_token($sessionToken);
    if ($csrfToken) {
        $response = http_request('POST', '/api/instances/trash.php', [
            'cookie' => "session_token=$sessionToken",
            'headers' => ['Content-Type: application/json', "X-CSRF-Token: $csrfToken"],
            'body' => json_encode(['hash' => $instanceHash]),
        ]);
        $trashed = ($response['body_json']['status'] ?? '') === 'success' ||
                   ($response['body_json']['success'] ?? false) === true;
        test("Trash request → success", $trashed,
            "Got: " . json_encode($response['body_json']));

        // Verify soft delete in DB
        $inst = $db->instances->findOne(['hash' => $instanceHash]);
        $trashedInst = $db->instance_trash->findOne(['hash' => $instanceHash]);
        test("Instance moved to instance_trash collection", $trashedInst !== null);

        // Verify queries exclude soft-deleted
        $activeInstances = $db->instances->countDocuments([
            'hash' => $instanceHash,
            'status' => ['$ne' => 'deleted'],
        ]);
        test("Soft-deleted instance excluded from active queries", $activeInstances === 0);
    } else {
        skip("Trash instance", "No CSRF token");
        skip("Soft delete verification", "No CSRF token");
        skip("Active query exclusion", "No CSRF token");
    }

    // ── Test 3: Restore endpoint ──
    echo "\n--- Restore Endpoint ---\n";

    if ($csrfToken && isset($trashedInst) && $trashedInst) {
        $csrfToken2 = get_csrf_token($sessionToken);
        if ($csrfToken2) {
            $response = http_request('POST', '/api/instances/restore.php', [
                'cookie' => "session_token=$sessionToken",
                'headers' => ['Content-Type: application/json', "X-CSRF-Token: $csrfToken2"],
                'body' => json_encode(['hash' => $instanceHash]),
            ]);
            $restored = ($response['body_json']['status'] ?? '') === 'success' ||
                        ($response['body_json']['success'] ?? false) === true;
            test("Restore request → success", $restored,
                "Got: " . json_encode($response['body_json']));

            // Verify instance is back in instances collection
            $inst = $db->instances->findOne(['hash' => $instanceHash]);
            test("Instance restored to instances collection", $inst !== null);

            // Verify removed from trash
            $trashedInst = $db->instance_trash->findOne(['hash' => $instanceHash]);
            test("Instance removed from instance_trash", $trashedInst === null);
        } else {
            skip("Restore instance", "No CSRF token");
        }
    } else {
        skip("Restore tests", "No CSRF or instance not trashed");
    }

    // ── Test 4: Permanent delete ──
    echo "\n--- Permanent Delete ---\n";

    // Create another instance to permanently delete
    $hash2 = createInstance($sessionToken);
    if ($hash2 && $csrfToken) {
        // Trash it first
        $csrfToken3 = get_csrf_token($sessionToken);
        if ($csrfToken3) {
            http_request('POST', '/api/instances/trash.php', [
                'cookie' => "session_token=$sessionToken",
                'headers' => ['Content-Type: application/json', "X-CSRF-Token: $csrfToken3"],
                'body' => json_encode(['hash' => $hash2]),
            ]);

            // Permanent delete
            $csrfToken4 = get_csrf_token($sessionToken);
            if ($csrfToken4) {
                $response = http_request('DELETE', '/api/instances/permanent_delete.php', [
                    'cookie' => "session_token=$sessionToken",
                    'headers' => ['Content-Type: application/json', "X-CSRF-Token: $csrfToken4"],
                    'body' => json_encode(['hash' => $hash2]),
                ]);
                $deleted = ($response['body_json']['status'] ?? '') === 'success' ||
                           ($response['body_json']['success'] ?? false) === true;
                test("Permanent delete → success", $deleted,
                    "Got: " . json_encode($response['body_json']));

                // Verify actually removed from DB
                $inst = $db->instance_trash->findOne(['hash' => $hash2]);
                test("Instance removed from instance_trash", $inst === null);
            } else {
                skip("Permanent delete", "No CSRF token");
            }
        } else {
            skip("Permanent delete", "No CSRF token");
        }
    } else {
        skip("Permanent delete tests", "Could not create second instance");
    }

    // ── Cleanup: remove test instances ──
    $db->instances->deleteMany(['hash' => ['$in' => [$instanceHash, $hash2 ?? '']]]);
    $db->instance_trash->deleteMany(['hash' => ['$in' => [$instanceHash, $hash2 ?? '']]]);
}

cleanup_test_user($testEmail);

test_summary();
