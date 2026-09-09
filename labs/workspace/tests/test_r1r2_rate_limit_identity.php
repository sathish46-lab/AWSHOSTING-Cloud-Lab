<?php
/**
 * Test R1+R2: Rate limit identity resolution — no bypass via GET params or spoofed X-Forwarded-For.
 *
 * REAL RUNTIME TEST — Calls the real get_rate_limit_identity() function with
 * manipulated superglobals and verifies the returned identity string.
 *
 * Security properties tested:
 * 1. $_GET['email'] is IGNORED (bypass prevention)
 * 2. X-Forwarded-For from untrusted proxy is IGNORED
 * 3. X-Forwarded-For from trusted proxy (127.0.0.1) is used
 * 4. Session email takes priority over IP
 * 5. REMOTE_ADDR is the fallback
 * 6. CLI requests bypass rate limiting
 *
 * Usage:
 *   # Inside Docker container:
 *   php workspace/tests/test_r1r2_rate_limit_identity.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== R1+R2: Rate Limit Identity Bypass Tests (Runtime) ===\n\n";

// ── Test 1: Source code safety — $_GET['email'] never used for identity ──
$ratelimitPath = SRC_PATH . '/utils/ratelimit.php';
test("ratelimit.php exists", file_exists($ratelimitPath));

if (file_exists($ratelimitPath)) {
    $src = file_get_contents($ratelimitPath);

    test('$_GET["email"] is NEVER used for identity',
        strpos($src, '$_GET[\'email\']') === false && strpos($src, '$_GET["email"]') === false);

    test('$_POST["email"] IS used (for unauthenticated login forms)',
        strpos($src, '$_POST[\'email\']') !== false);

    test('Session email is checked as fallback',
        strpos($src, '$_SESSION[\'email\']') !== false);

    test('X-Forwarded-For has proxy validation',
        strpos($src, 'trustedProxies') !== false);

    test('REMOTE_ADDR is the default IP source',
        strpos($src, '$_SERVER[\'REMOTE_ADDR\']') !== false);
}

// ── Test 2: Runtime — call get_rate_limit_identity() with manipulated superglobals ──
echo "\n--- Runtime Identity Resolution Tests ---\n";

// Reset superglobals
$_GET = [];
$_POST = [];
$_SESSION = [];
$_SERVER = ['REMOTE_ADDR' => '192.168.1.100'];

// Test A: Only REMOTE_ADDR set → should use IP
$identity = get_rate_limit_identity();
test("Fallback to IP when no session/email",
    str_starts_with($identity, 'ip_'),
    "Got: $identity");

// Test B: $_GET['email'] should be IGNORED
$_GET['email'] = 'attacker@evil.com';
$identity = get_rate_limit_identity();
test('$_GET["email"] ignored — still uses IP',
    str_starts_with($identity, 'ip_'),
    "Got: $identity");
unset($_GET['email']);

// Test C: $_POST['email'] should be used (login forms)
$_POST['email'] = 'user@example.com';
$identity = get_rate_limit_identity();
test('$_POST["email"] used for identity',
    str_starts_with($identity, 'em_'),
    "Got: $identity");
$expectedEmail = 'em_' . md5(strtolower('user@example.com'));
test("Email identity matches expected MD5",
    $identity === $expectedEmail,
    "Expected: $expectedEmail, Got: $identity");
unset($_POST['email']);

// Test D: Session email takes priority over IP
$_SESSION['email'] = 'session_user@test.com';
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$identity = get_rate_limit_identity();
test("Session email takes priority over IP",
    str_starts_with($identity, 'em_'),
    "Got: $identity");
$sessionExpected = 'em_' . md5(strtolower('session_user@test.com'));
test("Session email identity matches expected",
    $identity === $sessionExpected,
    "Expected: $sessionExpected, Got: $identity");

// Test E: Session user_id used when no email
$_SESSION = ['user_id' => '12345'];
$identity = get_rate_limit_identity();
test("Session user_id used when no email",
    str_starts_with($identity, 'usr_'),
    "Got: $identity");

// Test F: X-Forwarded-For from UNTRUSTED proxy → IGNORED
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '10.0.0.99';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 10.0.0.99';
$identity = get_rate_limit_identity();
$untrustedExpected = 'ip_' . md5('10.0.0.99');
test("X-Forwarded-For from untrusted proxy IGNORED",
    $identity === $untrustedExpected,
    "Expected IP from REMOTE_ADDR, Got: $identity");

// Test G: X-Forwarded-For from TRUSTED proxy (127.0.0.1) → USED
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8, 127.0.0.1';
$identity = get_rate_limit_identity();
$trustedExpected = 'ip_' . md5('5.6.7.8');
test("X-Forwarded-For from trusted proxy (127.0.0.1) USED",
    $identity === $trustedExpected,
    "Expected IP from XFF, Got: $identity");

// Test H: Multiple X-Forwarded-For → takes leftmost from trusted
$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 8.8.8.8, 127.0.0.1';
$identity = get_rate_limit_identity();
$multiExpected = 'ip_' . md5('9.9.9.9');
test("Multiple XFF values → leftmost IP used",
    $identity === $multiExpected,
    "Expected: $multiExpected, Got: $identity");

// Clean up superglobals
$_GET = [];
$_POST = [];
$_SESSION = [];
$_SERVER = ['REMOTE_ADDR' => 'CLI'];

// ── Test 3: CLI bypass ──
echo "\n--- CLI Bypass Tests ---\n";

// The rate_limit() function returns true for CLI
// We test this by calling it directly with CLI environment
$_SERVER['REMOTE_ADDR'] = 'CLI';
$result = rate_limit('test:cli:bypass', 1, 60);
test("rate_limit() returns true in CLI mode", $result === true);

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];

// ── Test 4: Rate limit function returns correct structure ──
echo "\n--- Rate Limit Response Structure ---\n";

test("get_rate_limit_identity returns non-empty string",
    !empty(get_rate_limit_identity()));

test("Identity format is consistent (prefix_ + md5)",
    preg_match('/^(em|usr|ip)_[a-f0-9]{32}$/', get_rate_limit_identity()) === 1,
    "Got: " . get_rate_limit_identity());

// ── Cleanup ──
$_GET = [];
$_POST = [];
$_SESSION = [];
$_SERVER = ['REMOTE_ADDR' => 'CLI'];

test_summary();
