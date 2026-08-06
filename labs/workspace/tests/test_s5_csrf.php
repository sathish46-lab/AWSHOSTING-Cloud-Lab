<?php
/**
 * Test S5: CSRF token validation for API routes.
 * 
 * Tests:
 * 1. CsrfProtection class file exists
 * 2. CsrfProtection has validate() method
 * 3. CsrfProtection has require() method
 * 4. CsrfProtection has token() method
 * 5. instances/create.php includes CsrfProtection.class.php
 * 6. instances/trash.php includes CsrfProtection.class.php
 * 7. instances/restore.php includes CsrfProtection.class.php
 * 8. instances/permanent_delete.php includes CsrfProtection.class.php
 * 9. vpn/add.php includes CsrfProtection.class.php
 * 10. vpn/delete.php includes CsrfProtection.class.php
 * 11. services/mysql/create.php includes CsrfProtection.class.php
 * 12. services/mysql/delete.php includes CsrfProtection.class.php
 * 13. All endpoints call CsrfProtection::require()
 */

$base = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: $name\n";
        $passed++;
    } else {
        echo "  FAIL: $name\n";
        $failed++;
    }
}

echo "=== S5: CSRF Protection Tests ===\n\n";

// 1. CsrfProtection class exists
$csrfPath = "$base/htdocs/src/lib/core/CsrfProtection.class.php";
test("CsrfProtection class file exists", file_exists($csrfPath));

// 2-4. CsrfProtection has required methods
$csrfContent = file_get_contents($csrfPath);
test("CsrfProtection has validate() method", strpos($csrfContent, 'public static function validate()') !== false);
test("CsrfProtection has require() method", strpos($csrfContent, 'public static function require()') !== false);
test("CsrfProtection has token() method", strpos($csrfContent, 'public static function token()') !== false);

// 5-12. All critical endpoints include CsrfProtection
$endpoints = [
    'instances/create.php',
    'instances/trash.php',
    'instances/restore.php',
    'instances/permanent_delete.php',
    'vpn/add.php',
    'vpn/delete.php',
    'services/mysql/create.php',
    'services/mysql/delete.php',
];

foreach ($endpoints as $ep) {
    $path = "$base/htdocs/src/api/$ep";
    $content = file_exists($path) ? file_get_contents($path) : '';
    test("$ep includes CsrfProtection.class.php", strpos($content, 'CsrfProtection.class.php') !== false);
}

// 13. All endpoints call CsrfProtection::require()
foreach ($endpoints as $ep) {
    $path = "$base/htdocs/src/api/$ep";
    $content = file_exists($path) ? file_get_contents($path) : '';
    test("$ep calls CsrfProtection::require()", strpos($content, 'CsrfProtection::require()') !== false);
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
