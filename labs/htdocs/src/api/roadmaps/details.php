<?php
/**
 * Roadmaps - Get Roadmap Details (for prompt display)
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$roadmapId = trim($_GET['roadmap_id'] ?? '');
if (empty($roadmapId) || !preg_match('/^[a-f0-9]{24}$/i', $roadmapId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid roadmap_id']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

$roadmap = $db->ai_roadmaps->findOne([
    '_id' => new MongoDB\BSON\ObjectId($roadmapId),
    'user_id' => $userId,
]);

if (!$roadmap) {
    http_response_code(404);
    echo json_encode(['error' => 'Roadmap not found']);
    exit;
}

$tags = ($roadmap['tags'] instanceof MongoDB\Model\BSONArray) ? iterator_to_array($roadmap['tags'], false) : (array)($roadmap['tags'] ?? []);

echo json_encode([
    'prompt' => $roadmap['prompt'] ?? '',
    'title' => $roadmap['title'] ?? '',
    'tags' => $tags,
    'level' => $roadmap['level'] ?? '',
    'model' => $roadmap['ai_model'] ?? '',
    'markdown' => $roadmap['markdown'] ?? '',
]);
