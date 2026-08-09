<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

$email = $user->getEmail();
$username = $user->getUsername() ?? 'User';

// Generate 6-digit OTP
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = time() + 60; // strictly 60 seconds
$context = $_GET['action'] ?? 'enable';

try {
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $db->users->updateOne(
        ['email' => $email],
        ['$set' => [
            'two_factor_otp' => $otpHash,
            'two_factor_expires' => $expires
        ]]
    );

    // Send Email
    $sent = \Auth\Mailer::send2faOtp($email, $username, $otp, $context);
    
    if ($sent) {
        echo json_encode(['status' => 'success']);
    } else {
        // Even if email fails locally due to SMTP config, we shouldn't block local dev if we just want to test logic.
        // But for security, we error out if mail fails.
        echo json_encode(['status' => 'error', 'error' => 'Failed to send OTP email. Check SMTP settings.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => 'Database error.']);
}
