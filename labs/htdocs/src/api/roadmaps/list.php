<?php
/**
 * Roadmaps - List Roadmaps API
 * Security: Auth required, shows user's own + public roadmaps
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
$filter = trim($_GET['filter'] ?? 'all'); // all | mine | public
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$skip = ($page - 1) * $limit;

$validFilters = ['all', 'mine', 'public'];
if (!in_array($filter, $validFilters)) {
    $filter = 'all';
}

$db = DatabaseConnection::getDefaultDatabase();

// ── BUILD QUERY (user-scoped) ──
switch ($filter) {
    case 'mine':
        $query = ['user_id' => $userId];
        break;
    case 'public':
        $query = ['visibility' => 'public'];
        break;
    default: // all
        $query = [
            '$or' => [
                ['user_id' => $userId],
                ['visibility' => 'public'],
            ]
        ];
}

// ── SEARCH BY TITLE OR PROMPT ──
if (!empty($search)) {
    $escaped = preg_quote($search, '/');
    $query['$and'] = $query['$and'] ?? [];
    $query['$and'][] = [
        '$or' => [
            ['title' => ['$regex' => $escaped, '$options' => 'i']],
            ['prompt' => ['$regex' => $escaped, '$options' => 'i']],
            ['tags' => ['$regex' => $escaped, '$options' => 'i']],
        ]
    ];
}

$total = $db->ai_roadmaps->countDocuments($query);

$cursor = $db->ai_roadmaps->find($query, [
    'sort' => ['created_at' => -1],
    'skip' => $skip,
    'limit' => $limit,
    'projection' => [
        'sections' => 0,
    ]
]);

$roadmaps = [];
foreach ($cursor as $doc) {
    $roadmaps[] = [
        'id' => (string)$doc['_id'],
        'slug' => $doc['slug'],
        'title' => $doc['title'],
        'description' => $doc['description'] ?? '',
        'prompt' => $doc['prompt'] ?? '',
        'level' => $doc['level'] ?? 'Beginner',
        'hours' => $doc['hours'] ?? 0,
        'tags' => $doc['tags'] ?? [],
        'visibility' => $doc['visibility'] ?? 'public',
        'author' => $doc['author'] ?? '',
        'user_id' => $doc['user_id'],
        'is_owner' => ($doc['user_id'] === $userId),
        'progress' => $doc['progress'] ?? 0,
        'checkpoints_total' => $doc['checkpoints_total'] ?? 0,
        'checkpoints_completed' => $doc['checkpoints_completed'] ?? 0,
        'created_at' => $doc['created_at'] ?? null,
    ];
}

echo json_encode([
    'roadmaps' => $roadmaps,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'pages' => (int)ceil($total / $limit),
]);
