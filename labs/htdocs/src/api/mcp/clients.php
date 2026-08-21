<?php
/**
 * MCP Client Management API
 * Lists and manages MCP clients for the authenticated user
 */

require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');

// Check authentication
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'message' => 'Authentication required']);
    exit;
}

$user = Session::getUser();
$userId = $user->getUserId();

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // List MCP clients for current user.
    // Dynamic clients registered pre-login have user_id=null, so also derive
    // "connected" clients from activity/tokens that reference this user.
    $db = MCPOAuth::getDb();

    $clients = MCPOAuth::getUserClients($userId);

    $connected = [];
    $seen = [];

    // 1. Clients explicitly created for this user
    foreach ($clients as $c) {
        $cid = $c['client_id'];
        $seen[$cid] = true;
        $connected[$cid] = [
            'client_id' => $cid,
            'client_name' => $c['client_name'] ?? 'Unknown Client',
            'redirect_uris' => $c['redirect_uris'] ?? [],
            'scopes' => $c['scopes'] ?? ['labs:*'],
            'connected_at' => $c['created_at'] && $c['created_at'] instanceof MongoDB\BSON\UTCDateTime
                ? $c['created_at']->toDateTime()->format('c')
                : '',
            'revoked' => $c['revoked'] ?? false,
            'connected' => false,
            'token_count' => 0,
            'request_count' => 0,
            'failed_count' => 0,
            'last_used_at' => $c['last_used_at'] && $c['last_used_at'] instanceof MongoDB\BSON\UTCDateTime
                ? $c['last_used_at']->toDateTime()->format('c')
                : '',
        ];
    }

    // 2. Clients found via mcp_activity for this user (dynamic registrations)
    $actClients = $db->mcp_activity->distinct('client_id', ['user_id' => $userId, 'client_id' => ['$ne' => '']]);
    foreach ($actClients as $cid) {
        if (isset($seen[$cid])) continue;
        $doc = $db->mcp_clients->findOne(['client_id' => $cid, 'revoked' => ['$ne' => true]]);
        if (empty($doc) || ($doc['auto'] ?? false)) {
            $seen[$cid] = true;
            continue;
        }
        $seen[$cid] = true;
        $connected[$cid] = [
            'client_id' => $cid,
            'client_name' => $doc['client_name'] ?? 'MCP Client',
            'redirect_uris' => $doc['redirect_uris'] ?? [],
            'scopes' => $doc['scopes'] ?? ['labs:*'],
            'connected_at' => $doc['created_at'] && $doc['created_at'] instanceof MongoDB\BSON\UTCDateTime
                ? $doc['created_at']->toDateTime()->format('c')
                : '',
            'revoked' => $doc['revoked'] ?? false,
            'connected' => false,
            'token_count' => 0,
            'request_count' => 0,
            'failed_count' => 0,
            'last_used_at' => '',
        ];
    }

    // 3. Clients referenced by live tokens for this user
    $tokClients = $db->mcp_tokens->distinct('client_id', ['user_id' => $userId, 'client_id' => ['$ne' => '']]);
    foreach ($tokClients as $cid) {
        if (isset($seen[$cid])) continue;
        $doc = $db->mcp_clients->findOne(['client_id' => $cid, 'revoked' => ['$ne' => true]]);
        if (empty($doc) || ($doc['auto'] ?? false)) {
            $seen[$cid] = true;
            continue;
        }
        $seen[$cid] = true;
        $connected[$cid] = [
            'client_id' => $cid,
            'client_name' => $doc['client_name'] ?? 'MCP Client',
            'redirect_uris' => $doc['redirect_uris'] ?? [],
            'scopes' => $doc['scopes'] ?? ['labs:*'],
            'connected_at' => $doc['created_at'] && $doc['created_at'] instanceof MongoDB\BSON\UTCDateTime
                ? $doc['created_at']->toDateTime()->format('c')
                : '',
            'revoked' => $doc['revoked'] ?? false,
            'connected' => false,
            'token_count' => 0,
            'request_count' => 0,
            'failed_count' => 0,
            'last_used_at' => '',
        ];
    }

    // 4. Connected = client currently has an open MCP SSE stream
    $connRows = $db->mcp_connections->find(['connected' => true]);
    foreach ($connRows as $row) {
        $cid = $row['client_id'] ?? '';
        if (!$cid || !isset($connected[$cid])) continue;
        $connected[$cid]['connected'] = true;
    }

    // Aggregates: request count + last-used per client
    $agg = $db->mcp_activity->aggregate([
        ['$match' => ['user_id' => $userId]],
        ['$group' => [
            '_id' => '$client_id',
            'count' => ['$sum' => 1],
            'last' => ['$max' => '$created_at'],
            'failures' => [
                '$sum' => ['$cond' => [['$eq' => ['$status', 'error']], 1, 0]]
            ]
        ]]
    ]);
    foreach ($agg as $row) {
        $cid = $row['_id'];
        if (!$cid || !isset($connected[$cid])) continue;
        $connected[$cid]['request_count'] = $row['count'] ?? 0;
        $connected[$cid]['failed_count'] = $row['failures'] ?? 0;
        if (!empty($row['last'])) {
            $connected[$cid]['last_used_at'] = $row['last'] instanceof MongoDB\BSON\UTCDateTime
                ? $row['last']->toDateTime()->format('c')
                : $connected[$cid]['last_used_at'];
        }
    }

    $list = array_values($connected);
    usort($list, function($a, $b) {
        return strcmp($b['last_used_at'], $a['last_used_at']);
    });

    echo json_encode([
        'clients' => $list,
        'count' => count($list),
        'live_count' => count(array_filter($list, function($c) {
            return !empty($c['connected']);
        }))
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Revoke MCP client
    $input = json_decode(file_get_contents('php://input'), true);
    $clientId = $input['client_id'] ?? '';

    if (empty($clientId)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'message' => 'client_id required']);
        exit;
    }

    // Validate CSRF
    if (!Session::validateCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'message' => 'Invalid CSRF token']);
        exit;
    }

    $revoked = MCPOAuth::revokeClient($clientId, $userId);

    echo json_encode(['revoked' => $revoked]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);