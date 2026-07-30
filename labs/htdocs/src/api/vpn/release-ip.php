<?php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../../src/lib/labs/IPManager.class.php';

header('Content-Type: application/json');
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ipAddr = $data['ip'] ?? null;
$type = $data['type'] ?? 'vpn';
$user = Session::getUser();
if ($type === 'essential_lab') {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

    // Check if Lab IP is still allocated
    $labIp = $db->lab_ips->findOne(['ip_addr' => $ipAddr, 'email' => $user->getEmail(), 'status' => 'allocated']);
    if ($labIp) {
        echo json_encode(['success' => false, 'error' => 'This IP is still allocated to an active lab. Delete the lab first.']);
        exit;
    }

    $ipManager = new \TomLabs\Labs\IPManager();

    // 1. Wipe the IP Inventory Record (Ownership Wipe)
    $ipManager->release($ipAddr, $user->getEmail());
    
    // 2. Remove the Lab Metadata entirely so it disappears from Dashboard
    $db->machine_labs->deleteMany([
        'deploy.internal_ip' => $ipAddr, 
        'deploy.email' => $user->getEmail()
    ]);

    echo json_encode(['success' => true]);
} else {
    // Standard VPN release logic
    $db = DatabaseConnection::getDefaultDatabase();
    
    // Check if IP is currently used by a VPN device
    $deviceExists = $db->devices->findOne(['assigned_ip' => $ipAddr, 'user_id' => $user->getUserId()]);
    if ($deviceExists) {
        echo json_encode(['success' => false, 'error' => 'This IP is attached to device "' . $deviceExists['device_name'] . '". Delete the device first.']);
        exit;
    }

    // 1. Try with email
    $response = VPN::request('ip', 'unreserve', ['ip' => $ipAddr, 'email' => $user->getEmail()]);
    
    // 2. If email failed, try with username (fallback for legacy records)
    if (!$response || empty($response['result'])) {
        error_log("VPN release failed with email for $ipAddr. Retrying with username: " . $user->getUsername());
        $response = VPN::request('ip', 'unreserve', ['ip' => $ipAddr, 'email' => $user->getUsername()]);
    }

    if ($response && $response['result']) {
        $db = DatabaseConnection::getDefaultDatabase();
        $db->devices->deleteMany(['assigned_ip' => $ipAddr, 'user_id' => $user->getUserId()]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to release VPN IP']);
    }
}
