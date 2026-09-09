<?php
/**
 * Test S3: Security headers middleware.
 *
 * REAL RUNTIME TEST — Makes HTTP requests and checks response headers
 * for required security headers (X-Frame-Options, CSP, HSTS, etc.).
 *
 * Usage:
 *   php workspace/tests/test_s3_security_headers.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== S3: Security Headers Tests (Runtime) ===\n\n";

// ── Test 1: Source code structure ──
echo "--- Source Code ---\n";

$loadPath = SRC_PATH . '/load.php';
test("load.php exists", file_exists($loadPath));

if (file_exists($loadPath)) {
    $src = file_get_contents($loadPath);
    test("load.php calls send_security_headers()",
        strpos($src, 'send_security_headers') !== false);
}

// Find where send_security_headers is defined
$securityHeadersFound = false;
$securityHeadersPath = '';
$searchPaths = [
    SRC_PATH . '/utils/security_headers.php',
    SRC_PATH . '/lib/core/SecurityHeaders.class.php',
    SRC_PATH . '/middleware/security_headers.php',
];

foreach ($searchPaths as $path) {
    if (file_exists($path)) {
        $securityHeadersFound = true;
        $securityHeadersPath = $path;
        break;
    }
}

// Also check load.php itself for the function definition
if (!$securityHeadersFound && file_exists($loadPath)) {
    $loadSrc = file_get_contents($loadPath);
    if (strpos($loadSrc, 'function send_security_headers') !== false) {
        $securityHeadersFound = true;
        $securityHeadersPath = $loadPath;
    }
}

if ($securityHeadersFound && $securityHeadersPath !== '') {
    $src = file_get_contents($securityHeadersPath);
    test("X-Frame-Options header set", strpos($src, 'X-Frame-Options') !== false);
    test("X-Content-Type-Options header set", strpos($src, 'X-Content-Type-Options') !== false);
    test("X-XSS-Protection header set", strpos($src, 'X-XSS-Protection') !== false);
    test("Referrer-Policy header set", strpos($src, 'Referrer-Policy') !== false);
    test("Permissions-Policy header set", strpos($src, 'Permissions-Policy') !== false || strpos($src, 'Feature-Policy') !== false);
    test("Content-Security-Policy header set", strpos($src, 'Content-Security-Policy') !== false);
    test("Strict-Transport-Security conditional on HTTPS", strpos($src, 'HTTPS') !== false || strpos($src, 'https') !== false);
} else {
    skip("Security headers source checks", "send_security_headers function not found in expected locations");
}

// ── Test 2: Runtime — check response headers ──
echo "\n--- HTTP Response Headers ---\n";

$response = http_request('GET', '/dashboard', [
    'cookie' => "session_token=" . create_test_user('header_test_' . time() . '@example.com', 'user'),
]);

$headers = $response['headers'] ?? [];

// Check each security header
test("X-Frame-Options header present",
    isset($headers['X-Frame-Options']) || isset($headers['x-frame-options']),
    "Headers: " . implode(', ', array_keys($headers)));

test("X-Content-Type-Options header present",
    isset($headers['X-Content-Type-Options']) || isset($headers['x-content-type-options']));

test("X-XSS-Protection header present",
    isset($headers['X-XSS-Protection']) || isset($headers['x-xss-protection']));

test("Referrer-Policy header present",
    isset($headers['Referrer-Policy']) || isset($headers['referrer-policy']));

test("Content-Security-Policy header present",
    isset($headers['Content-Security-Policy']) || isset($headers['content-security-policy']));

// Verify specific values
$xFrame = $headers['X-Frame-Options'] ?? $headers['x-frame-options'] ?? '';
test("X-Frame-Options is DENY or SAMEORIGIN",
    stripos($xFrame, 'DENY') !== false || stripos($xFrame, 'SAMEORIGIN') !== false,
    "Got: $xFrame");

$xContentType = $headers['X-Content-Type-Options'] ?? $headers['x-content-type-options'] ?? '';
test("X-Content-Type-Options is nosniff",
    strtolower($xContentType) === 'nosniff',
    "Got: $xContentType");

// Cleanup
cleanup_test_user('header_test_' . time() . '@example.com');

test_summary();
