<?php
/**
 * Pause Lab API
 * Freezes the container - zero CPU, memory preserved, network kept
 * Resume is instant
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
    
    // Check lab exists and is running
    $inst = $col->findOne(['instance_hash' => $instanceHash]);
    if (!$inst) {
        throw new Exception("Lab not found");
    }
    
    $currentStatus = $inst['status'] ?? 'unknown';
    
    if ($currentStatus !== 'running') {
        throw new Exception("Lab must be running to pause. Current status: {$currentStatus}");
    }
    
    // 1. Pause container (host-side docker command)
    $escapedHash = escapeshellarg($instanceHash);
    $output = [];
    $returnCode = 0;
    exec("docker pause {$escapedHash} 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception("Failed to pause container: " . implode("\n", $output));
    }
    
    // 2. Update status to paused
    $col->updateOne(
        ['instance_hash' => $instanceHash],
        [
            '$set' => ['status' => 'paused'],
            '$push' => [
                'activity_log' => [
                    '$each' => [
                        [
                            'action' => 'Paused',
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
        'message' => 'Lab paused successfully',
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
