<?php

class UserSession {
    private $database = null;
    private $usersCollection = null;
    public $user = null;

    private function __init() {
        $this->database = DatabaseConnection::getDefaultDatabase();
        $this->usersCollection = $this->database->users;
    }


public function __construct($username = null, $sessionHash = null) {
    $this->__init();
    
    if ($username !== null) {
        // Find the email for the user
        $userDoc = $this->usersCollection->findOne([
            '$or' => [['username' => $username], ['email' => $username]]
        ]);
        
        if ($userDoc) {
            // Pass the email to the User constructor as per your screenshot
            $this->user = new User($userDoc['email']);
            
            // Now you can call ANYTHING!
            $log = "User: " . $this->user->getUsername() . "\n";
            $log .= "Bio: " . $this->user->getBio() . "\n"; 
            $log .= "Avatar: " . $this->user->getAvatarUrl();

            // Console::log(indent($log, 8)); 
        }
    }
}

/**
 * Required for Session::getUser()
 */
public function getUser() {
    return $this->user;
}

    /**
     * Authenticate local users and set recovery cookies.
     */
    public static function authenticate($email, $password, $remember = false) {
    $instance = new self();
    try {
        $user = $instance->usersCollection->findOne(['email' => $email]);

        // S8: Account lockout check
        if ($user) {
            $failedAttempts = $user['failed_login_attempts'] ?? 0;
            $lockedUntil = $user['locked_until'] ?? 0;
            
            if ($lockedUntil > time()) {
                $remainingMinutes = ceil(($lockedUntil - time()) / 60);
                Session::set('login_error', "Account locked. Try again in {$remainingMinutes} minutes.");
                return false;
            }
            
            // Clear lockout if expired
            if ($lockedUntil > 0 && $lockedUntil <= time()) {
                $instance->usersCollection->updateOne(
                    ['email' => $email],
                    ['$set' => ['failed_login_attempts' => 0, 'locked_until' => 0]]
                );
            }
        }

        if ($user && isset($user['password']) && password_verify($password, $user['password'])) {
            // S8: Reset failed login attempts on successful password verification
            $instance->usersCollection->updateOne(
                ['email' => $email],
                ['$set' => ['failed_login_attempts' => 0, 'locked_until' => 0]]
            );

            if (!isset($user['is_verified']) || $user['is_verified'] === false) {
                Session::set('login_error', "Please verify your email.");
                return false;
            }
            
            // 2FA CHECK INTERCEPT
            if (isset($user['two_factor_enabled']) && $user['two_factor_enabled'] === true) {
                $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = time() + 60;
                $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                
                $instance->usersCollection->updateOne(
                    ['email' => $email],
                    ['$set' => [
                        'two_factor_otp' => $otpHash,
                        'two_factor_expires' => $expires
                    ]]
                );
                
                $username = $user['username'] ?? 'User';
                \Auth\Mailer::send2faOtp($email, $username, $otp, 'login');
                
                $_SESSION['2fa_pending_email'] = $email;
                return "2fa_required";
            }
            
            // Standard Login Logic
            $username = $user['username']; 
            
            // SECURITY: Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            $_SESSION['auth_status'] = \Constants::STATUS_LOGGEDIN;
            $_SESSION['username']    = $username;
            
            // Set cookies with environment-aware expiration from session.json
            $lifetime = get_session_lifetime();
            $domain = get_session_domain();

            $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            
            // GENERATE SECURE SESSION TOKEN
            $sessionToken = bin2hex(random_bytes(32));
            $tokenHash = password_hash($sessionToken, PASSWORD_DEFAULT);
            $deviceInfo = parse_user_agent();
            $clientIp = get_client_ip();
            
            // STORE HASHED TOKEN + DEVICE INFO IN DATABASE (Supports multi-device login)
            $instance->usersCollection->updateOne(
                ['email' => $email],
                [
                    '$push' => ['session_tokens' => [
                        'token_hash' => $tokenHash,
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

            // SET THE NEW SECURE TOKEN COOKIE
            // "Remember me" => persistent cookie; otherwise a session cookie
            // (expires 0) wiped when the browser closes, requiring sign-in again.
            setcookie('session_token', $sessionToken, [
                'expires'  => $remember ? time() + $lifetime : 0,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            
            return true;
        }
        
        // S8: Track failed login attempt
        if ($user) {
            $newAttempts = ($user['failed_login_attempts'] ?? 0) + 1;
            $updateOps = ['$set' => ['failed_login_attempts' => $newAttempts]];
            
            // Lock account after 5 failed attempts (lock for 15 minutes)
            if ($newAttempts >= 5) {
                $updateOps['$set']['locked_until'] = time() + 900;
                error_log("Account locked for {$email} after {$newAttempts} failed login attempts");
            }
            
            $instance->usersCollection->updateOne(['email' => $email], $updateOps);
        }
        
    } catch (Exception $e) {
        error_log("Auth Error: " . $e->getMessage());
    }
    return false;
}
    public static function logout() {
        Session::$authStatus = null;
        
        // Dynamic domain for clearing cookies from session.json
        $domain = get_session_domain();

        // READ TOKEN BEFORE CLEARING — fix token leak
        $sessionToken = $_COOKIE['session_token'] ?? null;

        $_SESSION = [];
        unset($_COOKIE['session_token']);
        unset($_COOKIE['username']);
        unset($_COOKIE['sessionHash']);
        unset($_COOKIE['sessionID']);

        // FORCE DELETE TOKEN FROM DATABASE IF PRESENT
        if ($sessionToken) {
            try {
                $instance = new self();
                // Remove the matching token via its deterministic token_id (indexed lookup)
                $tokenId = hash('sha256', $sessionToken);
                $instance->usersCollection->updateMany(
                    ['session_tokens.token_id' => $tokenId],
                    ['$pull' => ['session_tokens' => ['token_id' => $tokenId]]]
                );
            } catch (Exception $e) {
                error_log("Logout Token Clear Error: " . $e->getMessage());
            }
        }

        // DYNAMIC COOKIE SWEEPER (Obliterates old broken cookies)
        $past = time() - 3600;
        $domainsToClear = [$domain, '', $_SERVER['HTTP_HOST'] ?? ''];
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        foreach ($domainsToClear as $d) {
            $cookieOptions = [
                'expires' => $past,
                'path' => '/',
                'domain' => $d,
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            setcookie('username', '', $cookieOptions);
            setcookie('session_token', '', $cookieOptions);
            setcookie('sessionHash', '', $cookieOptions);
            setcookie('sessionID', '', $cookieOptions);
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}