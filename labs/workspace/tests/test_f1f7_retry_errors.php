<?php
/**
 * Test: F1-F7 — Retry logic and graceful error responses
 * 
 * Verifies:
 * 1. VPN.class.php has retry loop with backoff
 * 2. WebAPI.class.php returns JSON instead of die()
 * 3. vpn/download.php returns JSON instead of die()
 * 4. vpn/download.php sanitizes device name for Content-Disposition
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
$webapiContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/WebAPI.class.php');
$downloadContent = file_get_contents(__DIR__ . '/../../htdocs/src/api/vpn/download.php');

// VPN retry logic
test('VPN has retry loop', strpos($vpnContent, 'for ($attempt') !== false);
test('VPN has exponential backoff', strpos($vpnContent, 'sleep(min($attempt') !== false);
test('VPN retries on server errors (not 4xx)', strpos($vpnContent, '$httpCode >= 400 && $httpCode < 500') !== false);
test('VPN does not retry on client errors', strpos($vpnContent, 'Don\'t retry on 4xx') !== false);

// WebAPI graceful error
test('WebAPI returns JSON on missing extension', strpos($webapiContent, "echo json_encode") !== false);
test('WebAPI uses 500 status code', strpos($webapiContent, 'http_response_code(500)') !== false);
// Check that the active code (not comments) doesn't use die()
$activeWebapiContent = preg_replace('/\/\/.*$/m', '', $webapiContent); // Remove single-line comments
test('WebAPI active code does NOT use die()', strpos($activeWebapiContent, 'die("Unable') === false);

// vpn/download.php graceful errors
test('download.php returns JSON on unauthorized', strpos($downloadContent, "echo json_encode(['error' => 'Unauthorized'])") !== false);
test('download.php returns 401 on unauthorized', strpos($downloadContent, 'http_response_code(401)') !== false);
test('download.php does NOT expose exception messages', strpos($downloadContent, '$e->getMessage()') === false);
test('download.php sanitizes device name', strpos($downloadContent, "preg_replace('/[^a-zA-Z0-9_-]/'") !== false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
