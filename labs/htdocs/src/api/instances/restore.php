<?php
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/AuditLog.class.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$slug = trim($input['slug'] ?? $_POST['slug'] ?? '');

if (empty($slug)) {
    http_response_code(400);
    echo 'Missing slug';
    exit;
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$trashed = $db->instance_trash->findOne(['instance_hash' => $slug]);
if (!$trashed) {
    $trashed = $db->instance_trash->findOne(['slug' => $slug]);
}

if (!$trashed || (int)($trashed['user_id'] ?? 0) !== $userId) {
    http_response_code(404);
    echo 'Trashed instance not found or forbidden';
    exit;
}

unset($trashed['trashed_at']);
unset($trashed['trashed_by']);

$result = $db->instances->insertOne($trashed);

if ($result->getInsertedCount() > 0) {
    $db->instance_trash->deleteOne(['_id' => $trashed['_id']]);
    AuditLog::log('restore', 'instance', $trashed['instance_hash'] ?? (string)$trashed['_id'], [
        'name' => $trashed['name'] ?? '',
    ]);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(500);
    echo 'Failed to restore instance';
}
