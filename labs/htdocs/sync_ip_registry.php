<?php
require_once __DIR__ . '/src/load.php';

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
                'allocated' => false,
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
    
    $lab = $db->machine_labs->findOne(['deploy.instance_hash' => $hash]);
    if (!$lab) {
        $ipReg->updateOne(
            ['_id' => $ip['_id']],
            ['$set' => [
                'status' => 'available',
                'allocated' => false,
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
