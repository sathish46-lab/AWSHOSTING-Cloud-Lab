<?php
/**
 * Global & Action-Specific Request Rate Limiter
 * Protects endpoints against DoS, brute force, and automated scraping.
 * Supports per-action limits (e.g. combined SSL troubleshoot+refresh limit).
 */

/**
 * Determines unique client identity with strict preference order:
 * 1st Preference: Email Address (from session or POST only, never GET)
 * 2nd Preference: User ID / Username
 * 3rd Preference: Client IP Address (with proxy validation)
 */
function get_rate_limit_identity($rawEmailOnly = false) {
    $email = null;
    $userId = null;
    
    // 1st Preference: Check Email Address (POST only for unauthenticated, session for authenticated)
    if (!empty($_POST['email'])) {
        $email = trim((string)$_POST['email']);
    } elseif (!empty($_SESSION['email'])) {
        $email = $_SESSION['email'];
    } elseif (!empty($_SESSION['user_email'])) {
        $email = $_SESSION['user_email'];
    } elseif (!empty($_SESSION['2fa_pending_email'])) {
        $email = $_SESSION['2fa_pending_email'];
    } elseif (class_exists('Session') && ($userObj = Session::getUser())) {
        if (method_exists($userObj, 'getEmail') && !empty($userObj->getEmail())) {
            $email = $userObj->getEmail();
        }
        if (method_exists($userObj, 'getUserId') && !empty($userObj->getUserId())) {
            $userId = $userObj->getUserId();
        } elseif (method_exists($userObj, 'getUsername') && !empty($userObj->getUsername())) {
            $userId = $userObj->getUsername();
        }
    }

    if ($rawEmailOnly) {
        return $email;
    }

    if (!empty($email)) {
        return 'em_' . md5(strtolower(trim($email)));
    }

    // 2nd Preference: Check User ID / Username if email failed
    if (empty($userId)) {
        if (!empty($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        } elseif (!empty($_SESSION['username'])) {
            $userId = $_SESSION['username'];
        }
    }

    if (!empty($userId)) {
        return 'usr_' . md5((string)$userId);
    }

    // 3rd Preference: Fallback to Client IP Address
    // Only trust X-Forwarded-For if request comes from a known proxy
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Check if the direct client is a known proxy
        $trustedProxies = ['127.0.0.1', '::1']; // Add your proxy IPs here
        $directClient = $_SERVER['REMOTE_ADDR'] ?? '';
        if (in_array($directClient, $trustedProxies)) {
            // Take the leftmost (original client) IP from X-Forwarded-For
            $forwardedIps = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $clientIp = $forwardedIps[0] ?? $clientIp;
        }
    }
    return 'ip_' . md5($clientIp);
}

/**
 * Enforces per-action rate limits (e.g., combined SSL troubleshoot & refresh).
 * 
 * @param string $actionPrefix Action prefix/key (e.g., 'ssl:rl:refresh')
 * @param int $limit Maximum allowed executions in time window (default: 3)
 * @param int $window Time window in seconds (default: 600 for 10 minutes)
 * @return bool True if allowed, otherwise outputs HTTP 429 and terminates
 */
function rate_limit($actionPrefix = 'ssl:rl:refresh', $limit = 3, $window = 600) {
    if (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] === 'CLI') {
        return true;
    }

    // Use raw email MD5 if available to match requested pattern: ssl:rl:refresh:<md5(email)>
    $rawEmail = get_rate_limit_identity(true);
    if (!empty($rawEmail)) {
        $clientSuffix = md5(strtolower(trim($rawEmail)));
    } else {
        $clientSuffix = get_rate_limit_identity(false);
    }

    $actionKey = md5($actionPrefix . ':' . $clientSuffix);
    $currentWindow = (int)floor(time() / $window);
    
    $storageDir = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm/ratelimit_actions' : '/tmp/ratelimit_actions';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0777, true);
    }

    $file = $storageDir . '/' . $actionKey . '_' . $currentWindow . '.count';

    // Garbage collection for previous action buckets
    if (mt_rand(1, 10) === 1) {
        @unlink($storageDir . '/' . $actionKey . '_' . ($currentWindow - 1) . '.count');
        @unlink($storageDir . '/' . $actionKey . '_' . ($currentWindow - 2) . '.count');
    }

    $count = 1;
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        $count = ((int)$content) + 1;
    }
    @file_put_contents($file, (string)$count, LOCK_EX);

    $remaining = max(0, $limit - $count);
    $resetTime = ($currentWindow + 1) * $window;
    
    if (!headers_sent()) {
        header('X-Action-RateLimit-Limit: ' . $limit);
        header('X-Action-RateLimit-Remaining: ' . $remaining);
        header('X-Action-RateLimit-Reset: ' . $resetTime);
    }

    if ($count > $limit) {
        $retryAfter = max(1, $resetTime - time());
        http_response_code(429);
        
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        
        $isHtmx = !empty($_SERVER['HTTP_HX_REQUEST']);
        $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isHtmx || $isApi || $isAjax) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'status' => 'error',
                'error' => 'Rate limit exceeded for this action (maximum ' . $limit . ' runs per ' . ($window / 60) . ' minutes). Please try again in ' . ceil($retryAfter / 60) . ' min.',
                'rate_limited' => true,
                'retry_after' => $retryAfter
            ]);
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo '<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Action Rate Limit Exceeded</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1e36; color: #fff; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; text-align: center; }
                    .card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 40px; border-radius: 16px; max-width: 500px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); backdrop-filter: blur(10px); }
                    h1 { color: #ff6b6b; font-size: 2rem; margin: 0 0 15px; }
                    p { font-size: 1.1rem; opacity: 0.8; line-height: 1.6; margin: 0 0 20px; }
                    .timer { font-size: 1.2rem; color: #fca311; font-weight: bold; background: rgba(252,163,17,0.1); padding: 10px 20px; border-radius: 8px; display: inline-block; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>Action Limit Exceeded</h1>
                    <p>You have reached the maximum allowed limit of <b>' . $limit . ' runs</b> per ' . ($window / 60) . ' minutes for this operation.</p>
                    <div class="timer">Retry in ' . ceil($retryAfter / 60) . ' min (' . $retryAfter . 's)</div>
                </div>
            </body>
            </html>';
        }
        exit;
    }
    return true;
}

/**
 * Backward compatibility alias for rate_limit.
 */
function check_action_rate_limit($actionPrefix = 'ssl:rl:refresh', $limit = 3, $window = 600) {
    return rate_limit($actionPrefix, $limit, $window);
}

/**
 * Global request rate limiter with centralized action routing.
 */
function check_global_rate_limit() {
    if (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] === 'CLI') {
        return;
    }

    // 1. Centralized Action Routing Table
    // Automatically applies action-specific rate limits without editing individual PHP page files.
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $actionRules = [
        [
            'pattern' => '#^/api/ssl/(troubleshoot|refresh)#i',
            'key'     => 'ssl:rl:refresh',
            'limit'   => 3,
            'window'  => 300
        ],
        [
            'pattern' => '#^/(auth/)?signin#i',
            'methods' => ['POST'],
            'key'     => 'auth:rl:signin',
            'limit'   => 5,
            'window'  => 900
        ],
        [
            'pattern' => '#^/(auth/)?signup#i',
            'methods' => ['POST'],
            'key'     => 'auth:rl:signup',
            'limit'   => 5,
            'window'  => 1800
        ],
        [
            'pattern' => '#^/(auth/)?forgot#i',
            'methods' => ['POST'],
            'key'     => 'auth:rl:forgot',
            'limit'   => 3,
            'window'  => 900
        ],
        [
            'pattern' => '#^/api/auth/verify_login_2fa#i',
            'methods' => ['POST'],
            'key'     => 'auth:rl:2fa',
            'limit'   => 5,
            'window'  => 300
        ],
        [
            'pattern' => '#^/api/account/change_password#i',
            'methods' => ['POST'],
            'key'     => 'account:rl:change_password',
            'limit'   => 3,
            'window'  => 3600
        ]
    ];

    foreach ($actionRules as $rule) {
        if (!empty($rule['methods']) && !in_array($method, $rule['methods'], true)) {
            continue;
        }
        if (preg_match($rule['pattern'], $uri)) {
            rate_limit($rule['key'], $rule['limit'], $rule['window']);
            break;
        }
    }

    // 2. Default Global Request Limiter (300 req / 60s = 5 req/s)
    $limit = (int)(get_config('rate_limit_max') ?: 300);
    $window = (int)(get_config('rate_limit_window') ?: 60);

    $clientId = get_rate_limit_identity(false);
    $currentWindow = (int)floor(time() / $window);
    
    $storageDir = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm/ratelimit' : '/tmp/ratelimit';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0777, true);
    }

    $file = $storageDir . '/' . $clientId . '_' . $currentWindow . '.count';

    if (mt_rand(1, 20) === 1) {
        @unlink($storageDir . '/' . $clientId . '_' . ($currentWindow - 1) . '.count');
        @unlink($storageDir . '/' . $clientId . '_' . ($currentWindow - 2) . '.count');
    }

    $count = 1;
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        $count = ((int)$content) + 1;
    }
    @file_put_contents($file, (string)$count, LOCK_EX);

    $remaining = max(0, $limit - $count);
    $resetTime = ($currentWindow + 1) * $window;
    
    if (!headers_sent()) {
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $resetTime);
    }

    if ($count > $limit) {
        $retryAfter = max(1, $resetTime - time());
        http_response_code(429);
        
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        
        $isHtmx = !empty($_SERVER['HTTP_HX_REQUEST']);
        $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isHtmx || $isApi || $isAjax) {
            // All AJAX/HTMX/API requests get JSON — JS handles the UI
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'status' => 'error',
                'error' => 'Too Many Requests. Rate limit exceeded (' . $limit . ' requests per ' . $window . 's). Please slow down.',
                'rate_limited' => true,
                'retry_after' => $retryAfter
            ]);
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo '<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>429 Too Many Requests</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1e36; color: #fff; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; text-align: center; }
                    .card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 40px; border-radius: 16px; max-width: 500px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); backdrop-filter: blur(10px); }
                    h1 { color: #ff6b6b; font-size: 2.5rem; margin: 0 0 15px; }
                    p { font-size: 1.1rem; opacity: 0.8; line-height: 1.6; margin: 0 0 20px; }
                    .timer { font-size: 1.3rem; color: #fca311; font-weight: bold; background: rgba(252,163,17,0.1); padding: 10px 20px; border-radius: 8px; display: inline-block; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>429 - Slow Down</h1>
                    <p>You have exceeded the request rate limit of <b>' . $limit . ' requests</b> per ' . $window . ' seconds. This protection prevents server flooding and API abuse.</p>
                    <div class="timer">Retry in ' . $retryAfter . 's</div>
                </div>
            </body>
            </html>';
        }
        exit;
    }
}

// Automatically enforce global rate limit on file inclusion
check_global_rate_limit();
