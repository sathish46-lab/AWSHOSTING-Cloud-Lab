<?php
// /var/www/labs/htdocs/src/api/labs/stop.php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../lib/core/jobs/Process.class.php';
require_once __DIR__ . '/../../lib/core/jobs/Worker.class.php';
require_once __DIR__ . '/../../lib/core/RabbitClient.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$labName = $_POST['lab'] ?? 'essentials';

try {
    // 1. Get hash (Same logic as deploy)
    $instanceHash = $user->getLabHash($labName); 
    
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $col = $db->machine_labs;
    
    // 2. Check if the lab exists (flat structure: instance_hash at top level)
    $inst = $col->findOne(['instance_hash' => $instanceHash]);
    if (!$inst) {
        throw new Exception("No active lab session found.");
    }
    $existing = $inst;
    
    // 3. Update status to 'stopping' immediately (flat structure)
    $col->updateOne(
        ['instance_hash' => $instanceHash],
        [
            '$set' => ['status' => 'stopping'],
            '$push' => [
                'activity_log' => [
                    '$each' => [
                        [
                            'action' => 'Stopped',
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
    
    // 4. Trigger worker via QUEUE
    $work = [
        'action' => 'stop',
        'lab'    => $labName, 
        'hash'   => $instanceHash, 
        'user'   => $user->getUsername()
    ];
    
    $rabbit = new RabbitClient(); // Defaults to amq.topic
    $rabbit->sendToQueue('labs_jobs', $work);
    
    // NOTE: We do NOT call LabIPManager::release() here. 
    // We want the user to keep their IP even when the container is off.

    echo json_encode([
        'status'  => 'success',
        'message' => 'Shutdown request queued',
        'hash'    => $instanceHash
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}