<?php
/**
 * Test SE5+S23: Password change endpoint + session invalidation.
 * 
 * Tests:
 * 1. change_password.php file exists
 * 2. change_password.php is valid PHP
 * 3. change_password.php requires authentication
 * 4. change_password.php validates CSRF token
 * 5. change_password.php validates current password
 * 6. change_password.php validates new password length
 * 7. change_password.php hashes new password with password_hash()
 * 8. change_password.php invalidates other sessions (keeps current token only)
 * 9. change_password.php logs audit event
 * 10. change_password.php includes AuditLog.class.php
 * 11. change_password.php includes CsrfProtection.class.php
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

echo "=== SE5+S23: Password Change Tests ===\n\n";

$path = "$base/htdocs/src/api/account/change_password.php";
$content = file_exists($path) ? file_get_contents($path) : '';

// 1. File exists
test("change_password.php file exists", file_exists($path));

// 2. Valid PHP
test("change_password.php is valid PHP", substr($content, 0, 5) === '<?php');

// 3. Requires authentication
test("change_password.php checks auth status", strpos($content, 'Session::getAuthStatus()') !== false);

// 4. Validates CSRF
test("change_password.php validates CSRF token", strpos($content, 'CsrfProtection::require()') !== false);

// 5. Validates current password
test("change_password.php verifies current password", strpos($content, 'password_verify($currentPassword') !== false);

// 6. Validates new password length
test("change_password.php checks password length", strpos($content, 'strlen($newPassword)') !== false);

// 7. Hashes new password
test("change_password.php hashes new password", strpos($content, 'password_hash($newPassword') !== false);

// 8. Invalidates other sessions
test("change_password.php invalidates other sessions", strpos($content, 'session_tokens') !== false && strpos($content, '$set') !== false);

// 9. Logs audit event
test("change_password.php logs audit event", strpos($content, "AuditLog::log('change_password'") !== false);

// 10. Includes AuditLog
test("change_password.php includes AuditLog.class.php", strpos($content, 'AuditLog.class.php') !== false);

// 11. Includes CsrfProtection
test("change_password.php includes CsrfProtection.class.php", strpos($content, 'CsrfProtection.class.php') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
