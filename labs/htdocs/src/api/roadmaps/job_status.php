<?php
/**
 * Roadmaps - Job Status API
 * Security: Auth required, user-scoped job lookup
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
$requestId = trim($_GET['request_id'] ?? '');

// Validate request_id format (40-char hex SHA1)
if (empty($requestId) || !preg_match('/^[a-f0-9]{40}$/i', $requestId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request_id format']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

// ── USER-SCOPED QUERY ──
$job = $db->ai_roadmap_jobs->findOne([
    'request_id' => $requestId,
    'user_id' => $userId,  // SECURITY: only return user's own jobs
]);

if (!$job) {
    http_response_code(404);
    echo json_encode([
        'status' => 'failed',
        'message' => 'Job not found',
        'roadmap_id' => null,
        'request_id' => $requestId,
        'completed' => false,
        'failed' => true,
        'error_message' => 'Job not found or access denied',
    ]);
    exit;
}

// ── COMPLETED JOB ──
if ($job['status'] === 'completed') {
    echo json_encode([
        'status' => 'completed',
        'message' => 'Roadmap generated successfully!',
        'roadmap_id' => $job['roadmap_id'] ?? null,
        'slug' => $job['slug'] ?? null,
        'request_id' => $requestId,
        'completed' => true,
        'failed' => false,
        'percentage' => 100,
    ]);
    exit;
}

// ── FAILED JOB ──
if ($job['status'] === 'failed') {
    echo json_encode([
        'status' => 'failed',
        'message' => $job['error_message'] ?? 'Generation failed',
        'roadmap_id' => null,
        'request_id' => $requestId,
        'completed' => false,
        'failed' => true,
        'error_message' => $job['error_message'] ?? 'Unknown error',
    ]);
    exit;
}

// ── RUNNING JOB — GENERATE ON THIRD POLL ──
$elapsed = time() - (int)$job['created_at']->toDateTime()->format('U');

if ($elapsed <= 1) {
    echo json_encode([
        'status' => 'running',
        'message' => 'Analyzing your topic...',
        'percentage' => 25,
        'roadmap_id' => null,
        'completed' => false,
    ]);
    exit;
}

if ($elapsed <= 3) {
    echo json_encode([
        'status' => 'running',
        'message' => 'Designing roadmap structure...',
        'percentage' => 55,
        'roadmap_id' => null,
        'completed' => false,
    ]);
    exit;
}

// ── THIRD POLL: Actually generate the roadmap ──
require_once __DIR__ . '/../../lib/services/RoadmapGenerator.class.php';

$generator = new RoadmapGenerator();
$structure = $generator->generateStructure($job['prompt'], $job['level']);
$markdown = $generator->structureToMarkdown($structure);

// Count ALL items (not just checkpoints)
$totalItems = 0;
foreach ($structure['sections'] as $section) {
    foreach ($section['topics'] ?? [] as $topic) {
        $totalItems += count($topic['items'] ?? []);
    }
}

// Get user info (scope to authenticated user only)
$username = $user->getUsername();
$email = $user->getEmail();

// ── INSERT ROADMAP ──
$roadmapId = new MongoDB\BSON\ObjectId();
$roadmap = [
    '_id' => $roadmapId,
    'slug' => $structure['slug'],
    'title' => htmlspecialchars($structure['title'], ENT_QUOTES, 'UTF-8'),
    'description' => htmlspecialchars($structure['description'] ?? '', ENT_QUOTES, 'UTF-8'),
    'prompt' => $job['prompt'],
    'level' => $structure['level'],
    'hours' => (int)($structure['hours'] ?? 20),
    'tags' => array_map(function($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }, $structure['tags'] ?? []),
    'type' => 'roadmap',
    'visibility' => $job['visibility'] ?? 'public',
    'user_id' => $userId,
    'author' => $username,
    'author_email' => $email,
    'sections' => $structure['sections'],
    'markdown' => $markdown,
    'ai_model' => 'gemini-2.5-flash',
    'progress' => 0,
    'checkpoints_total' => $totalItems,
    'checkpoints_completed' => 0,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
];

$db->ai_roadmaps->insertOne($roadmap);

$roadmapIdStr = (string)$roadmapId;

// ── UPDATE JOB ──
$db->ai_roadmap_jobs->updateOne(
    ['request_id' => $requestId, 'user_id' => $userId],
    ['$set' => [
        'status' => 'completed',
        'roadmap_id' => $roadmapIdStr,
        'slug' => $structure['slug'],
        'percentage' => 100,
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ]]
);

echo json_encode([
    'status' => 'completed',
    'message' => 'Roadmap generated successfully!',
    'roadmap_id' => $roadmapIdStr,
    'slug' => $structure['slug'],
    'request_id' => $requestId,
    'completed' => true,
    'failed' => false,
    'percentage' => 100,
]);
