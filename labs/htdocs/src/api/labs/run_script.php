<?php
/**
 * POST /api/labs/run_script
 * Execute the init.sh script immediately inside the running container.
 * Queues a job via RabbitMQ so output streams to the user's log terminal.
 */
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../lib/core/jobs/Process.class.php';
require_once __DIR__ . '/../../lib/core/jobs/Worker.class.php';
require_once __DIR__ . '/../../lib/core/RabbitClient.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['hash'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing required fields']); exit;
}

$labName = $input['lab'] ?? 'essentials';
if (!\TomLabs\Labs\LabTemplateConfig::supportsFeature($labName, 'startup_script')) {
    echo json_encode(['status' => 'error', 'error' => 'Startup script feature is not supported for this lab type']); exit;
}
$instanceHash = $user->getLabHash($labName);

if ($instanceHash !== $input['hash']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Hash mismatch']); exit;
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $col = $db->machine_labs;

    $inst = $col->findOne(['instance_hash' => $instanceHash]);
    $existing = $inst;
    if (!$existing) {
        throw new Exception('Lab not found. Please deploy first.');
    }

    $status = $existing['status'] ?? 'offline';
    if ($status !== 'running') {
        throw new Exception('Lab is not running. Start your lab first.');
    }

    // Save the script content to DB for persistence
    $scriptContent = isset($input['script']) ? (string)$input['script'] : '#!/bin/bash';
    $col->updateOne(
        ['instance_hash' => $instanceHash],
        ['$set' => ['init_script' => $scriptContent]]
    );

    // Queue a run-script job
    $work = [
        'action' => 'run-script',
        'lab'    => $labName,
        'hash'   => $instanceHash,
        'user'   => $user->getUsername()
    ];

    $rabbit = new RabbitClient();
    $rabbit->sendToQueue('labs_jobs', $work);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Script execution queued',
        'hash'    => $instanceHash,
        'queued'  => true
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
