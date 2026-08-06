<?php
/**
 * Dashboard Statistics API
 * Returns active labs, domain counts, and recent activity for the current user
 */
require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');

// 1. Validate Session
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

// 2. Get active labs count
$activeLabs = $db->machine_labs->countDocuments([
    'user_id' => $userId,
    'status' => 'running'
]);

$labsLimit = 5; 

// 3. Get domain count
$domainCount = $db->domains->countDocuments([
    'user_id' => (string)$userId 
]);

if ($domainCount === 0) {
    $domainCount = $db->domains->countDocuments([
        'user_id' => $userId
    ]);
}

$domainsLimit = 10;

// 4. Get ALL deployed labs
$deployedLabs = $db->machine_labs->find(
    ['user_id' => $userId],
    ['sort' => ['created_at' => -1]]
);

$cacheFile = '/dev/shm/docker_stats.json';
$allStats = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];

$labsList = [];
foreach ($deployedLabs as $lab) {
    $hash = $lab['instance_hash'] ?? '';
    
    // Retrieve real-time metrics for this specific lab from memory cache
    $metrics = ['status' => 'offline'];
    if (isset($allStats[$hash])) {
        $metrics = $allStats[$hash];
    } else if (isset($allStats['ctf-' . $hash])) {
        $metrics = $allStats['ctf-' . $hash];
    }

    $labsList[] = [
        'name' => ucfirst($lab['lab_type'] ?? 'Lab'),
        'ip' => $lab['internal_ip'] ?? 'Unknown',
        'status' => $lab['status'] ?? 'unknown',
        'hash' => $hash,
        'type' => $lab['lab_type'] ?? 'unknown',
        'metrics' => $metrics
    ];
}

// 5. Get ALL domains
$domains = $db->domains->find(
    ['user_id' => ['$in' => [(string)$userId, $userId]], 'status' => ['$ne' => 'deleted']], // Check both string and int IDs
    ['sort' => ['created_at' => -1]]
);

$domainsList = [];
foreach ($domains as $domain) {
    $domainsList[] = [
        'domain' => $domain['domain'],
        'ip' => $domain['last_ip'] ?? 'Pending'
    ];
}

echo json_encode([
    'labs' => [
        'active' => $activeLabs,
        'limit' => $labsLimit,
        'all_labs' => $labsList
    ],
    'domains' => [
        'count' => $domainCount,
        'limit' => $domainsLimit,
        'all_domains' => $domainsList
    ]
]);
