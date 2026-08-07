<?php
/**
 * Test api/account/activity_analytics.php — Security & Functionality
 * 
 * Tests:
 * 1. File exists and is valid PHP
 * 2. Requires authentication (401 for unauth)
 * 3. User scoping: every aggregation filters by session user_id
 * 4. IDOR: no user_id accepted from request params
 * 5. Action breakdown: returns array of {action, count}
 * 6. Entity breakdown: returns array of {entity_type, count}
 * 7. Daily trend: returns array of {date, count}
 * 8. Hourly activity: returns 24-element array
 * 9. Security events: returns array with safe fields only
 * 10. Security events: limited to 20 most recent
 * 11. Summary stats: total_actions, active_days, this_week, most_common_action
 * 12. No _id fields in any response data
 * 13. No internal Mongo fields leaked
 * 14. Error handling: generic error message on failure
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

echo "=== API: activity_analytics.php Security Tests ===\n\n";

$path = "$base/htdocs/src/api/account/activity_analytics.php";
$content = file_get_contents($path);

// 1. File exists and is valid PHP
test("activity_analytics.php exists", file_exists($path));
test("activity_analytics.php contains <?php", strpos($content, '<?php') !== false);

// 2. Requires authentication
test("Checks auth status", strpos($content, 'Session::getAuthStatus()') !== false);
test("Returns 401 for unauth", strpos($content, 'http_response_code(401)') !== false);

// 3. User scoping
test("Gets user from Session", strpos($content, 'Session::getUser()') !== false);
test("user_id from getUserId()", strpos($content, 'getUserId()') !== false);
test("User filter uses \$in with session userId", strpos($content, "['\$in' => [\$userId") !== false);

// 4. IDOR protection
test("No user_id from GET", strpos($content, '$_GET') === false || strpos($content, '$_GET') === false);

// 5-6. Breakdown arrays
test("Action breakdown pipeline exists", strpos($content, 'action_breakdown') !== false);
test("Entity breakdown pipeline exists", strpos($content, 'entity_breakdown') !== false);

// 7. Daily trend
test("Daily trend pipeline exists", strpos($content, 'daily_trend') !== false);
test("Daily trend uses 30-day window", strpos($content, '30 * 86400') !== false);

// 8. Hourly activity
test("Hourly activity returns 24-element array", strpos($content, 'array_fill(0, 24, 0)') !== false);

// 9. Security events: safe fields only
test("Security events limited to safe fields", strpos($content, "'action' => 1") !== false);
test("Security events includes ip_address", strpos($content, "'ip_address' => 1") !== false);
test("Security events does NOT return user_agent", strpos($content, "'user_agent'") === false || substr_count($content, "'user_agent'") === 0);
test("Security events does NOT return request_uri", strpos($content, "'request_uri'") === false || substr_count($content, "'request_uri'") === 0);

// 10. Security events limit
test("Security events limited to 20", strpos($content, "'\$limit' => 20") !== false);

// 11. Summary stats
test("Summary includes total_actions", strpos($content, "'total_actions'") !== false);
test("Summary includes active_days", strpos($content, "'active_days'") !== false);
test("Summary includes this_week", strpos($content, "'this_week'") !== false);
test("Summary includes most_common_action", strpos($content, "'most_common_action'") !== false);

// 12-13. No internal data leaked (response entries don't contain _id)
// Note: _id appears in $group aggregation pipeline stages (expected), but NOT in response entries
test("Action breakdown response uses 'action' not '_id'", strpos($content, "'action' => \$doc['_id']") !== false);
test("Entity breakdown response uses 'entity_type' not '_id'", strpos($content, "'entity_type' => \$doc['_id']") !== false);
test("Daily trend response uses 'date' not '_id'", strpos($content, "'date' => \$doc['_id']") !== false);
test("Hourly response maps _id to array index", strpos($content, "\$hour = (int)\$doc['_id']") !== false);
test("Error message is generic", strpos($content, "Failed to load analytics") !== false);

// 14. User filter applied to all pipelines
test("User filter applied to action pipeline", strpos($content, 'userFilter') !== false);
test("User filter applied to daily pipeline", substr_count($content, 'userFilter') >= 4);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
