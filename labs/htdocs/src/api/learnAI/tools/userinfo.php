<?php
/**
 * Learn AI Tool API - Get Lab User Info
 * Called internally by ai_worker.py
 */
require_once __DIR__ . '/../../../load.php';

header('Content-Type: application/json');

// 1. Verify Internal Token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$internalToken = null;

$envPath = __DIR__ . '/../../../../../../env.json';
if (file_exists($envPath)) {
    $env = json_decode(file_get_contents($envPath), true);
    $internalToken = $env['ai_internal_token'] ?? null;
}

if (!$internalToken || $authHeader !== "Bearer $internalToken") {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized access."]);
    exit;
}

// 2. Parse payload
$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;

if (!$userId) {
    http_response_code(400);
    echo json_encode(["error" => "user_id is required"]);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    
    // Get User Profile
    $userDoc = $db->users->findOne(['_id' => (int)$userId]);
    $username = $userDoc['username'] ?? 'unknown';
    $email = $userDoc['email'] ?? 'unknown';

    // Get Active Lab
    $activeLab = null;
    $inst = $db->machine_labs->findOne([
        'deploy.user_id' => (int)$userId,
        'deploy.status' => 'running'
    ]);
    $labDoc = $inst ? ($inst['deploy'] ?? []) : null;

    $labNames = [
        'essentials' => 'Essentials Lab',
        'minio' => 'MinIO S3 Storage',
        'n8n' => 'n8n Workflow Lab',
        'docker_lab' => 'Tom Docker Lab'
    ];

    if ($labDoc) {
        $labType = $labDoc['lab_type'] ?? 'unknown';
        $labName = $labNames[$labType] ?? 'Unknown Lab';
        
        $activeLab = [
            'lab_name' => $labName,
            'ip' => $labDoc['internal_ip'] ?? 'Unknown',
            'instance_id' => $labDoc['instance_hash'] ?? ''
        ];
    } else {
        $activeLab = [
            'lab_name' => 'Unknown',
            'ip' => 'Instance Down',
            'instance_id' => ''
        ];
    }

    $response = [
        "username" => $username,
        "email" => $email,
        "lab_name" => $activeLab['lab_name'],
        "ip" => $activeLab['ip'],
        "instance_id" => $activeLab['instance_id']
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
