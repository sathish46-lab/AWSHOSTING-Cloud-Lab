<?php
/**
 * Test: SE3+S22 — Session cookie security flags via ini_set
 * 
 * Verifies:
 * 1. session.cookie_secure is set via ini_set
 * 2. session.cookie_samesite is set via ini_set
 * 3. Both are set BEFORE session_start()
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

$content = file_get_contents(__DIR__ . '/../../htdocs/src/load.php');
$lines = explode("\n", $content);

// Find line numbers
$secureLine = 0;
$samesiteLine = 0;
$sessionStartLine = 0;

foreach ($lines as $i => $line) {
    if (strpos($line, "ini_set('session.cookie_secure'") !== false) $secureLine = $i + 1;
    if (strpos($line, "ini_set('session.cookie_samesite'") !== false) $samesiteLine = $i + 1;
    if (strpos($line, 'session_start()') !== false && $sessionStartLine === 0 && strpos($line, '//') === false) $sessionStartLine = $i + 1;
}

echo "  (cookie_secure at line {$secureLine}, cookie_samesite at line {$samesiteLine}, session_start at line {$sessionStartLine})\n";

// Test 1: cookie_secure ini_set exists
test('session.cookie_secure ini_set exists', $secureLine > 0);

// Test 2: cookie_samesite ini_set exists
test('session.cookie_samesite ini_set exists', $samesiteLine > 0);

// Test 3: Both are before session_start()
test('cookie_secure is before session_start()', $secureLine > 0 && $secureLine < $sessionStartLine);
test('cookie_samesite is before session_start()', $samesiteLine > 0 && $samesiteLine < $sessionStartLine);

// Test 4: Secure flag is conditional on HTTPS
test('Secure flag checks HTTPS', strpos($content, 'isHttps') !== false);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
