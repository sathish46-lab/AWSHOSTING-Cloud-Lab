<?php
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/AuditLog.class.php';
require_once __DIR__ . '/../../lib/core/RabbitClient.class.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$slug = trim($input['slug'] ?? $_POST['slug'] ?? '');

if (empty($slug)) {
    http_response_code(400);
    echo 'Missing slug';
    exit;
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instance = $db->instances->findOne(['instance_hash' => $slug]);
if (!$instance) {
    $instance = $db->instances->findOne(['slug' => $slug]);
}

if (!$instance || (int)($instance['user_id'] ?? 0) !== $userId) {
    http_response_code(404);
    echo 'Instance not found or forbidden';
    exit;
}

$deploy = $instance['deploy'] ?? [];
$deployStatus = $deploy['status'] ?? 'none';
$instanceHash = $instance['instance_hash'] ?? '';

if (in_array($deployStatus, ['running', 'deploying', 'starting'])) {
    $containerName = escapeshellarg($instanceHash);
    @shell_exec("docker stop {$containerName} 2>/dev/null");
    @shell_exec("docker rm -f {$containerName} 2>/dev/null");
}

$now = new MongoDB\BSON\UTCDateTime();
$instance['trashed_at'] = $now;
$instance['trashed_by'] = $username;
$instance['status'] = 'stopped';
$instance['updated_at'] = $now;
$instance['updated_by'] = $username;
$instance['deploy']['status'] = 'stopped';

// Compensating transaction: insert to trash first, then delete from instances.
// If delete fails, roll back the trash insert.
$insertResult = $db->instance_trash->insertOne($instance);

if ($insertResult->getInsertedCount() > 0) {
    $deleteResult = $db->instances->deleteOne(['_id' => $instance['_id']]);

    if ($deleteResult->getDeletedCount() > 0) {
        AuditLog::log('trash', 'instance', $instanceHash ?: (string)$instance['_id'], [
            'name' => $instance['name'] ?? '',
        ]);
        echo json_encode(['status' => 'success']);
    } else {
        // Rollback: remove from trash since delete from instances failed
        $db->instance_trash->deleteOne(['_id' => $insertResult->getInsertedId()]);
        http_response_code(500);
        echo 'Failed to trash instance';
    }
} else {
    http_response_code(500);
    echo 'Failed to trash instance';
}
