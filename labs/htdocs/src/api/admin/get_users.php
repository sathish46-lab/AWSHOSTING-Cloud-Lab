<?php
require_once __DIR__ . '/../../load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
if ($user->getRole() !== 'superuser') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;

$query = [];
if (!empty($search)) {
    // Escape regex special characters to prevent NoSQL injection
    $escapedSearch = preg_quote($search, '/');
    $query = [
        'email' => ['$regex' => $escapedSearch, '$options' => 'i']
    ];
}

$options = [
    'sort' => ['last_login' => -1],
    'skip' => $skip,
    'limit' => $limit
];

$usersCursor = $db->users->find($query, $options);
$users = [];

foreach ($usersCursor as $u) {
    $uObj = new User($u['email']);
    $email = $u['email'];
    
    // Generate Gravatar
    $gravatarHash = md5(strtolower(trim($email)));
    $avatar = $u['avatar'] ?? "https://www.gravatar.com/avatar/{$gravatarHash}?d=identicon&s=150";
    
    $lastLoginTs = isset($u['last_login']) ? (is_numeric($u['last_login']) ? (int)$u['last_login'] : strtotime($u['last_login'])) : 0;
    $createdTs = isset($u['created_at']) ? (is_numeric($u['created_at']) ? (int)$u['created_at'] : strtotime($u['created_at'])) : 0;
    
    $users[] = [
        'email' => $email,
        'name' => $uObj->getFullName() ?? 'Unknown',
        'avatar' => $avatar,
        'role' => $u['role'] ?? 'user',
        'quizzes_count' => isset($u['quizzes_completed']) ? count($u['quizzes_completed']) : 0,
        'last_login' => $lastLoginTs ? date('M j, Y h:i A', $lastLoginTs) : 'Never',
        'created_at' => $createdTs ? date('M j, Y', $createdTs) : 'Unknown'
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'data' => $users,
    'has_more' => count($users) === $limit
]);
