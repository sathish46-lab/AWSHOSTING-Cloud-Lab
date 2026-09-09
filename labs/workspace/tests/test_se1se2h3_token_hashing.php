<?php
/**
 * Test: SE1+SE2+H3 — Session token hashing and expiry
 * 
 * Verifies:
 * 1. UserSession stores token_hash instead of plain token
 * 2. WebAPI validates hashed tokens (password_verify)
 * 3. WebAPI enforces 30-day token expiry
 * 4. Logout matches by hashed token
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
$sessionContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/UserSession.class.php');
$webapiContent = file_get_contents(__DIR__ . '/../../htdocs/src/lib/core/WebAPI.class.php');

// Test 1: Token hashing on storage
test('UserSession uses password_hash for token', strpos($sessionContent, 'password_hash($sessionToken') !== false);
test('UserSession stores token_hash field', strpos($sessionContent, "'token_hash' => $tokenHash") !== false);
test('UserSession stores token_id field', strpos($sessionContent, "'token_id'") !== false);
test('UserSession does NOT store plain token field', strpos($sessionContent, "'token' => $sessionToken") === false);

// Test 2: Token validation uses password_verify
test('WebAPI uses password_verify for token validation', strpos($webapiContent, 'password_verify($sessionToken, $storedHash)') !== false);

// Test 3: Token expiry check
test('WebAPI has 30-day expiry constant', strpos($webapiContent, '30 * 24 * 3600') !== false);
test('WebAPI checks created_at against cutoff', strpos($webapiContent, 'created_at') !== false && strpos($webapiContent, 'cutoffTime') !== false);

// Test 4: Logout matches by token_id
test('Logout uses token_id for lookup', strpos($sessionContent, 'session_tokens.token_id') !== false);
test('Logout uses pull with token_id', strpos($sessionContent, "'token_id' => \$tokenId") !== false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
