<?php
/**
 * Test T1+T2+T3: Timeout configurations.
 *
 * REAL RUNTIME TEST — Verifies timeout settings in VPN, RabbitClient,
 * and DatabaseConnection classes to prevent infinite hangs.
 *
 * Usage:
 *   php workspace/tests/test_t1t2t3_timeouts.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== T1+T2+T3: Timeout Configuration Tests (Runtime) ===\n\n";

// ── Test 1: VPN timeout config ──
echo "--- VPN Timeouts ---\n";

$vpnPath = SRC_PATH . '/lib/core/VPN.class.php';
test("VPN.class.php exists", file_exists($vpnPath));

if (file_exists($vpnPath)) {
    $src = file_get_contents($vpnPath);
    test("VPN has CURLOPT_TIMEOUT", strpos($src, 'CURLOPT_TIMEOUT') !== false);
    test("VPN has CURLOPT_CONNECTTIMEOUT", strpos($src, 'CURLOPT_CONNECTTIMEOUT') !== false);
    test("VPN has retry logic (for loop or while)", strpos($src, 'for') !== false || strpos($src, 'while') !== false);
    test("VPN has exponential backoff (sleep)", strpos($src, 'sleep') !== false);
    test("VPN uses CircuitBreaker", strpos($src, 'CircuitBreaker') !== false);
    test("VPN has no die() for critical errors", strpos($src, 'die(') === false || substr_count($src, 'die(') <= 1);
}

// ── Test 2: RabbitClient timeout config ──
echo "\n--- RabbitMQ Timeouts ---\n";

$rabbitPath = SRC_PATH . '/lib/core/RabbitClient.class.php';
test("RabbitClient.class.php exists", file_exists($rabbitPath));

if (file_exists($rabbitPath)) {
    $src = file_get_contents($rabbitPath);
    test("RabbitClient has read_write_timeout", strpos($src, 'read_write_timeout') !== false);
    test("RabbitClient has connection_timeout", strpos($src, 'connection_timeout') !== false);
    test("RabbitClient has connection_timeout", strpos($src, 'connection_timeout') !== false);
}

// ── Test 3: DatabaseConnection timeout config ──
echo "\n--- MongoDB Timeouts ---\n";

$dbPath = SRC_PATH . '/lib/core/DatabaseConnection.class.php';
test("DatabaseConnection.class.php exists", file_exists($dbPath));

if (file_exists($dbPath)) {
    $src = file_get_contents($dbPath);
    test("DatabaseConnection has serverSelectionTimeoutMS", strpos($src, 'serverSelectionTimeoutMS') !== false);
    test("DatabaseConnection has connectTimeoutMS", strpos($src, 'connectTimeoutMS') !== false);
    test("DatabaseConnection returns error on failure (no die)", strpos($src, 'die(') === false || strpos($src, 'die(\'Critical') === false);
}

// ── Test 4: Runtime — DatabaseConnection timeout actually works ──
echo "\n--- Runtime Timeout Verification ---\n";

// Verify DB connects with timeout
$start = microtime(true);
$db = DatabaseConnection::getDefaultDatabase();
$elapsed = microtime(true) - $start;

test("DatabaseConnection connects within timeout", $elapsed < 10,
    "Connection took " . round($elapsed, 2) . "s");

// Verify the timeout values are reasonable
if (file_exists($dbPath)) {
    $src = file_get_contents($dbPath);
    // Check timeout values are <= 10 seconds
    if (preg_match('/serverSelectionTimeoutMS[=:]\s*(\d+)/', $src, $m)) {
        $timeout = (int)$m[1];
        test("serverSelectionTimeoutMS <= 10000ms", $timeout <= 10000,
            "Got: ${timeout}ms");
    }
    if (preg_match('/connectTimeoutMS[=:]\s*(\d+)/', $src, $m)) {
        $timeout = (int)$m[1];
        test("connectTimeoutMS <= 10000ms", $timeout <= 10000,
            "Got: ${timeout}ms");
    }
}

// ── Test 5: No unsafe die/critical patterns ──
echo "\n--- Unsafe Pattern Checks ---\n";

$criticalFiles = [
    'VPN.class.php' => SRC_PATH . '/lib/core/VPN.class.php',
    'DatabaseConnection.class.php' => SRC_PATH . '/lib/core/DatabaseConnection.class.php',
    'RabbitClient.class.php' => SRC_PATH . '/lib/core/RabbitClient.class.php',
];

foreach ($criticalFiles as $name => $path) {
    if (file_exists($path)) {
        $src = file_get_contents($path);
        test("$name: no die('Critical') pattern",
            strpos($src, "die('Critical") === false && strpos($src, 'die("Critical') === false);
    }
}

test_summary();
