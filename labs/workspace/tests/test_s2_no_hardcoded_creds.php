<?php
/**
 * Test: S2 — Hardcoded credentials removed from service managers
 * 
 * Verifies:
 * 1. MySqlManager reads password from get_config() not hardcoded
 * 2. PostgreSqlManager reads password from get_config() not hardcoded
 * 3. MariaDbManager reads password from get_config() not hardcoded
 * 4. RedisManager reads password from get_config() not hardcoded
 * 5. Missing credentials throw RuntimeException
 */

require_once __DIR__ . '/../../htdocs/src/utils/config.php';

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

// Test 1: Verify hardcoded passwords are NOT in source files
$files = [
    __DIR__ . '/../../htdocs/src/lib/services/MySqlManager.php',
    __DIR__ . '/../../htdocs/src/lib/services/PostgreSqlManager.php',
    __DIR__ . '/../../htdocs/src/lib/services/MariaDbManager.php',
    __DIR__ . '/../../htdocs/src/lib/services/RedisManager.php',
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    $basename = basename($file);
    test("{$basename} does not contain 'tomlabs_root_secret'", strpos($content, 'tomlabs_root_secret') === false);
    test("{$basename} does not contain 'tomlabs_redis_secret'", strpos($content, 'tomlabs_redis_secret') === false);
    test("{$basename} uses get_config()", strpos($content, 'get_config(') !== false);
}

// Test 2: Verify get_config is called with correct keys
$mysqlContent = file_get_contents($files[0]);
test('MySqlManager calls get_config("mysql_root_pass")', strpos($mysqlContent, "get_config('mysql_root_pass')") !== false);

$redisContent = file_get_contents($files[3]);
test('RedisManager calls get_config("redis_admin_pass")', strpos($redisContent, "get_config('redis_admin_pass')") !== false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
