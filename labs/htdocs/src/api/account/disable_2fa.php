<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

$email = $user->getEmail();
$submittedOtp = trim($_POST['otp'] ?? '');

if (empty($submittedOtp) || strlen($submittedOtp) !== 6) {
    echo json_encode(['status' => 'error', 'error' => 'Invalid OTP format.']); exit;
}

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

// Success! Disable 2FA
try {
    $db->users->updateOne(
        ['email' => $email],
        ['$set' => [
            'two_factor_enabled' => false
        ], '$unset' => [
            'two_factor_otp' => '',
            'two_factor_expires' => ''
        ]]
    );

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => 'Failed to disable 2FA.']);
}
