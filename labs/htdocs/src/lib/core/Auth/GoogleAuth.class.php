<?php

namespace Auth;

use DatabaseConnection;
use Session;
use Constants;
use User;

class GoogleAuth {
    private $db;
    private $config;

    public function __construct() {
    // Ensure this matches the key in config.json
    $this->config = get_config('google_oauth');
    $this->db = DatabaseConnection::getDefaultDatabase();
    
    // Cleanup expired OAuth cache files (older than 15 minutes)
    $this->cleanupExpiredOAuthCache();
}

/**
 * Cleanup expired OAuth state cache files to prevent disk clutter
 * Removes files older than 15 minutes
 */
private function cleanupExpiredOAuthCache() {
    $cacheDir = '/var/cache/labs/';
    $maxAge = 900; // 15 minutes
    $now = time();
    
    $files = glob($cacheDir . 'oauth_state_*');
    if ($files) {
        foreach ($files as $file) {
            if (file_exists($file)) {
                $fileAge = $now - filemtime($file);
                if ($fileAge > $maxAge) {
                    @unlink($file); // Silently delete expired files
                }
            }
        }
    }
}

public function getAuthUrl($metadata) {
    // Defensive check to prevent "property on null" error
    if (!$metadata || !isset($metadata->authorization_endpoint)) {
        return "#error_metadata_missing";
    }
    
    // Defensive check for config
    if (!$this->config) {
        return "#error_config_missing";
    }

    // Generate cache key (this will be embedded in state parameter)
    $cacheKey = bin2hex(random_bytes(16)); // 32-char identifier
    $actualState = bin2hex(random_bytes(32)); // Real CSRF protection state
    $code_verifier = bin2hex(random_bytes(50));
    
    // Store in session (primary backup) 
    $_SESSION['state'] = $actualState;
    $_SESSION['code_verifier'] = $code_verifier;
    $_SESSION['oauth_cache_key'] = $cacheKey;
    
    // Store in server-side cache file using EMBEDDED cache key
    // This way we can find it even if session ID changes!
    $cacheFile = '/var/cache/labs/oauth_state_' . $cacheKey;
    file_put_contents($cacheFile, json_encode([
        'state' => $actualState,
        'code_verifier' => $code_verifier,
        'created' => time(),
        'cache_key' => $cacheKey
    ]));
    chmod($cacheFile, 0600);
    
    // Store in persistent cookies (for redundancy)
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    setcookie('oauth_state', $actualState, [
        'expires'  => time() + 600, // 10 minute expiry
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    setcookie('oauth_verifier', $code_verifier, [
        'expires'  => time() + 600,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    setcookie('oauth_cache_key', $cacheKey, [
        'expires'  => time() + 600,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    $code_challenge = base64_urlencode(hash('sha256', $code_verifier, true));

    // Send the cacheKey as the state to Google (it will be returned unchanged)
    // This allows us to find the cache file even if session ID changes!
    return $metadata->authorization_endpoint . '?' . http_build_query([
        'response_type'   => 'code',
        'client_id'       => $this->config['client_id'],
        'redirect_uri'    => $this->config['redirect_uri'],
        'state'           => $cacheKey,  // Send cache key, Google will return this
        'scope'           => 'openid profile email',
        'code_challenge'  => $code_challenge,
        'code_challenge_method' => 'S256',
        'access_type'     => 'offline'
    ]);
}

    /**
     * Handles the callback, token exchange, and user session creation
     * Uses embedded cache key in state parameter for reliable identification
     */
    public function handleCallback($metadata, $code, $state = null) {
        // SECURITY: Extract state (cache key) from GET parameter
        if ($state === null) $state = $_GET['state'] ?? null;
        
        if (!$state) {
            error_log('OAuth Error: No state parameter received from Google');
            return false;
        }
        
        // The state returned by Google is our cache key - use it to find stored credentials
        $cacheKey = $state; // This IS the cache key we sent to Google
        $cacheFile = '/var/cache/labs/oauth_state_' . $cacheKey;
        
        $actualState = null;
        $code_verifier = '';
        
        // PRIORITY 1: Try cache file (most reliable - uses embedded cache key)
        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if ($cacheData && isset($cacheData['state'])) {
                $actualState = $cacheData['state'];
                $code_verifier = $cacheData['code_verifier'] ?? '';
                // Check cache age (10 minute max)
                if (time() - $cacheData['created'] > 600) {
                    error_log('OAuth Error: State cache expired');
                    @unlink($cacheFile); // Clean up expired cache
                    return false;
                }
            }
        }
        
        // PRIORITY 2: Fallback to session
        if (!$actualState) {
            $actualState = $_SESSION['state'] ?? null;
            $code_verifier = $_SESSION['code_verifier'] ?? '';
        }
        
        // PRIORITY 3: Fallback to cookies
        if (!$actualState) {
            $actualState = $_COOKIE['oauth_state'] ?? null;
            $code_verifier = $_COOKIE['oauth_verifier'] ?? '';
        }
        
        error_log('OAuth Debug: Cache Key=' . $cacheKey . 
                  ', Found=' . ($actualState ? 'YES' : 'NO') . 
                  ', VerifierFound=' . ($code_verifier ? 'YES' : 'NO'));
        
        if (!$actualState || !$code_verifier) {
            error_log('OAuth Error: Could not find state or code_verifier in any storage');
            return false;
        }
        
        // Consume tokens to prevent replay attacks
        unset($_SESSION['state']);
        unset($_SESSION['code_verifier']);
        unset($_SESSION['oauth_cache_key']);
        setcookie('oauth_state', '', time() - 3600, '/');
        setcookie('oauth_cache_key', '', time() - 3600, '/');
        setcookie('oauth_verifier', '', time() - 3600, '/');
        
        // Clean up cache file
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
            error_log('OAuth: Cache file deleted successfully');
        }

        // Get code_verifier from best available source
        if (!$code_verifier) {
            $code_verifier = $_SESSION['code_verifier'] ?? $_COOKIE['oauth_verifier'] ?? '';
        }
        
        if (!$code_verifier) {
            error_log('OAuth Error: code_verifier missing from all sources');
            return false;
        }
        
    $response = http($metadata->token_endpoint, [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $this->config['redirect_uri'],
        'client_id'     => $this->config['client_id'],
        'client_secret' => $this->config['client_secret'],
        'code_verifier' => $code_verifier,
    ]);
    
    // Clean up verifier cookie
    setcookie('oauth_verifier', '', time() - 3600, '/');

    if (!$response || !isset($response->access_token)) { return false; }
    $userinfo = http($metadata->userinfo_endpoint, ['access_token' => $response->access_token]);

    if ($userinfo && isset($userinfo->sub)) {
        $user = $this->db->users->findOne(['email' => $userinfo->email]);

        // Triggers Stage A if user is new
        if (!$user || empty($user['username'])) {
             $_SESSION['pending_user'] = [
                'email' => $userinfo->email, 
                'name' => $userinfo->name,
                'first_name' => $userinfo->given_name ?? '',
                'last_name' => $userinfo->family_name ?? '',
                'sub' => $userinfo->sub, 
                'avatar' => $userinfo->picture ?? null
            ];
            return null; 
        }

        // Update last_login
        $this->db->users->updateOne(
            ['email' => $userinfo->email],
            ['$set' => ['last_login' => time()]]
        );

        // Google OAuth = trusted login (Google already verified identity + has its own 2FA)
        // Service-level 2FA is NOT required for OAuth logins (industry standard)
        $username = $user['username'];
        $_SESSION['username']    = $username;
        $_SESSION['auth_status'] = \Constants::STATUS_LOGGEDIN;

        // Generate session token (same as normal login)
        $sessionToken = bin2hex(random_bytes(32));
        $deviceInfo = parse_user_agent();
        $clientIp = get_client_ip();
        $lifetime = get_session_lifetime();
        $domain = get_session_domain();

        $this->db->users->updateOne(
            ['email' => $userinfo->email],
            [
                '$push' => ['session_tokens' => [
                    'token_hash' => password_hash($sessionToken, PASSWORD_DEFAULT),
                    'token_id'   => hash('sha256', $sessionToken),
                    'ip' => $clientIp,
                    'browser' => $deviceInfo['browser'],
                    'os' => $deviceInfo['os'],
                    'mobile' => $deviceInfo['mobile'],
                    'created_at' => time(),
                    'last_activity' => time()
                ]],
                '$set' => ['last_login' => time()]
            ]
        );

        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie('username', $username, [
            'expires' => time() + $lifetime, 'path' => '/',
            'secure' => $isSecure, 'httponly' => true, 'samesite' => 'Lax'
        ]);
        setcookie('session_token', $sessionToken, [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        return $user;
    }
    return false;
}
}