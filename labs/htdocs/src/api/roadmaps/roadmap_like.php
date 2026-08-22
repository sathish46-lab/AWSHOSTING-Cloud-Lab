<?php
/**
 * Roadmaps - Like API
 * Toggles like/unlike status for a roadmap
 */
require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$currentUsername = $user ? $user->getUsername() : '';
$currentUserId = $user ? (int)$user->getUserId() : 0;

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$roadmapId = trim($input['roadmap_id'] ?? $_POST['roadmap_id'] ?? '');

if (empty($roadmapId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid roadmap_id']);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    $objectId = new MongoDB\BSON\ObjectId($roadmapId);
    $roadmap = $db->ai_roadmaps->findOne(['_id' => $objectId]);

    if (!$roadmap) {
        http_response_code(404);
        echo json_encode(['error' => 'Roadmap not found']);
        exit;
    }

    $likesArray = $roadmap['likes'] ?? [];
    if (!is_array($likesArray)) {
        if (is_object($likesArray)) {
            $likesArray = method_exists($likesArray, 'getArrayCopy') ? $likesArray->getArrayCopy() : (array)$likesArray;
        } else {
            $likesArray = [];
        }
    }

    $existingLike = $db->ai_roadmap_likes->findOne([
        'roadmap_id' => $roadmapId,
        'username' => $currentUsername
    ]);

    $isCurrentlyLiked = ($existingLike || in_array($currentUsername, $likesArray));

    if ($isCurrentlyLiked) {
        $db->ai_roadmap_likes->deleteOne([
            'roadmap_id' => $roadmapId,
            'username' => $currentUsername
        ]);
        $db->ai_roadmaps->updateOne(
            ['_id' => $objectId],
            ['$pull' => ['likes' => $currentUsername]]
        );

        $likesCount = $db->ai_roadmap_likes->countDocuments(['roadmap_id' => $roadmapId]);
        $db->ai_roadmaps->updateOne(
            ['_id' => $objectId],
            ['$set' => ['likes_count' => $likesCount]]
        );

        echo json_encode([
            'result' => 'success', 'liked' => false, 'action' => 'unliked',
            'like_count' => $likesCount,
        ]);
    } else {
        $db->ai_roadmap_likes->insertOne([
            'roadmap_id' => $roadmapId, 'username' => $currentUsername,
            'user_id' => $currentUserId, 'liked_at' => date('c'),
        ]);
        $db->ai_roadmaps->updateOne(
            ['_id' => $objectId],
            ['$addToSet' => ['likes' => $currentUsername]]
        );

        $likesCount = $db->ai_roadmap_likes->countDocuments(['roadmap_id' => $roadmapId]);
        $db->ai_roadmaps->updateOne(
            ['_id' => $objectId],
            ['$set' => ['likes_count' => $likesCount]]
        );

        echo json_encode([
            'result' => 'success', 'liked' => true, 'action' => 'liked',
            'like_count' => $likesCount,
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process like: ' . $e->getMessage()]);
}
