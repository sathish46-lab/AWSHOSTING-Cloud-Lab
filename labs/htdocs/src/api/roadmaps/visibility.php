<?php
/**
 * Roadmaps - Toggle Visibility (public/private)
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
    echo json_encode(['error' => 'Roadmap not found or access denied']);
    exit;
}

$currentVisibility = $roadmap['visibility'] ?? 'private';
$newVisibility = $currentVisibility === 'public' ? 'private' : 'public';

$db->ai_roadmaps->updateOne(
    [
        '_id' => new MongoDB\BSON\ObjectId($roadmapId),
        'user_id' => $userId,
    ],
    [
        '$set' => [
            'visibility' => $newVisibility,
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ]
    ]
);

echo json_encode([
    'success' => true,
    'visibility' => $newVisibility,
]);
