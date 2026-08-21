<?php
/**
 * Resume Lab API
 * Unfreezes the container - restores CPU, memory, network
 * Instant resume
 */
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$labName = $_POST['lab'] ?? 'essentials';

try {
    $instanceHash = $user->getLabHash($labName);
    
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $col = $db->machine_labs;
    
    // Check lab exists and is paused
    $inst = $col->findOne(['instance_hash' => $instanceHash]);
    if (!$inst) {
        throw new Exception("Lab not found");
    }
    
    $currentStatus = $inst['status'] ?? 'unknown';
    
    if ($currentStatus !== 'paused') {
        throw new Exception("Lab must be paused to resume. Current status: {$currentStatus}");
    }
    
    // 1. Resume container (host-side docker command)
    $escapedHash = escapeshellarg($instanceHash);
    $output = [];
    $returnCode = 0;
    exec("docker unpause {$escapedHash} 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception("Failed to resume container: " . implode("\n", $output));
    }
    
    // 2. Update status to running
    $col->updateOne(
        ['instance_hash' => $instanceHash],
        [
            '$set' => ['status' => 'running'],
            '$push' => [
                'activity_log' => [
                    '$each' => [
                        [
                            'action' => 'Resumed',
                            'user' => $user->getUsername(),
                            'timestamp' => time(),
                            'type' => 'lab'
                        ]
                    ],
                    '$position' => 0,
                    '$slice' => 50
                ]
            ]
        ]
    );
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Lab resumed successfully',
        'hash' => $instanceHash,
        'lab' => $labName
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}
