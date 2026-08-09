<?php
require_once __DIR__ . '/../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    Session::$pageTitle = "Network";
    Session::loadMaster();
    exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');

// 1. Get all user-reserved IPs
$myIPs = $db->ip_registry->find(['email' => $user->getEmail(), 'status' => 'reserved'])->toArray();

$allResources = [];
foreach ($myIPs as $ip) {
    $allResources[] = [
        'ip_addr' => $ip['ip_addr'],
        'iface'   => 'wg0',
        'tag'     => '',
        'tag_bg'  => '',
    ];
}

// 2. Find what's actually using each IP
// Machine labs (flat structure)
$labs = $db->machine_labs->find(['email' => $user->getEmail()])->toArray();
foreach ($labs as $lab) {
    $ip = $lab['internal_ip'] ?? null;
    if (!$ip) continue;
    foreach ($allResources as &$res) {
        if ($res['ip_addr'] === $ip) {
            $labType = $lab['lab_type'] ?? 'Lab';
            $isRunning = ($lab['status'] ?? '') === 'running';
            $res['tag'] = $isRunning ? ucfirst($labType) : 'Unallocated';
            $res['tag_bg'] = $isRunning ? 'bg-primary' : 'bg-danger';
        }
    }
}
unset($res);

// Instances
$instances = $instDb->instances->find(['email' => $user->getEmail()])->toArray();
foreach ($instances as $inst) {
    $ip = $inst['deploy']['internal_ip'] ?? null;
    if (!$ip) continue;
    foreach ($allResources as &$res) {
        if ($res['ip_addr'] === $ip && empty($res['tag'])) {
            $tpl = $inst['template'] ?? 'Instance';
            $res['tag'] = ucfirst($tpl);
            $res['tag_bg'] = 'bg-info';
        }
    }
}
unset($res);

// Devices
$devices = $db->devices->find(['user_id' => $user->getUserId()])->toArray();
foreach ($devices as $dev) {
    $ip = $dev['assigned_ip'] ?? null;
    if (!$ip) continue;
    foreach ($allResources as &$res) {
        if ($res['ip_addr'] === $ip && empty($res['tag'])) {
            $res['tag'] = $dev['device_name'] ?? 'Device';
            $res['tag_bg'] = 'bg-success';
        }
    }
}
unset($res);

// 3. Auto-reserve IPs from labs/instances that aren't in ip_registry yet
$found = [];
foreach ($myIPs as $ip) { $found[$ip['ip_addr']] = true; }

foreach ($labs as $lab) {
    $ip = $lab['internal_ip'] ?? null;
    if ($ip && !isset($found[$ip])) {
        $db->ip_registry->updateOne(
            ['ip_addr' => $ip],
            ['$set' => ['status' => 'reserved', 'email' => $user->getEmail(), 'user_id' => $user->getUserId()]],
            ['upsert' => true]
        );
        $labType = $lab['lab_type'] ?? 'Lab';
        $allResources[] = [
            'ip_addr' => $ip,
            'iface'   => 'wg0',
            'tag'     => ucfirst($labType),
            'tag_bg'  => 'bg-primary',
        ];
    }
}

foreach ($instances as $inst) {
    $ip = $inst['deploy']['internal_ip'] ?? null;
    if ($ip && !isset($found[$ip])) {
        $db->ip_registry->updateOne(
            ['ip_addr' => $ip],
            ['$set' => ['status' => 'reserved', 'email' => $user->getEmail(), 'user_id' => $user->getUserId()]],
            ['upsert' => true]
        );
        $tpl = $inst['template'] ?? 'Instance';
        $allResources[] = [
            'ip_addr' => $ip,
            'iface'   => 'wg0',
            'tag'     => ucfirst($tpl),
            'tag_bg'  => 'bg-info',
        ];
    }
}

Session::$pageTitle = "Network"; 
Session::set('network_resources', $allResources);
Session::loadMaster();
