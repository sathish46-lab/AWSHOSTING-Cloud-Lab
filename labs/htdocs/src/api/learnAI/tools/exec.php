<?php
/**
 * AI Tool API: Execute Command in Lab Container
 * Called by ai_worker.py when the AI triggers the execute_command_in_lab tool.
 * 
 * Security:
 * - Validates API bearer token (shared secret between worker and PHP)
 * - Verifies lab ownership via instance_hash → user_id check
 * - Blocks dangerous commands (rm -rf /, etc.)
 * - Executes via docker exec inside the user's container
 */
require_once __DIR__ . '/../../../load.php';

header('Content-Type: application/json');

// Internal API auth: Only ai_worker.py can call this
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
$envConfig = json_decode(file_get_contents(__DIR__ . '/../../../../../../env.json'), true);
$internalToken = $envConfig['ai_internal_token'] ?? '';

if (empty($internalToken) || $authHeader !== "Bearer {$internalToken}") {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Forbidden: Invalid internal token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$command = $input['command'] ?? '';
$username = $input['username'] ?? null;

if (empty($userId) || empty($command)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing user_id or command']);
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

// Block dangerous commands
$dangerousPatterns = [
    '/rm\s+(-rf?|--no-preserve-root)\s+\/\s*$/i',
    '/mkfs\./i',
    '/dd\s+if=.*of=\/dev\//i',
    '/:(){.*};/i',
    '/>\s*\/dev\/[sh]d[a-z]/i',
];
foreach ($dangerousPatterns as $pattern) {
    if (preg_match($pattern, $command)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Command blocked: potentially destructive operation']);
        exit;
    }
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $inst = $db->machine_labs->findOne(['instance_hash' => $instanceHash]);
    $labData = $inst;

    if (!$labData) {
        throw new Exception('Lab not found for this instance hash');
    }

    if (($labData['status'] ?? 'offline') !== 'running') {
        throw new Exception('Lab is not running. Deploy your lab first.');
    }

    // Build docker exec command
    $escapedCommand = escapeshellarg($command);
    $userFlag = '';
    if (!empty($username)) {
        $escapedUser = escapeshellarg($username);
        $userFlag = "-u {$escapedUser}";
    }
    $escapedHash = escapeshellarg($instanceHash);

    $dockerCmd = "sudo docker exec {$userFlag} {$escapedHash} bash -c {$escapedCommand} 2>&1";

    $output = [];
    $exitCode = 0;
    exec($dockerCmd, $output, $exitCode);

    $outputText = implode("\n", $output);

    // Limit output to 4KB to prevent huge responses overwhelming the AI
    if (strlen($outputText) > 4096) {
        $outputText = substr($outputText, 0, 4096) . "\n... (output truncated to 4KB)";
    }

    echo json_encode([
        'status'    => 'success',
        'output'    => $outputText,
        'exit_code' => $exitCode
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
