<?php
/**
 * Roadmaps - Generate Item Content + Free Resources
 * Stores content per-item, not per-topic.
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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$roadmapId = trim($input['roadmap_id'] ?? '');
$topicId = trim($input['topic_id'] ?? '');
$itemId = trim($input['item_id'] ?? '');

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
if (empty($itemId) || !preg_match('/^[a-zA-Z0-9_]+$/', $itemId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid item_id']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

$roadmap = $db->ai_roadmaps->findOne([
    '_id' => new MongoDB\BSON\ObjectId($roadmapId),
    'user_id' => $userId,
]);

if (!$roadmap) {
    http_response_code(404);
    echo json_encode(['error' => 'Roadmap not found or access denied']);
    exit;
}

// Find topic and item indices
$foundTopic = null;
$foundItem = null;
$sectionIndex = null;
$topicIndex = null;
$itemIndex = null;

foreach ($roadmap['sections'] as $si => $section) {
    foreach ($section['topics'] ?? [] as $ti => $topic) {
        if ($topic['id'] === $topicId) {
            $foundTopic = $topic;
            $sectionIndex = $si;
            $topicIndex = $ti;
            foreach ($topic['items'] ?? [] as $ii => $item) {
                if ($item['id'] === $itemId) {
                    $foundItem = $item;
                    $itemIndex = $ii;
                    break 3;
                }
            }
        }
    }
}

if (!$foundTopic || !$foundItem) {
    http_response_code(404);
    echo json_encode(['error' => 'Topic or item not found']);
    exit;
}

// Check if already generated for this item
if (!empty($foundItem['content'])) {
    $c = $foundItem['content'];
    $ch = $foundItem['content_html'] ?? '';
    // Unwrap JSON if stored wrapped
    $decoded = json_decode($c, true);
    if (is_array($decoded)) {
        foreach (['explanation','content','text','body','response'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) { $c = $decoded[$key]; break; }
        }
    }
    $decodedH = json_decode(strip_tags($ch), true);
    if (is_array($decodedH)) {
        foreach (['explanation','content','text','body','response'] as $key) {
            if (!empty($decodedH[$key]) && is_string($decodedH[$key])) { $ch = $decodedH[$key]; break; }
        }
    }
    echo json_encode([
        'status' => 'already_generated',
        'content' => $c,
        'content_html' => $ch,
        'resources' => $foundItem['resources'] ?? [],
    ]);
    exit;
}

// Generate content
require_once __DIR__ . '/../../lib/services/RoadmapGenerator.class.php';
require_once __DIR__ . '/../../lib/services/WebSearchService.class.php';

$generator = new RoadmapGenerator();
$search = new WebSearchService();

$sectionTitle = $roadmap['sections'][$sectionIndex]['title'] ?? '';
$roadmapTitle = $roadmap['title'] ?? '';
$itemText = $foundItem['text'] ?? $foundItem['title'] ?? '';
$itemType = $foundItem['type'] ?? 'concept';

$contentResult = $generator->generateItemContent(
    $itemText,
    $itemType,
    $foundTopic['title'],
    $sectionTitle,
    $roadmapTitle
);

$resourceQuery = $itemText . ' ' . $roadmapTitle . ' tutorial guide';
$resources = $search->searchResources($resourceQuery, 6);

$sanitizedResources = [];
foreach ($resources as $r) {
    $url = $r['url'] ?? '';
    if (!preg_match('/^https?:\/\//i', $url)) continue;
    $sanitizedResources[] = [
        'type' => htmlspecialchars($r['type'] ?? 'article', ENT_QUOTES, 'UTF-8'),
        'title' => htmlspecialchars($r['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'url' => $url,
        'source' => htmlspecialchars($r['source'] ?? '', ENT_QUOTES, 'UTF-8'),
    ];
}

// Store content on the item itself
$updatePath = "sections.{$sectionIndex}.topics.{$topicIndex}.items.{$itemIndex}";

$db->ai_roadmaps->updateOne(
    [
        '_id' => new MongoDB\BSON\ObjectId($roadmapId),
        'user_id' => $userId,
    ],
    [
        '$set' => [
            "{$updatePath}.content" => $contentResult['content'],
            "{$updatePath}.content_html" => $contentResult['content_html'],
            "{$updatePath}.resources" => $sanitizedResources,
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ]
    ]
);

echo json_encode([
    'status' => 'generated',
    'content' => $contentResult['content'],
    'content_html' => $contentResult['content_html'],
    'resources' => $sanitizedResources,
]);
