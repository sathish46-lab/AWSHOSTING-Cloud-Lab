<?php
/**
 * Roadmaps - Generate Stream API
 * Creates a job and pushes to RabbitMQ for Python worker to stream
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';
require_once __DIR__ . '/../../../src/lib/core/RabbitClient.class.php';

header('Content-Type: application/json');

// ── AUTH CHECK ──
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername();
$email = $user->getEmail();

// ── INPUT VALIDATION ──
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$prompt = trim($input['prompt'] ?? '');
$level = trim($input['level'] ?? 'Beginner');
$visibility = trim($input['visibility'] ?? 'private');

// Validate level
$validLevels = ['Beginner', 'Intermediate', 'Advanced'];
if (!in_array($level, $validLevels)) $level = 'Beginner';

// Validate visibility
$validVisibility = ['public', 'private'];
if (!in_array($visibility, $validVisibility)) $visibility = 'private';

// Sanitize
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
    'status' => ['$in' => ['running', 'streaming']],
]);

if ($inflightCount >= 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many roadmaps being generated. Please wait.']);
    exit;
}

// ── CREATE JOB ──
$jobId = sha1(uniqid('rm_', true) . $userId . microtime(true));

$job = [
    'request_id' => $jobId,
    'user_id' => $userId,
    'prompt' => $prompt,
    'level' => $level,
    'visibility' => $visibility,
    'status' => 'streaming',
    'percentage' => 0,
    'roadmap_id' => null,
    'slug' => null,
    'error_message' => null,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
];

$db->ai_roadmap_jobs->insertOne($job);

// ── PUSH TO RABBITMQ ──
try {
    $rabbit = new RabbitClient();
    $rabbit->sendToQueue('ai_jobs', json_encode([
        'type' => 'roadmap_generation',
        'job_id' => $jobId,
        'user_id' => $userId,
        'username' => $username,
        'email' => $email,
        'prompt' => $prompt,
        'level' => $level,
        'visibility' => $visibility,
        'session_id' => $jobId,  // reuse as session for WebSocket topic
    ]));
} catch (Exception $e) {
    // Update job as failed
    $db->ai_roadmap_jobs->updateOne(
        ['request_id' => $jobId],
        ['$set' => ['status' => 'failed', 'error_message' => 'Failed to queue job: ' . $e->getMessage()]]
    );
    http_response_code(500);
    echo json_encode(['error' => 'Failed to start generation. Please try again.']);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'job_id' => $jobId,
    'message' => 'Generation started',
]);
