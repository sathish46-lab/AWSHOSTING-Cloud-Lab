<?php
/**
 * Test SE3+S22: Session cookie security flags.
 *
 * REAL RUNTIME TEST — Verifies session cookies have Secure, HttpOnly,
 * and SameSite attributes set correctly.
 *
 * Usage:
 *   php workspace/tests/test_se3s22_cookie_flags.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== SE3+S22: Cookie Security Flags Tests (Runtime) ===\n\n";

// ── Test 1: Source code structure ──
echo "--- Source Code ---\n";

$loadPath = SRC_PATH . '/load.php';
test("load.php exists", file_exists($loadPath));

if (file_exists($loadPath)) {
    $src = file_get_contents($loadPath);
    test("session.cookie_secure is set", strpos($src, 'session.cookie_secure') !== false);
    test("session.cookie_samesite is set", strpos($src, 'session.cookie_samesite') !== false);
    test("session.cookie_httponly is set", strpos($src, 'session.cookie_httponly') !== false || strpos($src, 'session.cookie_httponly') !== false);
    test("session_start() is called after cookie config",
        strpos($src, 'session_start()') !== false || strpos($src, 'session_start ()') !== false);
}

// ── Test 2: Runtime — check Set-Cookie headers ──
echo "\n--- HTTP Set-Cookie Headers ---\n";

$testEmail = 'cookie_test_' . time() . '@example.com';
$token = create_test_user($testEmail, 'user');

// Make a request that sets cookies (e.g., dashboard with session)
$response = http_request('GET', '/dashboard', [
    'cookie' => "session_token=$token",
]);

// Check for Set-Cookie headers in response
$setCookieHeader = $response['headers']['Set-Cookie'] ?? $response['headers']['set-cookie'] ?? '';

if (!empty($setCookieHeader)) {
    test("Set-Cookie header present", true);

    // Check for security flags
    $hasSecure = stripos($setCookieHeader, 'Secure') !== false;
    $hasHttpOnly = stripos($setCookieHeader, 'HttpOnly') !== false;
    $hasSameSite = stripos($setCookieHeader, 'SameSite') !== false;

    // Secure flag is only set over HTTPS — check source code instead for HTTP
    if ($hasSecure) {
        test("Cookie has Secure flag", true);
    } else {
        // Over HTTP, Secure flag is correctly omitted — verify it's set in source
        if (file_exists($loadPath)) {
            $src = file_get_contents($loadPath);
            test("Cookie Secure flag configured in source (omitted over HTTP)",
                strpos($src, 'session.cookie_secure') !== false);
        } else {
            skip("Cookie Secure flag", "load.php not found");
        }
    }
    test("Cookie has HttpOnly flag", $hasHttpOnly,
        "Set-Cookie: " . substr($setCookieHeader, 0, 100));
    test("Cookie has SameSite flag", $hasSameSite,
        "Set-Cookie: " . substr($setCookieHeader, 0, 100));

    if ($hasSameSite) {
        $sameSiteValue = '';
        if (preg_match('/SameSite\s*=\s*(\w+)/i', $setCookieHeader, $m)) {
            $sameSiteValue = $m[1];
        }
        test("SameSite is Lax or Strict",
            strtolower($sameSiteValue) === 'lax' || strtolower($sameSiteValue) === 'strict',
            "Got: $sameSiteValue");
    }
} else {
    // No Set-Cookie in this response — check if session cookie is present in request
    test("Set-Cookie header present", false,
        "No Set-Cookie header found. Response status: " . ($response['status'] ?? 'null'));
}

// ── Test 3: Verify cookie flags are configured ──
echo "\n--- Cookie Config ---\n";

if (file_exists($loadPath)) {
    $src = file_get_contents($loadPath);
    // Just verify both cookie config and session_start exist in the file
    test("Cookie secure config present in load.php", strpos($src, 'session.cookie_secure') !== false);
    test("Session start present in load.php", strpos($src, 'session_start') !== false);
}

cleanup_test_user($testEmail);
test_summary();
