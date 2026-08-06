<?php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../../src/lib/core/VPN.class.php';
require_once __DIR__ . '/../../../src/lib/core/AuditLog.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$dbId = $data['id'] ?? null;
$pubKey = $data['public_key'] ?? null;

if (!$dbId || !$pubKey) {
    echo json_encode(['status' => 'error', 'error' => 'Missing ID or Public Key']); exit;
}

$db = DatabaseConnection::getDefaultDatabase();
$user = Session::getUser();

try {
    // 1. Find device to get its IP
    $device = $db->devices->findOne([
        '_id' => new MongoDB\BSON\ObjectId($dbId),
        'user_id' => $user->getUserId()
    ]);
    
    if (!$device) {
        throw new Exception("Device not found.");
    }
    
    $assignedIp = $device['assigned_ip'] ?? null;

    // 2. Remove from WireGuard Kernel
    $response = VPN::request('wg', 'remove_peer', [
        'peer'     => $pubKey, 
        'reserved' => 'true',
        'device'   => 'wg0'
    ]);

    // 3. Soft-delete from devices collection (keep record for audit trail)
    $db->devices->updateOne([
        '_id' => new MongoDB\BSON\ObjectId($dbId),
        'user_id' => $user->getUserId()
    ], [
        '$set' => [
            'status' => 'deleted',
            'deleted_at' => new MongoDB\BSON\UTCDateTime(),
            'deleted_by' => $user->getEmail(),
        ]
    ]);

    AuditLog::log('delete', 'vpn_device', $assignedIp, [
        'device_name' => $device['device_name'] ?? '',
    ]);

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
