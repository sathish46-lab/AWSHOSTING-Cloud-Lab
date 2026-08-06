<?php
/**
 * Test: T1+T2+T3 — Timeouts added to VPN, RabbitMQ, MongoDB
 * 
 * Verifies:
 * 1. VPN.class.php has CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT
 * 2. RabbitClient.class.php has read_write_timeout parameter
 * 3. DatabaseConnection.class.php has serverSelectionTimeoutMS in URI
 * 4. DatabaseConnection no longer uses die() with raw message
 */

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

// Read files
$vpnContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/VPN.class.php');
$rabbitContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/RabbitClient.class.php');
$dbContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/DatabaseConnection.class.php');

// VPN timeouts
test('VPN has CURLOPT_CONNECTTIMEOUT', strpos($vpnContent, 'CURLOPT_CONNECTTIMEOUT') !== false);
test('VPN has CURLOPT_TIMEOUT', strpos($vpnContent, 'CURLOPT_TIMEOUT') !== false);

// RabbitMQ timeout
test('RabbitMQ has read_write_timeout parameter', strpos($rabbitContent, 'read_write_timeout') !== false);

// MongoDB timeout
test('MongoDB has serverSelectionTimeoutMS in URI', strpos($dbContent, 'serverSelectionTimeoutMS') !== false);
test('MongoDB has connectTimeoutMS in URI', strpos($dbContent, 'connectTimeoutMS') !== false);

// DatabaseConnection graceful error
test('DatabaseConnection returns JSON error instead of die', strpos($dbContent, "echo json_encode") !== false);
test('DatabaseConnection uses 503 status code', strpos($dbContent, 'http_response_code(503)') !== false);
test('DatabaseConnection does NOT use die() with raw message', strpos($dbContent, 'die("Critical') === false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
