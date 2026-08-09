<?php
/**
 * Activity Feed API — Returns paginated audit log entries for the authenticated user.
 * 
 * Security:
 * - Every query scoped to authenticated user's user_id (never from request params)
 * - action/entity_type filters validated against allowlist
 * - Only safe fields returned: action, entity_type, entity_id, details, ip_address, created_at
 * - No _id fields, no internal paths, no other users' data
 */
require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (string)$user->getUserId();
$userEmail = $user->getEmail();

// Allowlist: only these action types are valid filter values
$validActions = ['create', 'update', 'delete', 'trash', 'restore', 'permanent_delete', 'change_password'];
$validEntityTypes = ['instance', 'vpn_device', 'service_mysql', 'user'];

// Parse and validate filter params
$actionFilter = $_GET['action'] ?? null;
$entityTypeFilter = $_GET['entity_type'] ?? null;

if ($actionFilter !== null && !in_array($actionFilter, $validActions, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid action filter']);
    exit;
}

if ($entityTypeFilter !== null && !in_array($entityTypeFilter, $validEntityTypes, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid entity_type filter']);
    exit;
}

// Parse pagination params (clamp to sane limits)
$maxLimit = 100;
$limit = min(max((int)($_GET['limit'] ?? 50), 1), $maxLimit);
$offset = max((int)($_GET['offset'] ?? 0), 0);

try {
    $db = DatabaseConnection::getDefaultDatabase();

    // Build query — ALWAYS scope to current user
    $filter = [
        'user_id' => ['$in' => [$userId, (int)$userId]]
    ];

    if ($actionFilter !== null) {
        $filter['action'] = $actionFilter;
    }

    if ($entityTypeFilter !== null) {
        $filter['entity_type'] = $entityTypeFilter;
    }

    // Get total count for pagination
    $total = $db->audit_log->countDocuments($filter);

    // Fetch entries — sorted newest first
    $cursor = $db->audit_log->find($filter, [
        'sort' => ['created_at' => -1],
        'skip' => $offset,
        'limit' => $limit,
    ]);

    $entries = [];
    foreach ($cursor as $doc) {
        $createdAt = $doc['created_at'] ?? null;
        // Convert MongoDB\BSON\UTCDateTime to unix timestamp (milliseconds for JS)
        if ($createdAt instanceof \MongoDB\BSON\UTCDateTime) {
            $createdAt = (int)$createdAt->toDateTime()->format('U') * 1000;
        }

        $entries[] = [
            'action' => $doc['action'] ?? '',
            'entity_type' => $doc['entity_type'] ?? '',
            'entity_id' => $doc['entity_id'] ?? null,
            'details' => $doc['details'] ?? [],
            'ip_address' => $doc['ip_address'] ?? '',
            'created_at' => $createdAt,
        ];
    }

    // Summary counts (all-time, current user only)
    $summary = [];
    foreach ($validActions as $act) {
        $summary[$act] = $db->audit_log->countDocuments([
            'user_id' => ['$in' => [$userId, (int)$userId]],
            'action' => $act
        ]);
    }

    echo json_encode([
        'status' => 'success',
        'entries' => $entries,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'summary' => $summary,
    ]);

} catch (\Throwable $e) {
    error_log("Activity feed error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Failed to load activity feed']);
}
