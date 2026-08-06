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
        if (!extension_loaded('mongodb')) { die("Unable to load mongodb.so"); }

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
        
        // SE1: Find user with matching hashed token + SE2: enforce 30-day expiry
        $maxTokenAge = 30 * 24 * 3600; // 30 days
        $cutoffTime = time() - $maxTokenAge;
        
        $usersWithTokens = $db->users->find([
            'session_tokens' => ['$exists' => true, '$ne' => []]
        ]);
        
        $matchedUser = null;
        $matchedTokenData = null;
        
        foreach ($usersWithTokens as $user) {
            $tokens = $user['session_tokens'] ?? [];
            foreach ($tokens as $tokenData) {
                $storedHash = $tokenData['token_hash'] ?? $tokenData['token'] ?? '';
                $createdAt = $tokenData['created_at'] ?? 0;
                
                // Skip expired tokens
                if ($createdAt < $cutoffTime) {
                    continue;
                }
                
                if (password_verify($sessionToken, $storedHash)) {
                    $matchedUser = $user;
                    $matchedTokenData = $tokenData;
                    break 2;
                }
            }
        }
        
        if ($matchedUser && isset($matchedUser['username'])) {
            // Token is valid and not expired, rebuild session
            $_SESSION['username'] = $matchedUser['username'];
            $_SESSION['auth_status'] = \Constants::STATUS_LOGGEDIN;
            
            // Update last_activity for this token
            $storedHash = $matchedTokenData['token_hash'] ?? $matchedTokenData['token'] ?? '';
            $db->users->updateOne(
                ['_id' => $matchedUser['_id'], 'session_tokens.token_hash' => $storedHash],
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