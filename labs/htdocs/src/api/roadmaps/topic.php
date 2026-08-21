<?php
/**
 * Roadmaps - Get Topic API
 * Security: Auth required, user-scoped roadmap access, private=404
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';

header('Content-Type: application/json');

// ── AUTH CHECK ──
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

// ── INPUT VALIDATION ──
$roadmapId = trim($_GET['roadmap_id'] ?? '');
$topicId = trim($_GET['topic_id'] ?? '');

// Validate ObjectId format
if (empty($roadmapId) || !preg_match('/^[a-f0-9]{24}$/i', $roadmapId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid roadmap_id']);
    exit;
}

if (empty($topicId) || !preg_match('/^[a-zA-Z0-9_]+$/', $topicId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid topic_id']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

// ── USER-SCOPED QUERY with private check ──
$roadmap = $db->ai_roadmaps->findOne([
    '_id' => new MongoDB\BSON\ObjectId($roadmapId),
    '$or' => [
        ['user_id' => $userId],           // Owner can always access
        ['visibility' => 'public'],        // Public roadmaps accessible to all
    ]
]);

// SECURITY: Private roadmaps return 404, not 403
if (!$roadmap) {
    http_response_code(404);
    echo json_encode(['error' => 'Roadmap not found']);
    exit;
}

// ── FIND TOPIC ──
$foundTopic = null;
$sectionTitle = '';

foreach ($roadmap['sections'] as $section) {
    foreach ($section['topics'] ?? [] as $topic) {
        if ($topic['id'] === $topicId) {
            $foundTopic = $topic;
            $sectionTitle = $section['title'];
            break 2;
        }
    }
}

if (!$foundTopic) {
    http_response_code(404);
    echo json_encode(['error' => 'Topic not found']);
    exit;
}

// ── GET USER PROGRESS FOR THIS TOPIC ──
$progress = $db->ai_roadmap_progress->findOne([
    'user_id' => $userId,
    'roadmap_id' => new MongoDB\BSON\ObjectId($roadmapId),
    'topic_id' => $topicId,
]);

$completedItems = [];
if ($progress) {
    $completedItems = $progress['completed_items'] ?? [];
}

echo json_encode([
    'topic' => [
        'id' => $foundTopic['id'],
        'title' => $foundTopic['title'],
        'items' => $foundTopic['items'] ?? [],
        'content' => $foundTopic['content'] ?? null,
        'content_html' => $foundTopic['content_html'] ?? null,
        'resources' => $foundTopic['resources'] ?? null,
        'section_title' => $sectionTitle,
        'completed_items' => $completedItems,
    ],
    'roadmap' => [
        'id' => (string)$roadmap['_id'],
        'title' => $roadmap['title'],
        'slug' => $roadmap['slug'],
    ],
]);
