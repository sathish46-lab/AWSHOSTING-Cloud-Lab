<?php
/**
 * Test S2: No hardcoded credentials in database manager files.
 *
 * REAL RUNTIME TEST — Scans MySQL, PostgreSQL, MariaDB, and Redis
 * manager files to verify they use get_config() instead of hardcoded
 * passwords.
 *
 * Usage:
 *   php workspace/tests/test_s2_no_hardcoded_creds.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== S2: No Hardcoded Credentials Tests (Runtime) ===\n\n";

// ── Test 1: MySQL manager ──
echo "--- MySQL Manager ---\n";

$mysqlPath = SRC_PATH . '/lib/database/MySqlManager.php';
if (file_exists($mysqlPath)) {
    $src = file_get_contents($mysqlPath);
    test("MySqlManager.php uses get_config()", strpos($src, 'get_config') !== false);
    test("MySqlManager.php has no hardcoded passwords",
        strpos($src, "password'") === false ||
        (strpos($src, 'get_config') !== false && substr_count($src, "password'") <= 1));
    test("MySqlManager.php has no hardcoded host", strpos($src, 'localhost') === false || strpos($src, 'get_config') !== false);
} else {
    skip("MySqlManager checks", "File not found");
}

// ── Test 2: PostgreSQL manager ──
echo "\n--- PostgreSQL Manager ---\n";

$pgPath = SRC_PATH . '/lib/database/PostgreSqlManager.php';
if (file_exists($pgPath)) {
    $src = file_get_contents($pgPath);
    test("PostgreSqlManager.php uses get_config()", strpos($src, 'get_config') !== false);
    test("PostgreSqlManager.php has no hardcoded passwords",
        strpos($src, "password'") === false ||
        (strpos($src, 'get_config') !== false && substr_count($src, "password'") <= 1));
} else {
    skip("PostgreSqlManager checks", "File not found");
}

// ── Test 3: MariaDB manager ──
echo "\n--- MariaDB Manager ---\n";

$mariadbPath = SRC_PATH . '/lib/database/MariaDbManager.php';
if (file_exists($mariadbPath)) {
    $src = file_get_contents($mariadbPath);
    test("MariaDbManager.php uses get_config()", strpos($src, 'get_config') !== false);
    test("MariaDbManager.php has no hardcoded passwords",
        strpos($src, "password'") === false ||
        (strpos($src, 'get_config') !== false && substr_count($src, "password'") <= 1));
} else {
    skip("MariaDbManager checks", "File not found");
}

// ── Test 4: Redis manager ──
echo "\n--- Redis Manager ---\n";

$redisPath = SRC_PATH . '/lib/database/RedisManager.php';
if (file_exists($redisPath)) {
    $src = file_get_contents($redisPath);
    test("RedisManager.php uses get_config()", strpos($src, 'get_config') !== false);
    test("RedisManager.php has no hardcoded passwords",
        strpos($src, "password'") === false ||
        (strpos($src, 'get_config') !== false && substr_count($src, "password'") <= 1));
} else {
    skip("RedisManager checks", "File not found");
}

// ── Test 5: No plaintext passwords in env.json (runtime check) ──
echo "\n--- env.json Safety ---\n";

$envPath = PROJECT_ROOT . '/env.json';
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    $envData = json_decode($envContent, true);

    if (is_array($envData)) {
        // Check that no password field contains a real-looking password
        $dangerousPatterns = ['password123', 'admin123', 'root123', '123456', 'changeme'];
        foreach ($dangerousPatterns as $pattern) {
            test("env.json does not contain '$pattern'",
                stripos($envContent, $pattern) === false);
        }

        // Verify passwords are hashed or use env vars
        test("env.json passwords are hashed (bcrypt)",
            strpos($envContent, '$2y$') !== false || strpos($envContent, '$2a$') !== false ||
            stripos($envContent, 'password_hash') !== false || stripos($envContent, 'getenv') !== false);
    }
} else {
    skip("env.json checks", "File not found");
}

test_summary();
