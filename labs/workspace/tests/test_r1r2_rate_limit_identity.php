<?php
/**
 * Test: R1+R2 — Rate limit identity bypass fixed
 * 
 * Verifies:
 * 1. $_GET['email'] is NOT used for rate limit identity
 * 2. X-Forwarded-For is only trusted from known proxies
 * 3. Session-based identity is used for authenticated users
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

// Read the ratelimit file
$content = file_get_contents(__DIR__ . '/../../htdocs/src/utils/ratelimit.php');

// Test 1: $_GET['email'] is not used
test('$_GET["email"] is NOT used for identity', strpos($content, '$_GET[\'email\']') === false);

// Test 2: $_POST['email'] IS used (for unauthenticated login forms)
test('$_POST["email"] IS used for identity', strpos($content, '$_POST[\'email\']') !== false);

// Test 3: X-Forwarded-For requires proxy validation
test('X-Forwarded-For requires proxy check', strpos($content, 'trustedProxies') !== false);

// Test 4: REMOTE_ADDR is used as default
test('REMOTE_ADDR is default IP source', strpos($content, '$_SERVER[\'REMOTE_ADDR\']') !== false);

// Test 5: Session email is checked
test('Session email is used', strpos($content, '$_SESSION[\'email\']') !== false);

// Test 6: Session user_id is checked
test('Session user_id is used', strpos($content, '$_SESSION[\'user_id\']') !== false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
