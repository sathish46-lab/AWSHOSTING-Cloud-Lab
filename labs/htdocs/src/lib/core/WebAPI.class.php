<?php

class WebAPI {
    // public function __construct() {
    //     if (System::getOS() <= 2) { throw new UnsupportedEnvironmentException(); }
    //     if (!extension_loaded('mongodb')) { die("Unable to load mongodb.so"); }

    //     $build = 'beta'; 
    //     if (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], get_config('allowed_hosts'))) {
    //         Session::set('php', '/usr/bin/php');
    //     }
    //     Session::$environment = $build;
    //     DatabaseConnection::getClient(); 
    // }
    public function __construct() {
        if (System::getOS() <= 2) { throw new UnsupportedEnvironmentException(); }
        if (!extension_loaded('mongodb')) {
            if (php_sapi_name() !== 'cli') {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'error' => 'MongoDB extension not loaded']);
            }
            exit;
        }

        // DYNAMIC ENVIRONMENT DETECTION
        Session::$environment = is_local() ? 'local' : 'beta';

        DatabaseConnection::getClient(); 
    }

    public function initSession() {
    global $__start;

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) { 
        session_start(); 
    }

    // Manual Session Expiration Check (Crucial for Production GC reliability)
    $lifetime = get_session_lifetime();

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $lifetime)) {
        UserSession::logout();
        return; // Stop processing to ensure the user stays logged out for this request
    }
    $_SESSION['last_activity'] = time();

    // Prioritize active PHP Session
    $username = $_SESSION['username'] ?? null;
    $sessionToken = $_COOKIE['session_token'] ?? null;

    if ($username) {
        // Already active in current session
        Session::$userSession = new UserSession($username);
        if (Session::getUser() !== null) {
            Session::$authStatus = \Constants::STATUS_LOGGEDIN;
        } else {
            UserSession::logout(); 
        }
    } elseif ($sessionToken) {
        // Attempt Token Auto-Login
        $db = DatabaseConnection::getDefaultDatabase();

        // SE1+SE2: Look up the user by the deterministic token_id (sha256 of the
        // bearer token) and enforce the 30-day expiry. This is a single indexed
        // lookup instead of scanning every user's tokens (perf + DoS hardening).
        $maxTokenAge = 30 * 24 * 3600; // 30 days
        $cutoffTime = time() - $maxTokenAge;
        $tokenId = hash('sha256', $sessionToken);

        $matchedUser = $db->users->findOne([
            'session_tokens' => ['$elemMatch' => [
                'token_id'   => $tokenId,
                'created_at' => ['$gte' => $cutoffTime],
            ]],
        ]);

        if ($matchedUser && isset($matchedUser['username'])) {
            // Locate the matching token entry and verify its bcrypt hash
            $matchedTokenData = null;
            foreach ($matchedUser['session_tokens'] as $tokenData) {
                if (($tokenData['token_id'] ?? '') === $tokenId) {
                    $matchedTokenData = $tokenData;
                    break;
                }
            }

            $storedHash = $matchedTokenData['token_hash'] ?? '';
            // Authenticate only on a positive bcrypt match. A missing/empty hash
            // provides no credential proof — a bare token_id match (sha256 is not a
            // secret) must not grant a session. Reject in both cases.
            if (!$storedHash || !password_verify($sessionToken, $storedHash)) {
                // Hash missing or token was tampered with
                UserSession::logout();
                return;
            }

            // Token is valid and not expired, rebuild session
            $_SESSION['username'] = $matchedUser['username'];
            $_SESSION['auth_status'] = \Constants::STATUS_LOGGEDIN;

            // Update last_activity for this token
            $db->users->updateOne(
                ['_id' => $matchedUser['_id'], 'session_tokens.token_id' => $tokenId],
                ['$set' => ['session_tokens.$.last_activity' => time()]]
            );

            Session::$userSession = new UserSession($matchedUser['username']);
            Session::$authStatus = \Constants::STATUS_LOGGEDIN;
        } else {
            // Token is invalid, expired, or revoked, forcefully log out
            UserSession::logout();
        }
    } else {
        Session::$authStatus = \Constants::STATUS_DEFAULT;
    }
}
}