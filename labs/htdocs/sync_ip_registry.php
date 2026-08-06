<?php
require_once __DIR__ . '/src/load.php';

// S4: Require superuser authentication
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$user = Session::getUser();
if (!$user || $user->getRole() !== 'superuser') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: superuser required']);
    exit;
}

// Log the action
error_log("ADMIN ACTION: sync_ip_registry.php called by {$user->getEmail()} ({$user->getUserId()})");

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$ipReg = $db->ip_registry;
$devices = $db->devices;

$vpnIPs = $ipReg->find(['service_type' => 'vpn_device']);
$cleaned = 0;

foreach ($vpnIPs as $ip) {
    $pubKey = $ip['resource_id'] ?? null;
    if (!$pubKey) continue;
    
    $device = $devices->findOne(['public_key' => $pubKey]);
    if (!$device) {
        $ipReg->updateOne(
            ['_id' => $ip['_id']],
            ['$set' => [
                'status' => 'available',
                'reserved_to' => null,
            ], '$unset' => [
                'allocated_to' => '',
                'email' => '',
                'service_type' => '',
                'resource_type' => '',
                'resource_id' => '',
                'label' => '',
                'device_name' => '',
                'device_type' => '',
            ]]
        );
        echo "Released orphan IP: " . $ip['ip_addr'] . "\n";
        $cleaned++;
    }
}

// Also clean orphan lab IPs
$labIPs = $ipReg->find(['resource_type' => 'lab']);
foreach ($labIPs as $ip) {
    $hash = $ip['allocated_to'] ?? null;
    if (!$hash) continue;
    
    $lab = $db->machine_labs->findOne(['instance_hash' => $hash]);
    if (!$lab) {
        $ipReg->updateOne(
            ['_id' => $ip['_id']],
            ['$set' => [
                'status' => 'available',
                'reserved_to' => null,
            ], '$unset' => [
                'allocated_to' => '',
                'email' => '',
                'service_type' => '',
                'resource_type' => '',
                'resource_id' => '',
                'label' => '',
            ]]
        );
        echo "Released orphan lab IP: " . $ip['ip_addr'] . "\n";
        $cleaned++;
    }
}

echo "\nCleaned $cleaned orphan IPs total\n";
