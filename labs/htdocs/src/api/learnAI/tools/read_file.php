<?php
/**
 * AI Tool API: Read File Content from Lab Container
 * Called by ai_worker.py when the AI triggers the read_file_content tool.
 */
require_once __DIR__ . '/../../../load.php';

header('Content-Type: application/json');

// Internal API auth
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
$envConfig = json_decode(file_get_contents(__DIR__ . '/../../../../../../env.json'), true);
$internalToken = $envConfig['ai_internal_token'] ?? '';

if (empty($internalToken) || $authHeader !== "Bearer {$internalToken}") {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$filePath = $input['file_path'] ?? '';

if (empty($userId) || empty($filePath)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing user_id or file_path']);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    $inst = $db->machine_labs->findOne([
        'user_id' => (int)$userId,
        'status' => 'running'
    ]);
    $labDoc = $inst;

    if (!$labDoc || empty($labDoc['instance_hash'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'No running lab instance found for this user']);
        exit;
    }
    $instanceHash = $labDoc['instance_hash'];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Block sensitive paths
$blockedPaths = ['/etc/shadow', '/etc/gshadow', '/.labsconfig'];
foreach ($blockedPaths as $blocked) {
    if (strpos(realpath($filePath) ?: $filePath, $blocked) !== false || $filePath === $blocked) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'Access to this file is restricted']);
        exit;
    }
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $inst = $db->machine_labs->findOne(['instance_hash' => $instanceHash]);
    $labData = $inst;

    if (!$labData || ($labData['status'] ?? 'offline') !== 'running') {
        throw new Exception('Lab not found or not running');
    }

    $escapedPath = escapeshellarg($filePath);
    $escapedHash = escapeshellarg($instanceHash);

    $dockerCmd = "docker exec {$escapedHash} cat {$escapedPath} 2>&1";

    $output = [];
    $exitCode = 0;
    exec($dockerCmd, $output, $exitCode);

    $content = implode("\n", $output);

    // Limit to 8KB
    if (strlen($content) > 8192) {
        $content = substr($content, 0, 8192) . "\n... (file truncated to 8KB)";
    }

    if ($exitCode !== 0) {
        throw new Exception("File read failed: {$content}");
    }

    echo json_encode([
        'status'  => 'success',
        'content' => $content,
        'path'    => $filePath
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
