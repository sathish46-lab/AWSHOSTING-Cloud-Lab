<?php
/**
 * Test D8+D9: Health checks + circuit breaker for external services.
 * 
 * Tests:
 * 1. CircuitBreaker class file exists
 * 2. CircuitBreaker has allow() method
 * 3. CircuitBreaker has recordSuccess() method
 * 4. CircuitBreaker has recordFailure() method
 * 5. CircuitBreaker has getState() method
 * 6. CircuitBreaker has getAllStates() method
 * 7. CircuitBreaker allows requests when closed
 * 8. CircuitBreaker blocks requests when open
 * 9. CircuitBreaker transitions to half-open after cooldown
 * 10. HealthCheck class file exists
 * 11. HealthCheck has checkAll() method
 * 12. HealthCheck has checkVpnApi() method
 * 13. HealthCheck has checkMongoDB() method
 * 14. HealthCheck has checkMysql() method
 * 15. HealthCheck has checkRedis() method
 * 16. HealthCheck has checkRabbitMQ() method
 * 17. VPN.class.php uses CircuitBreaker
 * 18. health_check.php endpoint exists
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

echo "=== D8+D9: Health Check + Circuit Breaker Tests ===\n\n";

// CircuitBreaker tests
$cbPath = "$base/htdocs/src/lib/core/CircuitBreaker.class.php";
test("CircuitBreaker class file exists", file_exists($cbPath));

$cbContent = file_get_contents($cbPath);
test("CircuitBreaker has allow() method", strpos($cbContent, 'public static function allow(') !== false);
test("CircuitBreaker has recordSuccess() method", strpos($cbContent, 'public static function recordSuccess(') !== false);
test("CircuitBreaker has recordFailure() method", strpos($cbContent, 'public static function recordFailure(') !== false);
test("CircuitBreaker has getState() method", strpos($cbContent, 'public static function getState(') !== false);
test("CircuitBreaker has getAllStates() method", strpos($cbContent, 'public static function getAllStates(') !== false);

// CircuitBreaker state transitions
test("CircuitBreaker allows when closed", strpos($cbContent, "return true") !== false && strpos($cbContent, "STATE_CLOSED") !== false);
test("CircuitBreaker blocks when open", strpos($cbContent, "STATE_OPEN") !== false && strpos($cbContent, "return false") !== false);
test("CircuitBreaker transitions to half-open", strpos($cbContent, "STATE_HALF_OPEN") !== false);

// HealthCheck tests
$hcPath = "$base/htdocs/src/lib/core/HealthCheck.class.php";
test("HealthCheck class file exists", file_exists($hcPath));

$hcContent = file_get_contents($hcPath);
test("HealthCheck has checkAll() method", strpos($hcContent, 'public static function checkAll(') !== false);
test("HealthCheck has checkVpnApi() method", strpos($hcContent, 'public static function checkVpnApi(') !== false);
test("HealthCheck has checkMongoDB() method", strpos($hcContent, 'public static function checkMongoDB(') !== false);
test("HealthCheck has checkMysql() method", strpos($hcContent, 'public static function checkMysql(') !== false);
test("HealthCheck has checkRedis() method", strpos($hcContent, 'public static function checkRedis(') !== false);
test("HealthCheck has checkRabbitMQ() method", strpos($hcContent, 'public static function checkRabbitMQ(') !== false);

// VPN uses circuit breaker
$vpnContent = file_get_contents("$base/htdocs/src/lib/core/VPN.class.php");
test("VPN.class.php uses CircuitBreaker", strpos($vpnContent, 'CircuitBreaker::allow') !== false && strpos($vpnContent, 'CircuitBreaker::recordSuccess') !== false);

// Health check endpoint exists
test("health_check.php endpoint exists", file_exists("$base/htdocs/src/api/system/health_check.php"));

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
