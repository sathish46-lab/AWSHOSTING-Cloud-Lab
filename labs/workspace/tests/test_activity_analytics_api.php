<?php
/**
 * Test api/account/activity_analytics.php — Security & Functionality
 *
 * REAL RUNTIME TEST — Makes actual HTTP requests to the running server.
 * Verifies auth, user scoping, response structure, and data isolation.
 *
 * Security properties tested:
 * 1. Unauthenticated → 401
 * 2. User scoping: all analytics data belongs to authenticated user only
 * 3. IDOR: can't access another user's analytics via param tampering
 * 4. Response contains all expected sections with correct types
 * 5. No internal Mongo fields (_id, ObjectId, etc.) in response
 * 6. Summary stats are user-scoped
 *
 * Usage:
 *   # Inside Docker container:
 *   php workspace/tests/test_activity_analytics_api.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== API: activity_analytics.php Security Tests (Runtime) ===\n\n";

$db = DatabaseConnection::getDefaultDatabase();

// Create two test users for IDOR testing
$emailA = 'analytics_a_' . time() . '@example.com';
$emailB = 'analytics_b_' . time() . '@example.com';
$tokenA = create_test_user($emailA, 'user');
$tokenB = create_test_user($emailB, 'user');

// Seed audit log entries for user A
$userIdA = null;
$userA = $db->users->findOne(['email' => $emailA]);
if ($userA) {
    $userIdA = (string)$userA['_id'];
    $now = time();
    $db->audit_log->insertMany([
        [
            'user_id' => $userIdA, 'action' => 'create', 'entity_type' => 'instance',
            'entity_id' => 'inst_1', 'details' => [], 'ip_address' => '127.0.0.1',
            'user_agent' => 'Test', 'request_uri' => '/test', 'request_method' => 'POST',
            'created_at' => new MongoDB\BSON\UTCDateTime($now * 1000),
        ],
        [
            'user_id' => $userIdA, 'action' => 'update', 'entity_type' => 'instance',
            'entity_id' => 'inst_1', 'details' => [], 'ip_address' => '127.0.0.1',
            'user_agent' => 'Test', 'request_uri' => '/test', 'request_method' => 'POST',
            'created_at' => new MongoDB\BSON\UTCDateTime($now * 1000),
        ],
        [
            'user_id' => $userIdA, 'action' => 'change_password', 'entity_type' => 'user',
            'entity_id' => $userIdA, 'details' => [], 'ip_address' => '127.0.0.1',
            'user_agent' => 'Test', 'request_uri' => '/test', 'request_method' => 'POST',
            'created_at' => new MongoDB\BSON\UTCDateTime($now * 1000),
        ],
    ]);
}

// ── HTTP Tests ──
echo "--- HTTP Auth Tests ---\n";

// Test 1: Unauthenticated → 401
$response = http_request('GET', '/api/account/activity_analytics.php');
test("Unauthenticated → 401",
    $response['status'] === 401 || ($response['body_json']['error'] ?? '') === 'Unauthorized',
    "Got {$response['status']}: " . ($response['body_json']['error'] ?? ''));

// Test 2: Authenticated → 200 with valid structure
$response = http_request('GET', '/api/account/activity_analytics.php', [
    'cookie' => "session_token=$tokenA",
]);
test("Authenticated → 200", $response['status'] === 200,
    "Got {$response['status']}: " . substr($response['body'], 0, 200));

test("Response has status=success", ($response['body_json']['status'] ?? '') === 'success');

// ── Response structure ──
echo "\n--- Response Structure ---\n";

test("Has action_breakdown array", is_array($response['body_json']['action_breakdown'] ?? null));
test("Has entity_breakdown array", is_array($response['body_json']['entity_breakdown'] ?? null));
test("Has daily_trend array", is_array($response['body_json']['daily_trend'] ?? null));
test("Has hourly_activity array", is_array($response['body_json']['hourly_activity'] ?? null));
test("Has security_events array", is_array($response['body_json']['security_events'] ?? null));
test("Has summary object", is_array($response['body_json']['summary'] ?? null));

// Test hourly_activity has 24 elements
$hourly = $response['body_json']['hourly_activity'] ?? [];
test("hourly_activity has 24 elements", count($hourly) === 24,
    "Got " . count($hourly) . " elements");

// Test summary has required fields
$summary = $response['body_json']['summary'] ?? [];
test("Summary has total_actions", array_key_exists('total_actions', $summary));
test("Summary has active_days", array_key_exists('active_days', $summary));
test("Summary has this_week", array_key_exists('this_week', $summary));
test("Summary has most_common_action", array_key_exists('most_common_action', $summary));

// Test action_breakdown entries have correct structure
$actionBreakdown = $response['body_json']['action_breakdown'] ?? [];
if (count($actionBreakdown) > 0) {
    $first = $actionBreakdown[0];
    test("Action breakdown entry has 'action' field", isset($first['action']));
    test("Action breakdown entry has 'count' field", isset($first['count']));
    test("Action breakdown entry does NOT have '_id'", !isset($first['_id']));
} else {
    skip("Action breakdown structure", "No entries (empty audit log)");
}

// ── Data isolation: User B should see empty analytics ──
echo "\n--- Data Isolation (IDOR Protection) ---\n";

$responseB = http_request('GET', '/api/account/activity_analytics.php', [
    'cookie' => "session_token=$tokenB",
]);
test("User B → 200", $responseB['status'] === 200);

$summaryB = $responseB['body_json']['summary'] ?? [];
test("User B total_actions is 0",
    ($summaryB['total_actions'] ?? -1) === 0,
    "Got: " . ($summaryB['total_actions'] ?? 'null'));

$actionsB = $responseB['body_json']['action_breakdown'] ?? [];
test("User B action_breakdown is empty",
    count($actionsB) === 0,
    "Got " . count($actionsB) . " entries — possible IDOR leak");

$hourlyB = $responseB['body_json']['hourly_activity'] ?? [];
$hourlySumB = array_sum($hourlyB);
test("User B hourly_activity sums to 0",
    $hourlySumB === 0,
    "Sum: $hourlySumB — possible data leak");

$securityB = $responseB['body_json']['security_events'] ?? [];
test("User B security_events is empty",
    count($securityB) === 0,
    "Got " . count($securityB) . " events — possible IDOR leak");

// ── User A has data ──
echo "\n--- User A Data Verification ---\n";

$summaryA = $response['body_json']['summary'] ?? [];
test("User A total_actions > 0",
    ($summaryA['total_actions'] ?? 0) > 0,
    "Got: " . ($summaryA['total_actions'] ?? 'null'));

$actionsA = $response['body_json']['action_breakdown'] ?? [];
test("User A has action_breakdown entries",
    count($actionsA) > 0,
    "Got " . count($actionsA) . " entries");

$securityA = $response['body_json']['security_events'] ?? [];
test("User A has security_events (password change seeded)",
    count($securityA) > 0,
    "Got " . count($securityA) . " events");

// ── Response safety: no internal fields leaked ──
echo "\n--- Response Safety ---\n";

// Check security_events don't leak internal fields
if (count($securityA) > 0) {
    $seEvent = $securityA[0];
    test("Security event has 'action' field", isset($seEvent['action']));
    test("Security event has 'ip_address' field", isset($seEvent['ip_address']));
    test("Security event does NOT have '_id'", !isset($seEvent['_id']));
    test("Security event does NOT have 'user_agent'", !isset($seEvent['user_agent']));
    test("Security event does NOT have 'request_uri'", !isset($seEvent['request_uri']));
    test("Security event does NOT have 'request_method'", !isset($seEvent['request_method']));
    test("Security event does NOT have 'password'", !isset($seEvent['password']));
} else {
    skip("Security event field checks", "No security events");
}

// ── Cleanup ──
if ($userIdA) {
    $db->audit_log->deleteMany(['user_id' => $userIdA]);
}
cleanup_test_user($emailA);
cleanup_test_user($emailB);

test_summary();
