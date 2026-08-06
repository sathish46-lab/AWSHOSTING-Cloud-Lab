<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$keyId = $data['id'] ?? null;
$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

if ($keyId) {
    // 1. Soft-delete from MongoDB (keep record for audit trail)
    $db->ssh_keys->updateOne([
        '_id' => new MongoDB\BSON\ObjectId($keyId),
        'user_id' => $user->getUserId()
    ], [
        '$set' => [
            'status' => 'deleted',
            'deleted_at' => new MongoDB\BSON\UTCDateTime(),
            'deleted_by' => $user->getEmail(),
        ]
    ]);

    // 2. Re-Sync Container: This wipes the deleted key from the container
    shell_exec("sudo labsctl syncuser " . escapeshellarg($user->getUsername()));

    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'error' => 'Invalid ID']);
}
