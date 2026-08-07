<?php
/**
 * Update Profile API — Updates first/last name for authenticated user.
 * 
 * Security:
 * - CSRF token required (X-CSRF-Token header or _csrf_token POST field)
 * - Input sanitized: trimmed, length-limited (50 chars max), stripped of control chars
 * - User scoping: updates only the authenticated user's record (email from session)
 * - No user_id accepted from request params
 */
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/CsrfProtection.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

CsrfProtection::require();

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');

// Sanitize: strip control chars, limit length
$firstName = preg_replace('/[\x00-\x1F\x7F]/u', '', $firstName);
$lastName = preg_replace('/[\x00-\x1F\x7F]/u', '', $lastName);

// Length limits
$firstName = mb_substr($firstName, 0, 50);
$lastName = mb_substr($lastName, 0, 50);

// Validate: at least one field provided
if (empty($firstName) && empty($lastName)) {
    echo json_encode(['status' => 'error', 'error' => 'First name or last name is required.']);
    exit;
}

try {
    // Update ONLY the authenticated user's record — never accept user_id from params
    $db->users->updateOne(
        ['email' => $user->getEmail()],
        ['$set' => [
            'first_name' => $firstName,
            'last_name' => $lastName
        ]]
    );

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    error_log("Profile update error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'error' => 'Database update failed.']);
}
