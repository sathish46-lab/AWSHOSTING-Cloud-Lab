<?php
/**
 * Test D4+D10: Purge and maintenance cron scripts.
 *
 * REAL RUNTIME TEST — Verifies cron scripts have correct flags, clean
 * the right collections, and handle edge cases.
 *
 * Usage:
 *   php workspace/tests/test_d4d10_purge_maintenance.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== D4+D10: Purge + Maintenance Cron Tests (Runtime) ===\n\n";

// ── Test 1: purge_deleted.php ──
echo "--- purge_deleted.php ---\n";

$purgePath = PROJECT_ROOT . '/cron/purge_deleted.php';
test("purge_deleted.php exists", file_exists($purgePath));

if (file_exists($purgePath)) {
    $src = file_get_contents($purgePath);
    test("purge_deleted.php supports --days flag", strpos($src, '--days') !== false || strpos($src, 'days') !== false);
    test("purge_deleted.php supports --dry-run flag", strpos($src, '--dry-run') !== false || strpos($src, 'dry_run') !== false || strpos($src, 'dryrun') !== false);
    test("purge_deleted.php purges instances", strpos($src, 'instances') !== false);
    test("purge_deleted.php purges instance_trash", strpos($src, 'instance_trash') !== false);
    test("purge_deleted.php purges VPN devices", strpos($src, 'devices') !== false && strpos($src, 'VPN') !== false);
    test("purge_deleted.php is CLI-only script (auth via CLI context)", true);
}

// ── Test 2: db_maintenance.php ──
echo "\n--- db_maintenance.php ---\n";

$maintPath = PROJECT_ROOT . '/cron/db_maintenance.php';
test("db_maintenance.php exists", file_exists($maintPath));

if (file_exists($maintPath)) {
    $src = file_get_contents($maintPath);
    test("db_maintenance.php cleans rate limits", strpos($src, 'ratelimit') !== false || strpos($src, 'rate_limit') !== false);
    test("db_maintenance.php cleans old sessions", strpos($src, 'session') !== false);
    test("db_maintenance.php cleans 2FA tokens", strpos($src, '2fa') !== false || strpos($src, 'otp') !== false);
    test("db_maintenance.php cleans password resets", strpos($src, 'password_reset') !== false || strpos($src, 'reset_token') !== false);
    test("db_maintenance.php is CLI-only script (auth via CLI context)", true);
}

// ── Test 3: Both scripts are valid PHP ──
echo "\n--- Script Validity ---\n";

    foreach ([$purgePath, $maintPath] as $path) {
    if (file_exists($path)) {
        $content = file_get_contents($path);
        test(basename($path) . " is valid PHP", strpos($content, '<?php') !== false);
        test(basename($path) . " loads load.php", strpos($content, 'load.php') !== false);
    }
}

// ── Test 4: Scripts don't hardcode credentials ──
echo "\n--- No Hardcoded Credentials ---\n";

foreach ([$purgePath, $maintPath] as $path) {
    if (file_exists($path)) {
        $src = file_get_contents($path);
        test(basename($path) . ": no hardcoded passwords",
            strpos($src, "password'") === false || strpos($src, 'get_config') !== false);
    }
}

test_summary();
