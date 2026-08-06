<?php
/**
 * Test: S4 — Debug scripts are auth-gated
 * 
 * Verifies:
 * 1. set_admin.php checks for superuser role
 * 2. fix.php checks for superuser role
 * 3. sync_ip_registry.php checks for superuser role
 * 4. All scripts check auth status
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

$scripts = [
    'set_admin.php',
    'fix.php',
    'sync_ip_registry.php',
];

foreach ($scripts as $script) {
    $path = __DIR__ . '/../../htdocs/' . $script;
    $content = file_get_contents($path);
    
    // Check auth gate
    test("{$script}: checks auth status", strpos($content, 'Session::getAuthStatus()') !== false);
    
    // Check superuser gate
    test("{$script}: checks superuser role", strpos($content, "getRole() !== 'superuser'") !== false);
    
    // Check 401 response
    test("{$script}: returns 401 on unauthorized", strpos($content, 'http_response_code(401)') !== false);
    
    // Check 403 response
    test("{$script}: returns 403 on non-superuser", strpos($content, 'http_response_code(403)') !== false);
    
    // Check audit logging
    test("{$script}: logs admin action", strpos($content, 'ADMIN ACTION:') !== false);
}

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
