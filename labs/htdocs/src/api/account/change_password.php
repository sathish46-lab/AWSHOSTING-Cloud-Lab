<?php
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/AuditLog.class.php';
require_once __DIR__ . '/../../lib/core/CsrfProtection.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

CsrfProtection::require();

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

$data = json_decode(file_get_contents('php://input'), true);
$currentPassword = $data['current_password'] ?? '';
$newPassword = $data['new_password'] ?? '';

if (empty($currentPassword) || empty($newPassword)) {
    echo json_encode(['status' => 'error', 'error' => 'Current and new password are required.']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['status' => 'error', 'error' => 'New password must be at least 8 characters.']);
    exit;
}

try {
    // Find user document
    $userRecord = $db->users->findOne(['email' => $user->getEmail()]);

    if (!$userRecord) {
        echo json_encode(['status' => 'error', 'error' => 'User not found.']);
        exit;
    }

    // Verify current password
    $currentHash = $userRecord['password'] ?? '';
    if (!password_verify($currentPassword, $currentHash)) {
        echo json_encode(['status' => 'error', 'error' => 'Current password is incorrect.']);
        exit;
    }

    // Hash new password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update password and invalidate ALL other sessions (keep only current token)
    $sessionToken = $_COOKIE['session_token'] ?? null;
    $currentTokenHash = null;
    $currentTokenId = null;

    if ($sessionToken) {
        $tokens = $userRecord['session_tokens'] ?? [];
        foreach ($tokens as $tokenData) {
            $storedHash = $tokenData['token_hash'] ?? '';
            if (password_verify($sessionToken, $storedHash)) {
                $currentTokenHash = $storedHash;
                $currentTokenId   = $tokenData['token_id'] ?? hash('sha256', $sessionToken);
                break;
            }
        }
    }

    $updateOps = [
        '$set' => [
            'password' => $newHash,
            'password_changed_at' => time(),
        ]
    ];

    if ($currentTokenHash) {
        // Keep only the current session, remove all others
        $updateOps['$set']['session_tokens'] = [
            [
                'token_hash' => $currentTokenHash,
                'token_id'   => $currentTokenId,
                'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
                'browser' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => time(),
                'last_activity' => time(),
            ]
        ];
    } else {
        // Can't identify current token, clear all sessions (user must re-login everywhere)
        $updateOps['$set']['session_tokens'] = [];
    }

    $db->users->updateOne(
        ['_id' => $userRecord['_id']],
        $updateOps
    );

    AuditLog::log('change_password', 'user', (string)$userRecord['_id'], [
        'sessions_invalidated' => $currentTokenHash ? 'other_sessions' : 'all_sessions',
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Password changed successfully.']);

} catch (Exception $e) {
    error_log("Password change error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'error' => 'Failed to change password.']);
}
