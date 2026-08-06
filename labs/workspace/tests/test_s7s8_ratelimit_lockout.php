<?php
/**
 * Test S7+S8: Rate limiting on password change + account lockout.
 * 
 * Tests:
 * 1. Password change rate limit rule exists in ratelimit.php
 * 2. Rate limit rule uses correct key
 * 3. Rate limit rule uses correct limit (3 per hour)
 * 4. UserSession has lockout logic (locked_until field)
 * 5. UserSession tracks failed_login_attempts
 * 6. Lockout triggers after 5 failed attempts
 * 7. Lockout duration is 15 minutes (900 seconds)
 * 8. Failed attempts reset on successful login
 * 9. Lockout check prevents login when locked
 * 10. Lockout clears when expired
 */

$base = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: $name\n";
        $passed++;
    } else {
        echo "  FAIL: $name\n";
        $failed++;
    }
}

echo "=== S7+S8: Rate Limit + Account Lockout Tests ===\n\n";

// S7: Password change rate limit
$ratelimitContent = file_get_contents("$base/htdocs/src/utils/ratelimit.php");
test("Password change rate limit rule exists", strpos($ratelimitContent, 'change_password') !== false);
test("Rate limit uses correct key", strpos($ratelimitContent, 'account:rl:change_password') !== false);
test("Rate limit uses limit of 3", strpos($ratelimitContent, "'limit'   => 3") !== false);
test("Rate limit uses window of 3600 (1 hour)", strpos($ratelimitContent, "'window'  => 3600") !== false);

// S8: Account lockout
$userSessionContent = file_get_contents("$base/htdocs/src/lib/core/UserSession.class.php");
test("UserSession has locked_until field", strpos($userSessionContent, 'locked_until') !== false);
test("UserSession tracks failed_login_attempts", strpos($userSessionContent, 'failed_login_attempts') !== false);
test("Lockout triggers after 5 attempts", strpos($userSessionContent, '>= 5') !== false);
test("Lockout duration is 900 seconds", strpos($userSessionContent, 'time() + 900') !== false);
test("Failed attempts reset on successful login", strpos($userSessionContent, "'failed_login_attempts' => 0") !== false);
test("Lockout check prevents login", strpos($userSessionContent, '$lockedUntil > time()') !== false);
test("Lockout clears when expired", strpos($userSessionContent, '$lockedUntil <= time()') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
