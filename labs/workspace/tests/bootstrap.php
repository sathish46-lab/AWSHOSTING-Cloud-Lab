<?php
/**
 * Test Bootstrap — Sets up the testing environment.
 * 
 * Include this at the top of every test file:
 *   require_once __DIR__ . '/bootstrap.php';
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

// Test configuration
define('TEST_BASE_URL', $_ENV['TEST_BASE_URL'] ?? 'http://localhost:8083');
define('TEST_DB_NAME', 'tom_labs_test_db');
define('TEST_HOST_HEADER', $_ENV['TEST_HOST_HEADER'] ?? 'dev.tomweb.in');

// Detect project root (works both locally and inside Docker container)
function detect_project_root(): string {
    $candidates = [
        // Inside Docker container: /var/www/labs/workspace/tests → /var/www/labs
        realpath('/var/www/labs'),
        // Local dev: workspace/tests → workspace → labs → Dev_lab/labs
        dirname(__DIR__, 2),  // workspace/tests → workspace
        dirname(__DIR__),     // workspace/tests → tests (no)
    ];
    
    foreach ($candidates as $root) {
        if ($root && is_dir($root . '/htdocs/src/lib/core')) {
            return $root;
        }
    }
    
    // Fallback: walk up from this file
    $dir = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        $dir = dirname($dir);
        if (is_dir($dir . '/htdocs/src/lib/core')) {
            return $dir;
        }
    }
    
    return dirname(__DIR__, 2);
}

define('PROJECT_ROOT', detect_project_root());

// Load the application bootstrap
$loadPath = PROJECT_ROOT . '/htdocs/src/load.php';
if (file_exists($loadPath)) {
    require_once $loadPath;
} else {
    fwrite(STDERR, "WARNING: Could not find load.php at $loadPath\n");
}

// Path to src/ directory for direct file checks
define('SRC_PATH', PROJECT_ROOT . '/htdocs/src');

// Test counters
$GLOBALS['test_passed'] = 0;
$GLOBALS['test_failed'] = 0;
$GLOBALS['test_skipped'] = 0;
$GLOBALS['test_results'] = [];

/**
 * Assert a condition.
 */
function test(string $name, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo "  PASS: $name\n";
        $GLOBALS['test_passed']++;
    } else {
        echo "  FAIL: $name" . ($detail ? " — $detail" : '') . "\n";
        $GLOBALS['test_failed']++;
    }
    $GLOBALS['test_results'][] = ['name' => $name, 'passed' => $condition];
}

/**
 * Skip a test with reason.
 */
function skip(string $name, string $reason): void {
    echo "  SKIP: $name — $reason\n";
    $GLOBALS['test_skipped']++;
}

/**
 * Print test summary and exit with appropriate code.
 */
function test_summary(): void {
    $p = $GLOBALS['test_passed'];
    $f = $GLOBALS['test_failed'];
    $s = $GLOBALS['test_skipped'];
    echo "\n=== Results: $p passed, $f failed, $s skipped ===\n";
    exit($f > 0 ? 1 : 0);
}

/**
 * Make an HTTP request and return response info.
 */
function http_request(
    string $method,
    string $path,
    array $options = []
): array {
    $url = TEST_BASE_URL . $path;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    // Headers — always include Host header for Apache vhost routing
    $headers = $options['headers'] ?? [];
    $headers[] = 'Host: ' . TEST_HOST_HEADER;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Body
    if (isset($options['body'])) {
        if (is_array($options['body'])) {
            $body = http_build_query($options['body']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
    }
    
    // Cookies
    if (isset($options['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'body_json' => null, 'error' => $error];
    }
    
    $headersRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    // Parse response headers
    $responseHeaders = [];
    foreach (explode("\r\n", $headersRaw) as $line) {
        if (strpos($line, ':') !== false) {
            [$key, $val] = explode(':', $line, 2);
            $responseHeaders[trim($key)] = trim($val);
        }
    }
    
    return [
        'status' => $httpCode,
        'headers' => $responseHeaders,
        'body' => $body,
        'body_json' => json_decode($body, true),
    ];
}

/**
 * Create a test user and return session token.
 */
function create_test_user(string $email = 'test@example.com', string $role = 'user'): string {
    $db = DatabaseConnection::getDefaultDatabase();
    
    // Clean up any existing test user
    $db->users->deleteMany(['email' => $email]);
    
    // Create user
    $db->users->insertOne([
        'email' => $email,
        'username' => explode('@', $email)[0],
        'role' => $role,
        'password' => password_hash('TestPassword123!', PASSWORD_BCRYPT),
        'created_at' => time(),
        'last_login' => time(),
    ]);
    
    // Create session token
    $token = bin2hex(random_bytes(32));
    $tokenId = hash('sha256', $token);
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    
    // Embed session token in user document (WebAPI expects this)
    $db->users->updateOne(
        ['email' => $email],
        ['$push' => ['session_tokens' => [
            'token_hash' => $tokenHash,
            'token_id' => $tokenId,
            'ip' => '127.0.0.1',
            'browser' => 'PHPUnit',
            'os' => 'CLI',
            'mobile' => false,
            'created_at' => time(),
            'last_activity' => time(),
        ]]]
    );
    
    return $token;
}

/**
 * Clean up test user.
 */
function cleanup_test_user(string $email = 'test@example.com'): void {
    try {
        $db = DatabaseConnection::getDefaultDatabase();
        $db->users->deleteMany(['email' => $email]);
        $db->session_tokens->deleteMany(['email' => $email]);
    } catch (Exception $e) {
        // Ignore cleanup errors
    }
}

/**
 * Get a CSRF token from the page.
 */
function get_csrf_token(string $sessionToken): ?string {
    $response = http_request('GET', '/dashboard', [
        'cookie' => "session_token=$sessionToken",
    ]);
    
    if (preg_match('/<meta name="csrf-token" content="([^"]+)">/', $response['body'], $m)) {
        return $m[1];
    }
    
    return null;
}
