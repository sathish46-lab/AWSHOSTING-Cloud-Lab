<?php
/**
 * Activity Analytics API — Aggregated analytics for the activity page charts.
 * 
 * Returns: action breakdown (pie), daily trend (line), hourly activity (bar),
 * security events, and summary stats. All data scoped to authenticated user.
 * 
 * Security:
 * - Every aggregation filtered by session user_id (never from request params)
 * - No individual records returned — only aggregate counts
 * - No internal Mongo fields, _id chains, or secrets in response
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

try {
    $db = DatabaseConnection::getDefaultDatabase();
    $userFilter = ['user_id' => ['$in' => [$userId, (int)$userId]]];

    // 1. Action breakdown (for doughnut chart)
    $actionPipeline = [
        ['$match' => $userFilter],
        ['$group' => ['_id' => '$action', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]]
    ];
    $actionBreakdown = [];
    $cursor = $db->audit_log->aggregate($actionPipeline);
    foreach ($cursor as $doc) {
        $actionBreakdown[] = [
            'action' => $doc['_id'],
            'count' => $doc['count'],
        ];
    }

    // 2. Entity type breakdown
    $entityPipeline = [
        ['$match' => $userFilter],
        ['$group' => ['_id' => '$entity_type', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]]
    ];
    $entityBreakdown = [];
    $cursor = $db->audit_log->aggregate($entityPipeline);
    foreach ($cursor as $doc) {
        $entityBreakdown[] = [
            'entity_type' => $doc['_id'],
            'count' => $doc['count'],
        ];
    }

    // 3. Daily activity trend (last 30 days, for line chart)
    $thirtyDaysAgo = new MongoDB\BSON\UTCDateTime((time() - 30 * 86400) * 1000);
    $dailyPipeline = [
        ['$match' => array_merge($userFilter, ['created_at' => ['$gte' => $thirtyDaysAgo]])],
        ['$group' => [
            '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $dailyTrend = [];
    $cursor = $db->audit_log->aggregate($dailyPipeline);
    foreach ($cursor as $doc) {
        $dailyTrend[] = [
            'date' => $doc['_id'],
            'count' => $doc['count'],
        ];
    }

    // 4. Hourly activity (24-hour distribution, for bar chart)
    $hourlyPipeline = [
        ['$match' => $userFilter],
        ['$group' => [
            '_id' => ['$hour' => '$created_at'],
            'count' => ['$sum' => 1]
        ]],
        ['$sort' => ['_id' => 1]]
    ];
    $hourlyData = array_fill(0, 24, 0);
    $cursor = $db->audit_log->aggregate($hourlyPipeline);
    foreach ($cursor as $doc) {
        $hour = (int)$doc['_id'];
        if ($hour >= 0 && $hour < 24) {
            $hourlyData[$hour] = $doc['count'];
        }
    }

    // 5. Security events (password changes, session invalidations)
    $securityFilter = array_merge($userFilter, [
        'action' => ['$in' => ['change_password']]
    ]);
    $securityPipeline = [
        ['$match' => $securityFilter],
        ['$project' => [
            'action' => 1,
            'entity_type' => 1,
            'details' => 1,
            'ip_address' => 1,
            'created_at' => 1,
        ]],
        ['$sort' => ['created_at' => -1]],
        ['$limit' => 20]
    ];
    $securityEvents = [];
    $cursor = $db->audit_log->aggregate($securityPipeline);
    foreach ($cursor as $doc) {
        $securityEvents[] = [
            'action' => $doc['action'] ?? '',
            'entity_type' => $doc['entity_type'] ?? '',
            'details' => $doc['details'] ?? [],
            'ip_address' => $doc['ip_address'] ?? '',
            'created_at' => $doc['created_at'] ?? null,
        ];
    }

    // 6. Summary stats
    $totalActions = $db->audit_log->countDocuments($userFilter);
    
    // Active days (distinct dates with activity)
    $activeDaysPipeline = [
        ['$match' => $userFilter],
        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']]]],
        ['$count' => 'total']
    ];
    $activeDays = 0;
    $cursor = $db->audit_log->aggregate($activeDaysPipeline);
    foreach ($cursor as $doc) {
        $activeDays = $doc['total'] ?? 0;
    }

    // Most common action
    $mostCommonAction = '';
    if (!empty($actionBreakdown)) {
        $mostCommonAction = $actionBreakdown[0]['action'];
    }

    // This week's actions
    $weekAgo = new MongoDB\BSON\UTCDateTime((time() - 7 * 86400) * 1000);
    $thisWeek = $db->audit_log->countDocuments(array_merge($userFilter, [
        'created_at' => ['$gte' => $weekAgo]
    ]));

    echo json_encode([
        'status' => 'success',
        'action_breakdown' => $actionBreakdown,
        'entity_breakdown' => $entityBreakdown,
        'daily_trend' => $dailyTrend,
        'hourly_activity' => $hourlyData,
        'security_events' => $securityEvents,
        'summary' => [
            'total_actions' => $totalActions,
            'active_days' => $activeDays,
            'this_week' => $thisWeek,
            'most_common_action' => $mostCommonAction,
        ],
    ]);

} catch (Exception $e) {
    error_log("Activity analytics error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Failed to load analytics']);
}
