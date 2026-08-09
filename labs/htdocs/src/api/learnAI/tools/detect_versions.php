<?php
/**
 * AI Tool API: Detect Tool Versions in Lab Container
 * Called by ai_worker.py when the AI triggers the detect_tool_versions tool.
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
$lessonId = $input['lesson_id'] ?? null;
$tools = $input['tools'] ?? [];

if (empty($userId) || empty($tools) || !is_array($tools)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing user_id or tools array']);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    $inst = $db->machine_labs->findOne([
        'user_id' => (int)$userId,
        'status' => 'running',
        'lab_type' => 'essentials'
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

// Only allow known safe tool names
$allowedTools = ['python3', 'python', 'node', 'npm', 'php', 'java', 'javac', 'gcc', 'g++', 
                 'go', 'rustc', 'cargo', 'ruby', 'perl', 'git', 'docker', 'nginx', 'apache2',
                 'mysql', 'mongo', 'mongosh', 'redis-cli', 'pip3', 'pip', 'composer', 'yarn',
                 'curl', 'wget', 'ssh', 'systemctl', 'bash', 'zsh'];

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $inst = $db->machine_labs->findOne(['instance_hash' => $instanceHash]);
    $labData = $inst;

    if (!$labData || ($labData['status'] ?? 'offline') !== 'running') {
        throw new Exception('Lab not found or not running');
    }

    $escapedHash = escapeshellarg($instanceHash);
    $versions = [];

    foreach ($tools as $tool) {
        $tool = trim((string)$tool);
        if (!in_array($tool, $allowedTools)) {
            $versions[$tool] = 'not allowed';
            continue;
        }

        $escapedTool = escapeshellarg($tool);
        $dockerCmd = "sudo docker exec {$escapedHash} bash -c '{$tool} --version 2>/dev/null || echo NOT_INSTALLED' 2>&1";

        $output = [];
        exec($dockerCmd, $output, $exitCode);
        $versionOutput = trim(implode(' ', $output));

        if (strpos($versionOutput, 'NOT_INSTALLED') !== false || $exitCode !== 0) {
            $versions[$tool] = 'not installed';
        } else {
            // Extract first line only for cleanliness
            $firstLine = explode("\n", $versionOutput)[0];
            $versions[$tool] = trim($firstLine);
        }
    }

    echo json_encode([
        'status'   => 'success',
        'lab_name' => $labData['name'] ?? 'Unknown',
        'versions' => $versions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
