<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';
header('Content-Type: application/json');

$user = Session::getUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$currentUserId = (int)$user->getUserId();
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing slug parameter']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

$roadmap = $db->ai_roadmaps->findOne([
    'slug' => $slug,
    '$or' => [
        ['user_id' => $currentUserId],
        ['visibility' => 'public']
    ]
]);

if (!$roadmap) {
    http_response_code(404);
    echo json_encode(['error' => 'Roadmap not found']);
    exit;
}

$rmId = (string)$roadmap['_id'];
$isOwner = ($roadmap['user_id'] === $currentUserId);

function bsonDecode($v) {
    if ($v instanceof MongoDB\Model\BSONArray) return iterator_to_array($v, false);
    if ($v instanceof MongoDB\Model\BSONDocument) return iterator_to_array($v, false);
    if (is_array($v)) return array_map('bsonDecode', $v);
    return $v;
}

$sections = bsonDecode($roadmap['sections'] ?? []);
$tags = bsonDecode($roadmap['tags'] ?? []);

$allProgress = [];
if ($isOwner) {
    foreach ($db->ai_roadmap_progress->find([
        'user_id' => $currentUserId,
        'roadmap_id' => new MongoDB\BSON\ObjectId($rmId)
    ]) as $p) {
        $allProgress[(string)$p['topic_id']] = bsonDecode($p['completed_items'] ?? []);
    }
}

echo json_encode([
    'success' => true,
    'roadmap' => [
        'id' => $rmId,
        'title' => (string)($roadmap['title'] ?? ''),
        'description' => (string)($roadmap['description'] ?? ''),
        'level' => (string)($roadmap['level'] ?? 'Beginner'),
        'hours' => (int)($roadmap['hours'] ?? 0),
        'tags' => $tags,
        'progress' => (int)($roadmap['progress'] ?? 0),
        'checkpoints_total' => (int)($roadmap['checkpoints_total'] ?? 0),
        'checkpoints_completed' => (int)($roadmap['checkpoints_completed'] ?? 0),
        'visibility' => (string)($roadmap['visibility'] ?? 'private'),
        'sections' => $sections,
    ],
    'progress_data' => $allProgress,
    'is_owner' => $isOwner
]);
