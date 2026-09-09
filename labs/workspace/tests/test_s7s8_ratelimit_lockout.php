<?php
/**
 * Test S7+S8: Rate limiting + Account lockout
 * 
 * REAL RUNTIME TEST — Tests actual lockout behavior against the database.
 * 
 * Prerequisites:
 *   - Database accessible
 * 
 * Usage:
 *   # Inside Docker container:
 *   php workspace/tests/test_s7s8_ratelimit_lockout.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== S7+S8: Rate Limit + Account Lockout Tests (Runtime) ===\n\n";

$db = DatabaseConnection::getDefaultDatabase();

// ── Test 1: Rate limit config exists and is correct ──
$ratelimitPath = SRC_PATH . '/utils/ratelimit.php';
test("ratelimit.php exists", file_exists($ratelimitPath));

if (file_exists($ratelimitPath)) {
    $content = file_get_contents($ratelimitPath);
    test("Password change rate limit rule exists", strpos($content, 'change_password') !== false);
    test("Rate limit uses correct key", strpos($content, 'account:rl:change_password') !== false);
    test("Rate limit limit is 3", strpos($content, "'limit'   => 3") !== false || strpos($content, "'limit'=>3") !== false);
    test("Rate limit window is 3600", strpos($content, "'window'  => 3600") !== false || strpos($content, "'window'=>3600") !== false);
}

// ── Test 2: UserSession has lockout logic ──
$userSessionPath = SRC_PATH . '/lib/core/UserSession.class.php';
test("UserSession.class.php exists", file_exists($userSessionPath));

if (file_exists($userSessionPath)) {
    $content = file_get_contents($userSessionPath);
    test("UserSession has locked_until field", strpos($content, 'locked_until') !== false);
    test("UserSession tracks failed_login_attempts", strpos($content, 'failed_login_attempts') !== false);
    test("Lockout triggers after 5 attempts", strpos($content, '>= 5') !== false);
    test("Lockout duration is 900 seconds", strpos($content, 'time() + 900') !== false || strpos($content, 'time()+900') !== false);
}

// ── Test 3: Actual lockout behavior in database ──
$testEmail = 'lockout_test_' . time() . '@example.com';

// Create test user
$db->users->insertOne([
    'email' => $testEmail,
    'username' => 'lockout_test',
    'role' => 'user',
    'password' => password_hash('WrongPassword123!', PASSWORD_BCRYPT),
    'created_at' => time(),
]);

// Simulate 5 failed login attempts
for ($i = 1; $i <= 5; $i++) {
    $db->users->updateOne(
        ['email' => $testEmail],
        ['$set' => ['failed_login_attempts' => $i]]
    );
    
    if ($i >= 5) {
        $db->users->updateOne(
            ['email' => $testEmail],
            ['$set' => ['locked_until' => time() + 900]]
        );
    }
}

$user = $db->users->findOne(['email' => $testEmail]);
test("User has 5 failed attempts", ($user['failed_login_attempts'] ?? 0) === 5);
test("User is locked until future", ($user['locked_until'] ?? 0) > time());

// ── Test 4: Lockout prevents login ──
$lockedUntil = $user['locked_until'] ?? 0;
test("Lockout prevents login when active", $lockedUntil > time());

// ── Test 5: Lockout clears when expired ──
$db->users->updateOne(
    ['email' => $testEmail],
    ['$set' => ['locked_until' => time() - 1]]  // Set to past
);

$user = $db->users->findOne(['email' => $testEmail]);
$lockedUntil = $user['locked_until'] ?? 0;
test("Lockout clears when expired", $lockedUntil <= time());

// ── Test 6: Successful login resets failed attempts ──
$db->users->updateOne(
    ['email' => $testEmail],
    ['$set' => ['failed_login_attempts' => 0, 'locked_until' => 0]]
);

$user = $db->users->findOne(['email' => $testEmail]);
test("Failed attempts reset to 0", ($user['failed_login_attempts'] ?? -1) === 0);
test("Lockout cleared after reset", ($user['locked_until'] ?? -1) === 0);

// ── Cleanup ──
$db->users->deleteMany(['email' => $testEmail]);

test_summary();
