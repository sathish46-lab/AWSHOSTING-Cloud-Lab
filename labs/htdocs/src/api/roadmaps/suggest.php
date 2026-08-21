<?php
/**
 * Roadmaps - Search Suggestions API
 * Returns raw HTML suggestions (like Learn AI search.php)
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';

header('Content-Type: text/html; charset=utf-8');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    exit;
}

$query = trim($_POST['query'] ?? $_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo '<div class="text-center py-4"><h6 class="text-secondary fw-semibold" style="font-size:0.9rem;">Type to search your roadmaps</h6></div>';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$db = DatabaseConnection::getDefaultDatabase();

// Build keyword search (same logic as Learn AI)
$words = preg_split('/\s+/', $query);
$words = array_filter($words, function($w) { return strlen($w) >= 2; });
$stopWords = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'are', 'want', 'learn', 'teach', 'how', 'can', 'about', 'cover', 'like', 'using', 'give', 'roadmap'];
$words = array_filter($words, function($w) use ($stopWords) { return !in_array(strtolower($w), $stopWords); });
$words = array_values(array_slice($words, 0, 6));

if (empty($words)) {
    echo '<div class="text-center py-4"><h6 class="text-secondary fw-semibold" style="font-size:0.9rem;">No roadmaps found</h6></div>';
    exit;
}

$andConditions = [];
foreach ($words as $w) {
    $safeWord = preg_quote($w);
    $andConditions[] = [
        '$or' => [
            ['title' => new MongoDB\BSON\Regex($safeWord, 'i')],
            ['prompt' => new MongoDB\BSON\Regex($safeWord, 'i')],
            ['tags' => new MongoDB\BSON\Regex($safeWord, 'i')],
        ]
    ];
}

$filter = count($andConditions) === 1 ? $andConditions[0] : ['$and' => $andConditions];

// Show user's own + public
$filter = ['$and' => [$filter, ['$or' => [['user_id' => $userId], ['visibility' => 'public']]]]];

$roadmaps = $db->ai_roadmaps->find($filter, ['limit' => 10, 'sort' => ['created_at' => -1]])->toArray();

// Render using partial
include __DIR__ . '/../../template/partials/roadmaps/suggestions.php';
