<?php
// /app/labs/dashboard.php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    Session::$pageTitle = "Labs / Domains";
    Session::loadMaster();
    exit;
}

$user = Session::getUser();

// 1. Get Hash from URL
$uriParts = explode('/', $_SERVER['REQUEST_URI']);
$instanceHash = end($uriParts); 

if (empty($instanceHash)) { 
    header("Location: /labs"); 
    exit; 
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$labDoc = $db->machine_labs->findOne(['instance_hash' => $instanceHash]);
$labData = $labDoc;

// 2. If Lab is new, identify type by hash comparison
if (!$labData) {
    $labType = 'essentials';
    if ($instanceHash === $user->getLabHash('minio')) {
        $labType = 'minio';
    } elseif ($instanceHash === $user->getLabHash('n8n')) {
        $labType = 'n8n';
    } elseif ($instanceHash === $user->getLabHash('docker_lab')) {
        $labType = 'docker_lab';
    }
    
    // Create a "Virtual" lab object with the ACTUAL hash
    $labData = [
        'instance_hash' => $instanceHash,
        'lab_type' => $labType,
        'status' => 'not_deployed',
        'internal_ip' => '0.0.0.0'
    ];
} else {
    $labType = 'essentials';
    if ($instanceHash === $user->getLabHash('minio')) {
        $labType = 'minio';
    } elseif ($instanceHash === $user->getLabHash('n8n')) {
        $labType = 'n8n';
    } elseif ($instanceHash === $user->getLabHash('docker_lab')) {
        $labType = 'docker_lab';
    }
    $labType = $labData['lab_type'] ?? $labType;
    $instanceHash = $labData['instance_hash'];
}

// 3. Declare the exchange immediately
new RabbitClient("logs_" . $instanceHash);

// 4. Set session variables
Session::set('full_instance_hash', $instanceHash);
Session::set('current_lab_status', $labData['status'] ?? 'not_deployed');

Session::$pageTitle = "Tom Cloud Lab"; 
// error_log("DEBUG: instanceHash=$instanceHash, labType=$labType, status=" . ($labData['status'] ?? 'none'));

Session::loadMaster();