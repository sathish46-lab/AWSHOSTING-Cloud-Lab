<?php
/**
 * Test api/account/activity.php — Security & Functionality
 *
 * REAL RUNTIME TEST — Makes actual HTTP requests to the running server.
 * Verifies auth, user scoping, allowlist validation, pagination, and response safety.
 *
 * Security properties tested:
 * 1. Unauthenticated → 401
 * 2. User scoping: entries belong to authenticated user only
 * 3. IDOR: can't access another user's data via param tampering
 * 4. Allowlist: invalid action filter → 400
 * 5. Allowlist: invalid entity_type filter → 400
 * 6. Pagination clamped to max 100
 * 7. Response contains only safe fields (no _id, no user_agent, etc.)
 * 8. Summary counts are user-scoped
 *
 * Usage:
 *   # Inside Docker container:
 *   php workspace/tests/test_activity_api.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== API: activity.php Security Tests (Runtime) ===\n\n";

$db = DatabaseConnection::getDefaultDatabase();

// Create two test users for IDOR testing
$emailA = 'activity_a_' . time() . '@example.com';
$emailB = 'activity_b_' . time() . '@example.com';
$tokenA = create_test_user($emailA, 'user');
$tokenB = create_test_user($emailB, 'user');

// Seed some audit log entries for user A
$userIdA = null;
$userA = $db->users->findOne(['email' => $emailA]);
if ($userA) {
    $userIdA = (string)$userA['_id'];
    $db->audit_log->insertMany([
        [
            'user_id' => $userIdA,
            'action' => 'create',
            'entity_type' => 'instance',
            'entity_id' => 'test_instance_1',
            'details' => ['name' => 'Test Instance'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestBot/1.0',
            'request_uri' => '/api/instances/create.php',
            'request_method' => 'POST',
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
        ],
        [
            'user_id' => $userIdA,
            'action' => 'delete',
            'entity_type' => 'instance',
            'entity_id' => 'test_instance_2',
            'details' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestBot/1.0',
            'request_uri' => '/api/instances/delete.php',
            'request_method' => 'POST',
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
        ],
    ]);
}

// ── HTTP Tests ──
echo "--- HTTP Auth Tests ---\n";

// Test 1: Unauthenticated → 401
$response = http_request('GET', '/api/account/activity.php');
test("Unauthenticated → 401",
    $response['status'] === 401 || ($response['body_json']['error'] ?? '') === 'Unauthorized',
    "Got {$response['status']}: " . ($response['body_json']['error'] ?? ''));

// Test 2: Authenticated → 200 with valid structure
$response = http_request('GET', '/api/account/activity.php', [
    'cookie' => "session_token=$tokenA",
]);
test("Authenticated → 200", $response['status'] === 200,
    "Got {$response['status']}");
test("Response has status field", ($response['body_json']['status'] ?? '') === 'success');
test("Response has entries array", is_array($response['body_json']['entries'] ?? null));
test("Response has total field", isset($response['body_json']['total']));
test("Response has summary field", is_array($response['body_json']['summary'] ?? null));

// ── Allowlist validation ──
echo "\n--- Allowlist Validation ---\n";

// Test 3: Invalid action filter → 400
$response = http_request('GET', '/api/account/activity.php?action=INJECTDropTable', [
    'cookie' => "session_token=$tokenA",
]);
test("Invalid action filter → 400", $response['status'] === 400,
    "Got {$response['status']}: " . ($response['body_json']['error'] ?? ''));
test("Error message for invalid action",
    strpos($response['body_json']['error'] ?? '', 'Invalid action') !== false);

// Test 4: Invalid entity_type filter → 400
$response = http_request('GET', '/api/account/activity.php?entity_type=malicious', [
    'cookie' => "session_token=$tokenA",
]);
test("Invalid entity_type filter → 400", $response['status'] === 400,
    "Got {$response['status']}: " . ($response['body_json']['error'] ?? ''));
test("Error message for invalid entity_type",
    strpos($response['body_json']['error'] ?? '', 'Invalid entity_type') !== false);

// Test 5: Valid action filter works
$response = http_request('GET', '/api/account/activity.php?action=create', [
    'cookie' => "session_token=$tokenA",
]);
test("Valid action filter → 200", $response['status'] === 200);

// Test 6: Valid entity_type filter works
$response = http_request('GET', '/api/account/activity.php?entity_type=instance', [
    'cookie' => "session_token=$tokenA",
]);
test("Valid entity_type filter → 200", $response['status'] === 200);

// ── Pagination ──
echo "\n--- Pagination ---\n";

// Test 7: Limit clamped to max 100
$response = http_request('GET', '/api/account/activity.php?limit=999', [
    'cookie' => "session_token=$tokenA",
]);
test("Limit 999 clamped to 100", ($response['body_json']['limit'] ?? 0) <= 100,
    "Got limit: " . ($response['body_json']['limit'] ?? 'null'));

// Test 8: Limit=1 returns at most 1 entry
$response = http_request('GET', '/api/account/activity.php?limit=1', [
    'cookie' => "session_token=$tokenA",
]);
test("Limit=1 returns at most 1 entry",
    count($response['body_json']['entries'] ?? []) <= 1);

// Test 9: Offset works
$response = http_request('GET', '/api/account/activity.php?offset=0&limit=10', [
    'cookie' => "session_token=$tokenA",
]);
test("Offset=0 returns entries", $response['status'] === 200);

// ── Response safety ──
echo "\n--- Response Safety ---\n";

$entries = $response['body_json']['entries'] ?? [];
if (count($entries) > 0) {
    $first = $entries[0];
    test("Entry has 'action' field", isset($first['action']));
    test("Entry has 'entity_type' field", isset($first['entity_type']));
    test("Entry has 'created_at' field", isset($first['created_at']));
    test("Entry does NOT have '_id' field", !isset($first['_id']));
    test("Entry does NOT have 'user_agent' field", !isset($first['user_agent']));
    test("Entry does NOT have 'request_uri' field", !isset($first['request_uri']));
    test("Entry does NOT have 'request_method' field", !isset($first['request_method']));
    test("Entry does NOT have 'password' field", !isset($first['password']));
    test("Entry does NOT have 'session_tokens' field", !isset($first['session_tokens']));
} else {
    skip("Entry field checks", "No entries returned (empty audit log)");
}

// ── IDOR: User A can't see User B's entries ──
echo "\n--- IDOR Protection ---\n";

$responseA = http_request('GET', '/api/account/activity.php', [
    'cookie' => "session_token=$tokenA",
]);
$responseB = http_request('GET', '/api/account/activity.php', [
    'cookie' => "session_token=$tokenB",
]);

$entriesA = $responseA['body_json']['entries'] ?? [];
$entriesB = $responseB['body_json']['entries'] ?? [];

// User A has seeded entries, User B should have none
test("User A has entries", count($entriesA) > 0,
    "Got " . count($entriesA) . " entries");
test("User B has no entries (different user)", count($entriesB) === 0,
    "Got " . count($entriesB) . " entries — possible IDOR leak");

// Verify User A's entries don't appear in User B's response
if (count($entriesB) > 0) {
    $bEntityIds = array_column($entriesB, 'entity_id');
    test("User B doesn't see User A's entity_ids",
        !in_array('test_instance_1', $bEntityIds),
        "Leaked entity_id from User A");
} else {
    test("User B doesn't see User A's entity_ids", true);
}

// ── Summary is user-scoped ──
echo "\n--- Summary User Scoping ---\n";

$summaryA = $responseA['body_json']['summary'] ?? [];
$summaryB = $responseB['body_json']['summary'] ?? [];

// User A should have non-zero counts, User B should be all zeros
$hasNonZero = false;
foreach ($summaryA as $count) {
    if ($count > 0) { $hasNonZero = true; break; }
}
test("User A summary has non-zero counts", $hasNonZero);

$allZero = true;
foreach ($summaryB as $count) {
    if ($count > 0) { $allZero = false; break; }
}
test("User B summary is all zeros (user-scoped)", $allZero);

// ── NoSQL injection via action param ──
echo "\n--- NoSQL Injection Protection ---\n";

$injectionPayloads = [
    '{"$gt": ""}',
    '{"$ne": null}',
    '$regex',
    'create; DROP TABLE',
];

foreach ($injectionPayloads as $payload) {
    $response = http_request('GET', '/api/account/activity.php?action=' . urlencode($payload), [
        'cookie' => "session_token=$tokenA",
    ]);
    // Should return 400 (invalid action) or 200 (filtered), never crash
    test("Injection payload rejected safely: " . substr($payload, 0, 20),
        in_array($response['status'], [200, 400]),
        "Got {$response['status']}");
}

// ── Cleanup ──
// Remove seeded audit log entries
if ($userIdA) {
    $db->audit_log->deleteMany(['user_id' => $userIdA]);
}
cleanup_test_user($emailA);
cleanup_test_user($emailB);

test_summary();
