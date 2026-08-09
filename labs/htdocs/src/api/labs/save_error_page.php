<?php
/**
 * Save Custom Error Page
 * POST: hash, custom_error_page (HTML content)
 * GET: hash (returns current custom_error_page)
 */
require_once __DIR__ . '/../../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();
$hash = $_GET['hash'] ?? $_POST['hash'] ?? '';

if (empty($hash)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing hash']));
}

$inst = $db->machine_labs->findOne(['instance_hash' => $hash]);
if (!$inst || ($inst['user_id'] ?? '') !== $user->getUserId()) {
    http_response_code(404);
    exit(json_encode(['error' => 'Lab not found']));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'custom_error_page' => $inst['custom_error_page'] ?? ''
    ]);
    exit;
}

// POST: Save
$content = $_POST['custom_error_page'] ?? '';

if (strlen($content) > 65536) {
    http_response_code(400);
    exit(json_encode(['error' => 'Content exceeds 64 KB limit']));
}

$db->machine_labs->updateOne(
    ['instance_hash' => $hash],
    ['$set' => ['custom_error_page' => $content]]
);

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
