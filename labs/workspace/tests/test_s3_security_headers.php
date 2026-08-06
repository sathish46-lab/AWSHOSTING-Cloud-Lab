<?php
/**
 * Test: S3 — Security headers present in load.php
 * 
 * Verifies:
 * 1. send_security_headers function exists
 * 2. All required headers are defined in the function
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

// Read the load.php file
$loadContent = file_get_contents(__DIR__ . '/../../htdocs/src/load.php');

// Test 1: send_security_headers function exists
test('send_security_headers function defined', strpos($loadContent, 'function send_security_headers') !== false);

// Test 2: Required headers are present
$headers = [
    'X-Frame-Options',
    'X-Content-Type-Options',
    'X-XSS-Protection',
    'Content-Security-Policy',
    'Referrer-Policy',
    'Permissions-Policy',
    'Strict-Transport-Security',
];

foreach ($headers as $header) {
    test("Header {$header} is set", strpos($loadContent, "header('{$header}:") !== false);
}

// Test 3: CSP includes essential directives
$cspDirectives = ['default-src', 'script-src', 'style-src', 'img-src', 'connect-src', 'frame-ancestors'];
foreach ($cspDirectives as $directive) {
    test("CSP includes {$directive}", strpos($loadContent, $directive) !== false);
}

// Test 4: HSTS is conditional on HTTPS
test('HSTS is conditional on HTTPS', strpos($loadContent, "isset(\$_SERVER['HTTPS'])") !== false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
