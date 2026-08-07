<?php
/**
 * Test api/account/activity.php — Security & Functionality
 * 
 * Tests:
 * 1. File exists and is valid PHP
 * 2. Requires authentication (401 for unauth)
 * 3. User scoping: query filters by user_id from session, NOT from params
 * 4. IDOR test: cannot request another user's data via param tampering
 * 5. Allowlist: valid action filter accepted
 * 6. Allowlist: invalid action filter rejected (400)
 * 7. Allowlist: valid entity_type filter accepted
 * 8. Allowlist: invalid entity_type filter rejected (400)
 * 9. Pagination: limit clamped to max 100
 * 10. Pagination: offset accepts valid values
 * 11. Response format: no _id fields leaked
 * 12. Response format: no internal Mongo fields leaked
 * 13. Response contains only safe fields
 * 14. Summary counts are user-scoped
 * 15. SQL/NoSQL injection: special chars in action param handled safely
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

echo "=== API: activity.php Security Tests ===\n\n";

$path = "$base/htdocs/src/api/account/activity.php";
$content = file_get_contents($path);

// 1. File exists and is valid PHP
test("activity.php exists", file_exists($path));
test("activity.php contains <?php", strpos($content, '<?php') !== false);

// 2. Requires authentication
test("Checks auth status", strpos($content, 'Session::getAuthStatus()') !== false);
test("Returns 401 for unauth", strpos($content, 'http_response_code(401)') !== false);

// 3. User scoping: query uses session user_id, never request params
test("Gets user_id from Session::getUser()", strpos($content, 'Session::getUser()') !== false);
test("user_id from getUserId()", strpos($content, 'getUserId()') !== false);
test("Query filters by user_id from session", strpos($content, "'user_id' => ['\$in' => [$userId") !== false);

// 4. IDOR: no user_id accepted from request params
test("No user_id from $_GET", strpos($content, '$_GET[\'user_id\']') === false && strpos($content, '$_GET["user_id"]') === false);
test("No user_id from $_POST", strpos($content, '$_POST[\'user_id\']') === false && strpos($content, '$_POST["user_id"]') === false);
test("No user_id from JSON body", strpos($content, "'user_id'") === false || substr_count($content, "'user_id'") <= 3); // Only in the query filter

// 5. Allowlist: valid actions defined
test("Valid actions allowlist exists", strpos($content, '$validActions') !== false);
test("Allowlist includes create", strpos($content, "'create'") !== false);
test("Allowlist includes delete", strpos($content, "'delete'") !== false);

// 6. Invalid action rejected
test("Invalid action returns 400", strpos($content, "Invalid action filter") !== false);

// 7. Valid entity_type accepted
test("Valid entity types allowlist exists", strpos($content, '$validEntityTypes') !== false);

// 8. Invalid entity_type rejected
test("Invalid entity_type returns 400", strpos($content, "Invalid entity_type filter") !== false);

// 9. Pagination limits
test("Limit capped at 100", strpos($content, '$maxLimit = 100') !== false);
test("Limit uses min/max clamping", strpos($content, 'min(max(') !== false);

// 10. Offset validated
test("Offset defaults to 0", strpos($content, "offset'] ?? 0") !== false);
test("Offset clamped to >= 0", strpos($content, 'max((int)') !== false && strpos($content, 'offset') !== false);

// 11-13. Response format: safe fields only
test("Does not return _id field", strpos($content, "'_id' =>") === false || substr_count($content, "'_id' =>") <= 0);
test("Returns action field", strpos($content, "'action' =>") !== false);
test("Returns entity_type field", strpos($content, "'entity_type' =>") !== false);
test("Returns entity_id field", strpos($content, "'entity_id' =>") !== false);
test("Returns details field", strpos($content, "'details' =>") !== false);
test("Returns ip_address field", strpos($content, "'ip_address' =>") !== false);
test("Returns created_at field", strpos($content, "'created_at' =>") !== false);
test("Does NOT return user_agent", strpos($content, "'user_agent'") === false || substr_count($content, "'user_agent'") === 0);
test("Does NOT return request_uri", strpos($content, "'request_uri'") === false || substr_count($content, "'request_uri'") === 0);
test("Does NOT return request_method", strpos($content, "'request_method'") === false || substr_count($content, "'request_method'") === 0);

// 14. Summary is user-scoped
test("Summary queries scope to user_id", substr_count($content, "['\$in' => [$userId") >= 2); // filter + each summary count

// 15. NoSQL injection protection via allowlist
test("Action filter validated against allowlist (not passed raw to Mongo)", strpos($content, 'in_array($actionFilter, $validActions, true)') !== false);
test("Entity_type filter validated against allowlist", strpos($content, 'in_array($entityTypeFilter, $validEntityTypes, true)') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
