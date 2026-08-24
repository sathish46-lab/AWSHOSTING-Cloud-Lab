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
$evidenceData = [];
if ($isOwner) {
    foreach ($db->ai_roadmap_progress->find([
        'user_id' => $currentUserId,
        'roadmap_id' => new MongoDB\BSON\ObjectId($rmId)
    ]) as $p) {
        $topicId = (string)$p['topic_id'];
        $allProgress[$topicId] = bsonDecode($p['completed_items'] ?? []);

        // Any declaration means "has evidence" (even without files — self-declaration counts)
        $declarations = bsonDecode($p['declarations'] ?? []);
        foreach ($declarations as $decl) {
            $declItemId = $decl['item_id'] ?? '';
            if (empty($declItemId)) continue;
            if (!isset($evidenceData[$topicId])) $evidenceData[$topicId] = [];
            $evidenceData[$topicId][$declItemId] = true;
        }
    }
}

// Recalculate progress from actual data (don't rely on stale roadmap.progress)
$totalCheckpoints = 0;
$completedCheckpoints = 0;
$declaredItems = 0;
foreach ($sections as $section) {
    $topics = $section['topics'] ?? [];
    if (is_object($topics)) $topics = iterator_to_array($topics, false);
    foreach ($topics as $topic) {
        $topicId2 = $topic['id'] ?? '';
        $items = $topic['items'] ?? [];
        if (is_object($items)) $items = iterator_to_array($items, false);
        foreach ($items as $item) {
            $itemType = $item['type'] ?? 'concept';
            if (in_array($itemType, ['checkpoint', 'milestone', 'project'])) {
                $totalCheckpoints++;
                $ci = $allProgress[$topicId2] ?? [];
                if (in_array($item['id'] ?? '', $ci)) {
                    $completedCheckpoints++;
                }
            }
            // Count declarations (evidence submitted)
            $itemId2 = $item['id'] ?? '';
            if (!empty($itemId2) && isset($evidenceData[$topicId2][$itemId2])) {
                $declaredItems++;
            }
        }
    }
}
$liveProgress = $totalCheckpoints > 0 ? min(100, (int)round(($completedCheckpoints / $totalCheckpoints) * 100)) : 0;

echo json_encode([
    'success' => true,
    'roadmap' => [
        'id' => $rmId,
        'title' => (string)($roadmap['title'] ?? ''),
        'description' => (string)($roadmap['description'] ?? ''),
        'level' => (string)($roadmap['level'] ?? 'Beginner'),
        'hours' => (int)($roadmap['hours'] ?? 0),
        'tags' => $tags,
        'progress' => $liveProgress,
        'checkpoints_total' => $totalCheckpoints,
        'checkpoints_completed' => $completedCheckpoints,
        'declared_count' => $declaredItems,
        'visibility' => (string)($roadmap['visibility'] ?? 'private'),
        'sections' => $sections,
    ],
    'progress_data' => $allProgress,
    'evidence_data' => $evidenceData,
    'is_owner' => $isOwner
]);
