<?php
/**
 * Main Loader: Handles environment, vendors, and core libraries.
 */
require_once __DIR__ . '/utils/config.php';

// 1. Start session first (without using any classes yet)
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = get_session_lifetime();
    ini_set('session.gc_maxlifetime', $lifetime);
    ini_set('session.cookie_lifetime', $lifetime);
    
    // Security: HTTPS-only cookies, prevent JS access, strict mode
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    
    // SE3+S22: Set Secure and SameSite flags before session_start()
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    
    // Fix Ubuntu Cron Job Session Deletion Bug
    $sessionPath = '/var/cache/labs/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0733, true);
        @chown($sessionPath, 'www-data');
    }
    ini_set('session.save_path', $sessionPath);
    
    // Optional: increase probability to clean up old sessions
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
    
    session_start();
}

// 1.5 Global Request Rate Limiter (Runs early to reject DDoS/flooding without database overhead)
require_once __DIR__ . '/utils/ratelimit.php';

// 1.6 Security Headers (sent before any output)
if (!function_exists('send_security_headers')) {
    function send_security_headers() {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // Prevent MIME-type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // XSS Protection (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions Policy (restrict browser features)
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        
        // Content Security Policy (restrictive default, allow self + common CDNs)
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "img-src 'self' data: blob: https:",
            "connect-src 'self' ws: wss: https:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
        
        // HSTS (only in production HTTPS)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    send_security_headers();
}

// 2. Load Composer and Libraries FIRST (before using any classes)
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/utils/common.php'; 
require_once __DIR__ . '/lib/load.php';

// 3. NOW we can use Constants class - regenerate session cookie
if (isset($_SESSION['auth_status']) && $_SESSION['auth_status'] === Constants::STATUS_LOGGEDIN) {
    $cookieParams = session_get_cookie_params();
    $lifetime = get_session_lifetime();
    $domain = get_session_domain();

    setcookie(
        session_name(),
        session_id(),
        [
            'expires'  => time() + $lifetime,
            'path'     => $cookieParams['path'],
            'domain'   => $domain,
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                          (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}
/**
 * Global Helpers
 */
if (!function_exists('require_ui_component')) {
    function require_ui_component($file) {
        $path = __DIR__ . "/ui/" . $file . '.php';
        if (!file_exists($path)) {
            throw new Exception("UI Component not found: " . $file);
        }
        require_once $path;
    }
}
// 3. Sync PHP Session to Session Class (Safe now because Session class is loaded)
if (isset($_SESSION['auth_status'])) {
    Session::$authStatus = $_SESSION['auth_status'];
}
// 4. Global Exception and Error Handlers
if (!function_exists('global_exception_handler')) {
    function global_exception_handler($e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Only handle if Session class is available to render the beautiful page
        if (class_exists('Session')) {
            Session::set('error_exception', $e);
            Session::loadErrorPage();
            exit;
        } else {
            // Fallback for extremely early fatal errors
            echo "Fatal Error: " . htmlspecialchars($e->getMessage());
            exit;
        }
    }
}
set_exception_handler('global_exception_handler');

register_shutdown_function(function() {
    $error = error_get_last();
    // Catch fatal errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Convert to ErrorException to pass to our handler
        $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        global_exception_handler($e);
    }
});
if (!function_exists('cdn')) {
    function cdn($url) {
        return $url;
    }
}