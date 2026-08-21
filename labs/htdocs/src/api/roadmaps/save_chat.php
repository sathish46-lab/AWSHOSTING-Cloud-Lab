<?php
/**
 * Roadmap AI Assist - Save Chat Message API
 * Saves an AI response to chat history (called from client on stream_end)
 */
require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$input = json_decode(file_get_contents('php://input'), true);
$roadmapId = trim($input['roadmap_id'] ?? '');
$content = $input['content'] ?? '';
$role = $input['role'] ?? 'model';
$tools = $input['tools'] ?? null;
$usage = $input['usage'] ?? null;

if (empty($roadmapId)) {
    http_response_code(400);
    echo json_encode(['error' => 'roadmap_id required']);
    exit;
}

if (empty($content)) {
    http_response_code(400);
    echo json_encode(['error' => 'content required']);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();

    $msg = [
        'role' => $role,
        'content' => $content,
        'timestamp' => time()
    ];
    if ($tools) $msg['tools'] = $tools;
    if ($usage) $msg['usage'] = $usage;

    $db->ai_chat_history->updateOne(
        ['user_id' => $userId, 'roadmap_id' => (string)$roadmapId],
        ['$push' => ['messages' => ['$each' => [$msg]]]],
        ['upsert' => true]
    );

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save: ' . $e->getMessage()]);
}
