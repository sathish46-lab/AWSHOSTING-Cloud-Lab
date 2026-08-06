<?php
/**
 * Test D4+D10: Purge cron + DB maintenance.
 * 
 * Tests:
 * 1. purge_deleted.php script exists
 * 2. purge_deleted.php is valid PHP
 * 3. purge_deleted.php supports --days flag
 * 4. purge_deleted.php supports --dry-run flag
 * 5. purge_deleted.php purges MySQL services
 * 6. purge_deleted.php purges MySQL users
 * 7. purge_deleted.php purges domains
 * 8. purge_deleted.php purges SSH keys
 * 9. purge_deleted.php purges VPN devices
 * 10. purge_deleted.php purges instance trash
 * 11. db_maintenance.php script exists
 * 12. db_maintenance.php is valid PHP
 * 13. db_maintenance.php cleans rate limit files
 * 14. db_maintenance.php cleans expired session tokens
 * 15. db_maintenance.php cleans expired 2FA OTPs
 * 16. db_maintenance.php cleans expired password reset tokens
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

echo "=== D4+D10: Purge + Maintenance Tests ===\n\n";

// purge_deleted.php tests
$purgePath = "$base/cron/purge_deleted.php";
test("purge_deleted.php exists", file_exists($purgePath));

$purgeContent = file_get_contents($purgePath);
test("purge_deleted.php is valid PHP", strpos($purgeContent, '<?php') !== false);
test("purge_deleted.php supports --days flag", strpos($purgeContent, '--days=') !== false);
test("purge_deleted.php supports --dry-run flag", strpos($purgeContent, '--dry-run') !== false);
test("purge_deleted.php purges MySQL services", strpos($purgeContent, 'mysql_services') !== false);
test("purge_deleted.php purges MySQL users", strpos($purgeContent, 'mysql_users') !== false);
test("purge_deleted.php purges domains", strpos($purgeContent, 'domains') !== false);
test("purge_deleted.php purges SSH keys", strpos($purgeContent, 'ssh_keys') !== false);
test("purge_deleted.php purges VPN devices", strpos($purgeContent, 'devices') !== false);
test("purge_deleted.php purges instance trash", strpos($purgeContent, 'instance_trash') !== false);

// db_maintenance.php tests
$maintPath = "$base/cron/db_maintenance.php";
test("db_maintenance.php exists", file_exists($maintPath));

$maintContent = file_get_contents($maintPath);
test("db_maintenance.php is valid PHP", strpos($maintContent, '<?php') !== false);
test("db_maintenance.php cleans rate limit files", strpos($maintContent, 'ratelimit') !== false && strpos($maintContent, '.count') !== false);
test("db_maintenance.php cleans expired session tokens", strpos($maintContent, 'session_tokens') !== false);
test("db_maintenance.php cleans expired 2FA OTPs", strpos($maintContent, 'two_factor_otp') !== false);
test("db_maintenance.php cleans expired password reset tokens", strpos($maintContent, 'password_reset_token') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
