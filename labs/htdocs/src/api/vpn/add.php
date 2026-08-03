<?php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../../src/lib/core/VPN.class.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

$publicKey = $_POST['public_key'] ?? null;
$privateKey = $_POST['private_key'] ?? '';
$deviceName = $_POST['device_name'] ?? 'Unnamed Device';
$deviceType = $_POST['device_type'] ?? 'Mobile';
$selectedIp = $_POST['ip'] ?? null;

if (empty($publicKey)) {
    http_response_code(400);
    echo 'Public Key is required.';
    exit;
}

// 1. Provision the peer in the WireGuard Kernel
$response = VPN::request('wg', 'add_peer', [
    'public_key' => $publicKey,
    'email'      => $user->getEmail(),
    'ip'         => $selectedIp,
    'reserved'   => 'true', 
    'device'     => 'wg0'
]);

if (isset($response['result']) && $response['result'] !== false) {
    $assignedIp = $response['result'];
    
    // 2. Reserve IP in unified ip_registry
    $db->ip_registry->updateOne(
        ['ip_addr' => $assignedIp],
        ['$set' => [
            'status'      => 'reserved',
            'user_id'     => $user->getUserId(),
            'email'       => $user->getEmail(),
            'reserved_at' => time(),
        ]],
        ['upsert' => true]
    );
    
    // 3. Store device metadata
    $db->devices->updateOne(
        ['user_id' => $user->getUserId(), 'assigned_ip' => $assignedIp],
        ['$set' => [
            'email'       => $user->getEmail(),
            'device_name' => $deviceName,
            'device_type' => $deviceType,
            'public_key'  => $publicKey,
            'private_key' => $privateKey,
            'assigned_ip' => $assignedIp,
            'created_at'  => time(),
            'is_reserved' => true
        ]],
        ['upsert' => true]
    );

    // 4. Render the HTML card
    ob_start();
    $device = [
        '_id' => (string)($user->getUserId()),
        'public_key' => $publicKey,
        'device_name' => $deviceName,
        'device_type' => $deviceType,
        'status' => 'offline',
        'assigned_ip' => $assignedIp,
        'origin_ip' => 'N/A',
        'rx' => '0 B',
        'tx' => '0 B'
    ];
    $insertedDevice = $db->devices->findOne(['user_id' => $user->getUserId(), 'public_key' => $publicKey]);
    if ($insertedDevice) {
        $device = $insertedDevice;
    }
    include __DIR__ . '/../../template/partials/_device_card.php';
    $html = ob_get_clean();

    echo $html;
} else {
    http_response_code(400);
    echo $response['error'] ?? 'Kernel Error';
}
