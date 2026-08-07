<?php
/**
 * Test api/account/update_profile.php — Security & Functionality
 * 
 * Tests:
 * 1. File exists and is valid PHP
 * 2. Requires authentication (401 for unauth)
 * 3. CSRF protection required
 * 4. First name length limited to 50 chars
 * 5. Last name length limited to 50 chars
 * 6. Control characters stripped from input
 * 7. User scoping: updates by email from session, NOT from params
 * 8. IDOR: no user_id accepted from request params
 * 9. Returns error if both names empty
 * 10. Input is trimmed
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

echo "=== API: update_profile.php Security Tests ===\n\n";

$path = "$base/htdocs/src/api/account/update_profile.php";
$content = file_get_contents($path);

// 1. File exists and is valid PHP
test("update_profile.php exists", file_exists($path));
test("update_profile.php contains <?php", strpos($content, '<?php') !== false);

// 2. Requires authentication
test("Checks auth status", strpos($content, 'Session::getAuthStatus()') !== false);
test("Returns 401 for unauth", strpos($content, 'json_encode([\'status\' => \'error\', \'error\' => \'Unauthorized\'])') !== false);

// 3. CSRF protection
test("CSRF protection required", strpos($content, 'CsrfProtection::require()') !== false);
test("Includes CsrfProtection class", strpos($content, 'CsrfProtection.class.php') !== false);

// 4-5. Length limits
test("First name limited to 50 chars", strpos($content, "mb_substr(\$firstName, 0, 50)") !== false);
test("Last name limited to 50 chars", strpos($content, "mb_substr(\$lastName, 0, 50)") !== false);

// 6. Control characters stripped
test("Control characters stripped", strpos($content, "preg_replace('/[\\x00-\\x1F\\x7F]/u'") !== false);

// 7. User scoping
test("Updates by email from session", strpos($content, "'email' => \$user->getEmail()") !== false);
test("Does not accept user_id from params", strpos($content, '$_POST[\'user_id\']') === false && strpos($content, '$_GET[\'user_id\']') === false);

// 8. IDOR protection — no user_id from request params in code (comments are OK)
test("No user_id from $_POST/$_GET in code", 
    strpos($content, '$_POST[\'user_id\']') === false && 
    strpos($content, '$_GET[\'user_id\']') === false &&
    strpos($content, '$_POST["user_id"]') === false &&
    strpos($content, '$_GET["user_id"]') === false
);

// 9. Empty validation
test("Returns error if both names empty", strpos($content, "empty(\$firstName) && empty(\$lastName)") !== false);

// 10. Input trimmed
test("Input is trimmed", strpos($content, "trim(\$_POST['first_name']") !== false || strpos($content, 'trim($_POST["first_name"]') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
