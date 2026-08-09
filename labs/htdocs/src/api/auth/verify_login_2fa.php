<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

$email = $_SESSION['2fa_pending_email'] ?? null;
if (!$email) {
    echo json_encode(['status' => 'error', 'error' => 'No pending 2FA login session found.']); exit;
}

$submittedOtp = trim($_POST['otp'] ?? '');

if (empty($submittedOtp) || strlen($submittedOtp) !== 6) {
    echo json_encode(['status' => 'error', 'error' => 'Invalid OTP format.']); exit;
}

$db = DatabaseConnection::getDefaultDatabase();
$userDoc = $db->users->findOne(['email' => $email]);

if (!$userDoc || !isset($userDoc['two_factor_otp']) || !isset($userDoc['two_factor_expires'])) {
    echo json_encode(['status' => 'error', 'error' => 'No active 2FA request found.']); exit;
}

// Strictly check 1-minute expiration
if (time() > $userDoc['two_factor_expires']) {
    echo json_encode(['status' => 'error', 'error' => 'OTP has expired.']); exit;
}

// Verify OTP match
if (!password_verify($submittedOtp, $userDoc['two_factor_otp'])) {
    echo json_encode(['status' => 'error', 'error' => 'Incorrect OTP code.']); exit;
}

// Success! Complete the login
try {
    $db->users->updateOne(
        ['email' => $email],
        [
            '$unset' => [
                'two_factor_otp' => '',
                'two_factor_expires' => ''
            ],
            '$set' => [
                'last_login' => time()
            ]
        ]
    );

    $username = $userDoc['username']; 
    session_regenerate_id(true);
    $_SESSION['auth_status'] = \Constants::STATUS_LOGGEDIN;
    $_SESSION['username']    = $username;
    
    // Clear pending email
    unset($_SESSION['2fa_pending_email']);
    
    // Generate and store session token with device info
    $sessionToken = bin2hex(random_bytes(32));
    $deviceInfo = parse_user_agent();
    $clientIp = get_client_ip();
    
    $db->users->updateOne(
        ['email' => $email],
        [
            '$push' => ['session_tokens' => [
                'token' => $sessionToken,
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
    
    // Set cookies with environment-aware expiration from session.json
    $lifetime = get_session_lifetime();
    $domain = get_session_domain();

    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('session_token', $sessionToken, [
        'expires'  => time() + $lifetime,
        'path'     => '/',
        'domain'   => $domain, 
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    setcookie('username', $username, [
        'expires'  => time() + $lifetime,
        'path'     => '/',
        'domain'   => $domain, 
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => 'Failed to establish session.']);
}
