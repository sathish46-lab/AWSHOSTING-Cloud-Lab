<?php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../../src/lib/core/VPN.class.php';
require_once __DIR__ . '/../../../src/lib/labs/IPManager.class.php';

header('Content-Type: application/json');
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ipAddr = $data['ip'] ?? null;
$type = $data['type'] ?? 'vpn';
$user = Session::getUser();

$ipManager = new \TomLabs\Labs\IPManager();

if ($type === 'essential_lab') {
    // Release lab IP
    $ipManager->release($ipAddr, $user->getEmail());
    
    // Clean up machine_labs
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $db->machine_labs->deleteMany([
        'deploy.internal_ip' => $ipAddr, 
        'deploy.email' => $user->getEmail()
    ]);

    echo json_encode(['success' => true]);
} else {
    // VPN device release
    $db = DatabaseConnection::getDefaultDatabase();
    
    // Check if IP is used by a device
    $deviceExists = $db->devices->findOne(['assigned_ip' => $ipAddr, 'user_id' => $user->getUserId()]);
    if ($deviceExists) {
        echo json_encode(['success' => false, 'error' => 'Delete the device first.']);
        exit;
    }

    // Release from WireGuard kernel
    $response = VPN::request('ip', 'unreserve', ['ip' => $ipAddr, 'email' => $user->getEmail()]);
    
    if (!$response || empty($response['result'])) {
        $response = VPN::request('ip', 'unreserve', ['ip' => $ipAddr, 'email' => $user->getUsername()]);
    }

    if ($response && $response['result']) {
        $ipManager->release($ipAddr, $user->getEmail());
        $db->devices->deleteMany(['assigned_ip' => $ipAddr, 'user_id' => $user->getUserId()]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to release VPN IP']);
    }
}
