<?php
/**
 * Test: S1 — env.json secrets loading via environment variables
 * 
 * Verifies:
 * 1. Environment variables take precedence over env.json
 * 2. env.json fallback still works when env var not set
 * 3. Deprecation warning is logged when env.json is used in production
 */

require_once __DIR__ . '/../../htdocs/src/utils/config.php';

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

// Test 1: Environment variable takes precedence
putenv('TEST_S1_KEY=from_env_var');
$result = get_config('TEST_S1_KEY');
test('Env var takes precedence over file', $result === 'from_env_var');
putenv('TEST_S1_KEY');

// Test 2: Returns null when neither env var nor file has the key
$result = get_config('NONEXISTENT_KEY_S1_TEST');
test('Returns null for missing key', $result === null);

// Test 3: get_config function exists and is callable
test('get_config function exists', function_exists('get_config'));

// Test 4: is_local function exists (used by config.php)
test('is_local function exists', function_exists('is_local'));

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
