<?php
/**
 * Roadmaps - Generate Roadmap API
 * Security: Auth required, rate-limited, dedupes in-flight jobs
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
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$prompt = trim($input['prompt'] ?? $_POST['prompt'] ?? '');
$level = trim($input['level'] ?? $_POST['level'] ?? 'Beginner');
$visibility = trim($input['visibility'] ?? $_POST['visibility'] ?? 'public');

// Validate level
$validLevels = ['Beginner', 'Intermediate', 'Advanced'];
if (!in_array($level, $validLevels)) {
    $level = 'Beginner';
}

// Validate visibility
$validVisibility = ['public', 'private'];
if (!in_array($visibility, $validVisibility)) {
    $visibility = 'public';
}

// Sanitize prompt
$prompt = htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8');

if (empty($prompt) || mb_strlen($prompt) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt must be at least 10 characters']);
    exit;
}

if (mb_strlen($prompt) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt must be under 2000 characters']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

// ── RATE LIMIT: max 3 in-flight jobs per user ──
$inflightCount = $db->ai_roadmap_jobs->countDocuments([
    'user_id' => $userId,
    'status' => 'running',
]);

if ($inflightCount >= 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many roadmaps being generated. Please wait for existing ones to complete.']);
    exit;
}

// ── DEDUPE: check for same prompt in-flight ──
$existingJob = $db->ai_roadmap_jobs->findOne([
    'user_id' => $userId,
    'prompt' => $prompt,
    'status' => 'running',
]);

if ($existingJob) {
    echo json_encode([
        'status' => 'running',
        'request_id' => $existingJob['request_id'],
        'message' => 'A roadmap for this topic is already being generated.',
    ]);
    exit;
}

// ── CREATE JOB ──
$requestId = sha1(uniqid('rm_', true) . $userId . microtime(true));

$job = [
    'request_id' => $requestId,
    'user_id' => $userId,
    'prompt' => $prompt,
    'level' => $level,
    'visibility' => $visibility,
    'status' => 'running',
    'percentage' => 15,
    'roadmap_id' => null,
    'error_message' => null,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
];

$db->ai_roadmap_jobs->insertOne($job);

echo json_encode([
    'status' => 'running',
    'request_id' => $requestId,
    'message' => 'Roadmap generation started',
]);
