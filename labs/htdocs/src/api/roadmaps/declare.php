<?php
/**
 * Roadmaps - Declare Completion API
 * Allows user to declare a checkpoint/milestone as completed
 * Evidence is optional - user can save progress without files
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
$username = $user->getUsername() ?? 'User';

// Handle both FormData and JSON
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    $input = $_POST;
}

$roadmapId = trim($input['roadmap_id'] ?? '');
$topicId = trim($input['topic_id'] ?? '');
$itemId = trim($input['item_id'] ?? '');
$notes = trim($input['notes'] ?? '');
$completed = ($input['completed'] ?? 'true') === 'true';
$evidenceUrls = json_decode($input['evidence_urls'] ?? '[]', true);

if (empty($roadmapId) || empty($topicId) || empty($itemId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing roadmap_id, topic_id, or item_id']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

// Verify roadmap ownership
$roadmap = $db->ai_roadmaps->findOne([
    '_id' => new MongoDB\BSON\ObjectId($roadmapId),
    'user_id' => $userId
]);

if (!$roadmap) {
    http_response_code(404);
    echo json_encode(['error' => 'Roadmap not found or access denied']);
    exit;
}

// Handle file uploads (save to temp for now - in production, upload to S3/CDN)
$evidenceFiles = [];
if (!empty($_FILES['evidence_files'])) {
    $uploadDir = '/tmp/roadmap_evidence/' . $roadmapId . '/' . $topicId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $files = $_FILES['evidence_files'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $name = basename($files['name'][$i]);
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $uploadDir . $safeName;
            if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $evidenceFiles[] = [
                    'name' => $name,
                    'path' => $dest,
                    'size' => $files['size'][$i],
                ];
            }
        }
    }
}

// Build evidence array
$evidence = [];
foreach ($evidenceFiles as $f) {
    $evidence[] = ['type' => 'file', 'name' => $f['name'], 'path' => $f['path'], 'size' => $f['size']];
}
foreach ($evidenceUrls as $url) {
    $evidence[] = ['type' => 'url', 'value' => $url];
}

$now = new MongoDB\BSON\UTCDateTime();
$declarationId = md5($roadmapId . $topicId . $itemId . $userId . time());

// Upsert progress record
$existingProgress = $db->ai_roadmap_progress->findOne([
    'user_id' => $userId,
    'roadmap_id' => new MongoDB\BSON\ObjectId($roadmapId),
    'topic_id' => $topicId,
]);

if ($existingProgress) {
    // Add item to completed_items if not already there
    $completedItems = $existingProgress['completed_items'] ?? [];
    if (is_object($completedItems)) $completedItems = iterator_to_array($completedItems, false);
    if (!is_array($completedItems)) $completedItems = [];

    if ($completed && !in_array($itemId, $completedItems)) {
        $completedItems[] = $itemId;
    } elseif (!$completed) {
        $completedItems = array_values(array_filter($completedItems, function($id) use ($itemId) {
            return $id !== $itemId;
        }));
    }

    // Add declaration record
    $declarations = $existingProgress['declarations'] ?? [];
    if (is_object($declarations)) $declarations = iterator_to_array($declarations, false);
    if (!is_array($declarations)) $declarations = [];

    $declarations[] = [
        'declaration_id' => $declarationId,
        'item_id' => $itemId,
        'evidence' => $evidence,
        'notes' => $notes,
        'username' => $username,
        'declared_at' => $now,
    ];

    $db->ai_roadmap_progress->updateOne(
        ['_id' => $existingProgress['_id']],
        ['$set' => [
            'completed_items' => $completedItems,
            'declarations' => $declarations,
            'updated_at' => $now,
        ]]
    );
} else {
    // Create new progress record
    $db->ai_roadmap_progress->insertOne([
        'user_id' => $userId,
        'roadmap_id' => new MongoDB\BSON\ObjectId($roadmapId),
        'topic_id' => $topicId,
        'completed_items' => $completed ? [$itemId] : [],
        'declarations' => $completed ? [[
            'declaration_id' => $declarationId,
            'item_id' => $itemId,
            'evidence' => $evidence,
            'notes' => $notes,
            'username' => $username,
            'declared_at' => $now,
        ]] : [],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// Recalculate progress
$totalCheckpoints = 0;
$completedCheckpoints = 0;
$sections = $roadmap['sections'] ?? [];
if (is_object($sections)) $sections = iterator_to_array($sections, false);

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
                // Check if completed
                $prog = $db->ai_roadmap_progress->findOne([
                    'user_id' => $userId,
                    'roadmap_id' => new MongoDB\BSON\ObjectId($roadmapId),
                    'topic_id' => $topicId2,
                ]);
                if ($prog) {
                    $ci = $prog['completed_items'] ?? [];
                    if (is_object($ci)) $ci = iterator_to_array($ci, false);
                    if (in_array($item['id'] ?? '', $ci)) {
                        $completedCheckpoints++;
                    }
                }
            }
        }
    }
}

$progressPct = $totalCheckpoints > 0 ? min(100, round(($completedCheckpoints / $totalCheckpoints) * 100)) : 0;

// Update roadmap progress
$db->ai_roadmaps->updateOne(
    ['_id' => new MongoDB\BSON\ObjectId($roadmapId)],
    ['$set' => [
        'progress' => $progressPct,
        'checkpoints_completed' => $completedCheckpoints,
        'checkpoints_total' => $totalCheckpoints,
    ]]
);

echo json_encode([
    'result' => 'success',
    'declaration_id' => $declarationId,
    'progress_percentage' => $progressPct,
    'checkpoints_completed' => $completedCheckpoints,
    'checkpoints_total' => $totalCheckpoints,
]);
