<?php
/**
 * MCP Activity API
 * Lists tool-call activity for the authenticated user (paginated, filterable).
 * Data source: mcp_activity collection written by the Python MCP server.
 */

require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Pragma: no-cache');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'message' => 'Authentication required']);
    exit;
}

$user = Session::getUser();
$userId = $user->getUserId();

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$clientId = $_GET['client_id'] ?? '';
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$page = max(0, (int)($_GET['page'] ?? 0));
$skip = $page * $limit;

$filter = ['user_id' => $userId];
if (!empty($clientId)) {
    $filter['client_id'] = $clientId;
}

$db = MCPOAuth::getDb();

$total = $db->mcp_activity->countDocuments($filter);

$cursor = $db->mcp_activity->find(
    $filter,
    ['sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit]
);

$items = [];
foreach ($cursor as $a) {
    $clientName = null;
    $cid = $a['client_id'] ?? '';
    if (!empty($cid)) {
        $cDoc = $db->mcp_clients->findOne(['client_id' => $cid]);
        $clientName = $cDoc['client_name'] ?? null;
    }
    $items[] = [
        'id' => (string)$a['_id'],
        'client_id' => $cid,
        'client_name' => $clientName,
        'username' => $a['username'] ?? '',
        'email' => $a['email'] ?? '',
        'tool' => $a['tool'] ?? '',
        'status' => $a['status'] ?? 'ok',
        'duration_ms' => $a['duration_ms'] ?? 0,
        'error' => $a['error'] ?? null,
        'request' => $a['request'] ?? [],
        'response' => $a['response'] ?? [],
        'created_at' => isset($a['created_at']) && $a['created_at'] instanceof MongoDB\BSON\UTCDateTime
            ? $a['created_at']->toDateTime()->format('c')
            : '',
    ];
}

echo json_encode([
    'activity' => $items,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'has_more' => $skip + count($items) < $total,
]);
exit;