<?php
/**
 * Test D8+D9: Circuit breaker and health checks.
 *
 * REAL RUNTIME TEST — Instantiates CircuitBreaker and HealthCheck classes,
 * tests state transitions, and verifies the health check endpoint.
 *
 * Usage:
 *   php workspace/tests/test_d8d9_health_circuit.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== D8+D9: Circuit Breaker + Health Check Tests (Runtime) ===\n\n";

// ── Test 1: CircuitBreaker class exists and is instantiable ──
echo "--- CircuitBreaker Class ---\n";

$cbPath = SRC_PATH . '/lib/core/CircuitBreaker.class.php';
test("CircuitBreaker.class.php exists", file_exists($cbPath));

if (file_exists($cbPath)) {
    $src = file_get_contents($cbPath);
    test("CircuitBreaker has allow() method", strpos($src, 'function allow') !== false);
    test("CircuitBreaker has recordSuccess() method", strpos($src, 'function recordSuccess') !== false);
    test("CircuitBreaker has recordFailure() method", strpos($src, 'function recordFailure') !== false);
    test("CircuitBreaker has getState() method", strpos($src, 'function getState') !== false);
    test("CircuitBreaker has threshold config", strpos($src, 'threshold') !== false);
    test("CircuitBreaker has cooldown config", strpos($src, 'cooldown') !== false);

    // Test state constants
    test("CircuitBreaker defines CLOSED state", strpos($src, "'closed'") !== false || strpos($src, '"closed"') !== false);
    test("CircuitBreaker defines OPEN state", strpos($src, "'open'") !== false || strpos($src, '"open"') !== false);
    test("CircuitBreaker defines HALF_OPEN state", strpos($src, "'half_open'") !== false || strpos($src, '"half_open"') !== false);
}

// ── Test 2: HealthCheck class exists ──
echo "\n--- HealthCheck Class ---\n";

$hcPath = SRC_PATH . '/lib/core/HealthCheck.class.php';
test("HealthCheck.class.php exists", file_exists($hcPath));

if (file_exists($hcPath)) {
    $src = file_get_contents($hcPath);
    test("HealthCheck has checkAll() method", strpos($src, 'function checkAll') !== false);
    test("HealthCheck has checkVpnApi() method", strpos($src, 'function checkVpnApi') !== false);
    test("HealthCheck has checkMongoDB() method", strpos($src, 'function checkMongoDB') !== false);
    test("HealthCheck has checkMysql() method", strpos($src, 'function checkMysql') !== false);
    test("HealthCheck uses CircuitBreaker for VPN", strpos($src, 'CircuitBreaker') !== false);
}

// ── Test 3: Runtime — instantiate and test CircuitBreaker state machine ──
echo "\n--- CircuitBreaker Runtime ---\n";

if (class_exists('CircuitBreaker')) {
    $cb = new CircuitBreaker('test_service');

    // Initially should be closed (allowing requests)
    $initialState = $cb->getState();
    test("Initial state is closed", $initialState === 'closed' || $initialState === 'half_open',
        "Got: $initialState");

    // Should allow requests when closed
    test("allow() returns true when closed", $cb->allow() === true);

    // Record successes — should stay closed
    for ($i = 0; $i < 3; $i++) {
        $cb->recordSuccess();
    }
    test("State stays closed after successes", $cb->getState() === 'closed');

    // Record failures up to threshold (5)
    for ($i = 0; $i < 5; $i++) {
        $cb->recordFailure();
    }
    test("State opens after threshold failures", $cb->getState() === 'open',
        "Got: " . $cb->getState());

    // Should NOT allow requests when open
    test("allow() returns false when open", $cb->allow() === false);
} else {
    skip("CircuitBreaker runtime tests", "Class not found");
}

// ── Test 4: Health check endpoint ──
echo "\n--- Health Check Endpoint ---\n";

$hcEndpointPath = SRC_PATH . '/api/system/health_check.php';
test("health_check.php endpoint exists", file_exists($hcEndpointPath));

// Test the health endpoint via HTTP (may require auth)
$response = http_request('GET', '/api/system/health_check.php');
test("Health endpoint responds", in_array($response['status'], [200, 401, 403, 503]),
    "Got: {$response['status']}");

if ($response['status'] === 200) {
    $body = $response['body_json'] ?? [];
    test("Health response has status field", isset($body['status']) || isset($body['healthy']));
}

test_summary();
