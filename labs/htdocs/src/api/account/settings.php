<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername();
$email = $user->getEmail();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

// ── Platform Limits ──
$devices = $db->devices->countDocuments(['user_id' => $userId]);
$devicesLimit = 5;

$domainCount = $db->domains->countDocuments(['user_id' => ['\$in' => [(string)$userId, $userId]]]);
$domainsLimit = 20;

$activeLabs = $db->machine_labs->countDocuments(['user_id' => $userId, 'status' => 'running']);
$labsLimit = 5;

$labCopies = $db->instances->countDocuments(['user_id' => $userId]);
$copiesLimit = 4;

$deployments = $db->machine_labs->countDocuments(['user_id' => $userId]);
$deploymentsLimit = 3;

// ── Storage ──
$homePath = get_config('storage_base') . "/" . md5($user->getEmail());
$storageUsed = 0;
$storageLimitGb = 25;
if (is_dir($homePath)) {
    $output = [];
    exec("du -sb " . escapeshellarg($homePath) . " 2>/dev/null", $output, $ret);
    if ($ret === 0 && !empty($output[0])) {
        preg_match('/^(\d+)/', $output[0], $m);
        $storageUsed = (int)($m[1] ?? 0);
    }
}
$storageUsedGb = round($storageUsed / (1024 * 1024 * 1024), 2);
$storagePercent = min(100, round(($storageUsedGb / $storageLimitGb) * 100, 1));

// ── S3 Files size ──
$s3Bytes = 0;
try {
    $client = Storage::getClient();
    $config = get_config('s3');
    $prefix = "labassets/uploads/{$userId}/";
    $results = $client->listObjectsV2(['Bucket' => $config['bucket'], 'Prefix' => $prefix]);
    if (!empty($results['Contents'])) {
        foreach ($results['Contents'] as $item) {
            $s3Bytes += (int)$item['Size'];
        }
    }
} catch (Exception $e) {}
$s3Gb = round($s3Bytes / (1073741824), 2);

// ── Container layers (approximate from Docker) ──
$containerLayersGb = 0.01;
$containerCount = 0;
try {
    $dockerOutput = shell_exec("docker ps -q --filter 'label=user={$username}' 2>/dev/null");
    if ($dockerOutput) {
        $containerCount = substr_count(trim($dockerOutput), "\n") + 1;
    }
} catch (Exception $e) {}

// ── Services Usage ──
$services = [
    'total' => ['used' => 0, 'limit' => 15],
    'mysql' => ['used' => 0, 'limit' => 5, 'databases' => 0],
    'mariadb' => ['used' => 0, 'limit' => 5, 'databases' => 0],
    'postgresql' => ['used' => 0, 'limit' => 5, 'databases' => 0],
    'mongodb' => ['used' => 0, 'limit' => 5, 'databases' => 0],
    'rabbitmq' => ['used' => 0, 'limit' => 5, 'vhosts' => 0],
    'redis' => ['used' => 0, 'limit' => 1]
];

$mysqlUsers = $db->mysql_users->find(['user_id' => $userId])->toArray();
foreach ($mysqlUsers as $mu) {
    $dbs = $db->mysql_databases->countDocuments(['mysql_user_id' => (string)$mu['_id'], 'user_id' => $userId]);
    $services['mysql']['used'] += $dbs;
    $services['mysql']['databases'] += $dbs;
}
$services['total']['used'] += $services['mysql']['used'];

$mariadbUsers = $db->mariadb_users->find(['user_id' => $userId])->toArray();
foreach ($mariadbUsers as $mu) {
    $dbs = $db->mariadb_databases->countDocuments(['mariadb_user_id' => (string)$mu['_id'], 'user_id' => $userId]);
    $services['mariadb']['used'] += $dbs;
    $services['mariadb']['databases'] += $dbs;
}
$services['total']['used'] += $services['mariadb']['used'];

$pgUsers = $db->postgresql_users->find(['user_id' => $userId])->toArray();
foreach ($pgUsers as $pu) {
    $dbs = $db->postgresql_databases->countDocuments(['postgresql_user_id' => (string)$pu['_id'], 'user_id' => $userId]);
    $services['postgresql']['used'] += $dbs;
    $services['postgresql']['databases'] += $dbs;
}
$services['total']['used'] += $services['postgresql']['used'];

$mongoUsers = $db->mongodb_users->find(['user_id' => $userId])->toArray();
foreach ($mongoUsers as $mo) {
    $dbs = $db->mongodb_databases->countDocuments(['mongodb_user_id' => (string)$mo['_id'], 'user_id' => $userId]);
    $services['mongodb']['used'] += $dbs;
    $services['mongodb']['databases'] += $dbs;
}
$services['total']['used'] += $services['mongodb']['used'];

$rabbitUsers = $db->rabbitmq_users->find(['user_id' => $userId])->toArray();
foreach ($rabbitUsers as $ru) {
    $vhosts = $db->rabbitmq_vhosts->countDocuments(['rabbitmq_user_id' => (string)$ru['_id'], 'user_id' => $userId]);
    $services['rabbitmq']['used'] += $vhosts;
    $services['rabbitmq']['vhosts'] += $vhosts;
}
$services['total']['used'] += $services['rabbitmq']['used'];

$redisUsers = $db->redis_users->find(['user_id' => $userId])->toArray();
$services['redis']['used'] = count($redisUsers);
$services['total']['used'] += $services['redis']['used'];

// ── SSH Keys ──
$sshKeys = $db->ssh_keys->find(['user_id' => $userId, 'status' => ['$ne' => 'deleted']], ['sort' => ['created_at' => -1]])->toArray();
$platformKeys = [];
foreach ($sshKeys as $key) {
    $platformKeys[] = [
        'id' => (string)$key['_id'],
        'title' => $key['title'] ?? '',
        'fingerprint' => $key['fingerprint'] ?? '',
        'created_at' => $key['created_at'] ?? 0,
        'expires_at' => $key['expires_at'] ?? null
    ];
}

// ── Sessions ──
$userDoc = $db->users->findOne(['email' => $email]);
$sessionTokens = $userDoc['session_tokens'] ?? [];
$lastLogin = $userDoc['last_login'] ?? 0;
$currentToken = $_COOKIE['session_token'] ?? '';

$activeSessions = [];
$recentLogins = [];

// Build active sessions from stored tokens
foreach ($sessionTokens as $sess) {
    $token = $sess['token'] ?? $sess; // handle old string format
    $isCurrent = ($token === $currentToken);
    $activeSessions[] = [
        'token_prefix' => $isCurrent ? substr($token, 0, 8) : substr($token, 0, 8),
        'is_current' => $isCurrent,
        'ip' => $sess['ip'] ?? '',
        'browser' => $sess['browser'] ?? 'Unknown',
        'os' => $sess['os'] ?? 'Unknown',
        'mobile' => $sess['mobile'] ?? false,
        'last_activity' => $sess['last_activity'] ?? $sess['created_at'] ?? time(),
        'created_at' => $sess['created_at'] ?? time()
    ];
}

// If no stored tokens but user is authenticated, show current session
if (empty($activeSessions) && $currentToken) {
    $deviceInfo = parse_user_agent();
    $activeSessions[] = [
        'token_prefix' => substr($currentToken, 0, 8),
        'is_current' => true,
        'ip' => get_client_ip(),
        'browser' => $deviceInfo['browser'],
        'os' => $deviceInfo['os'],
        'mobile' => $deviceInfo['mobile'],
        'last_activity' => time(),
        'created_at' => time()
    ];
} elseif (empty($activeSessions)) {
    // Fallback: user is authenticated via session but no token cookie
    $deviceInfo = parse_user_agent();
    $activeSessions[] = [
        'token_prefix' => 'current',
        'is_current' => true,
        'ip' => get_client_ip(),
        'browser' => $deviceInfo['browser'],
        'os' => $deviceInfo['os'],
        'mobile' => $deviceInfo['mobile'],
        'last_activity' => time(),
        'created_at' => time()
    ];
}
if ($lastLogin) {
    $recentLogins[] = [
        'time' => $lastLogin,
        'formatted' => date('M j, Y', $lastLogin) . ' · ' . date('g:i A', $lastLogin)
    ];
}

// ── Response ──
echo json_encode([
    'success' => true,
    'two_factor_enabled' => !empty($userDoc['two_factor_enabled']),
    'limits' => [
        'devices' => ['used' => $devices, 'limit' => $devicesLimit],
        'domains' => ['used' => $domainCount, 'limit' => $domainsLimit],
        'labs' => ['used' => $activeLabs, 'limit' => $labsLimit],
        'copies' => ['used' => $labCopies, 'limit' => $copiesLimit],
        'deployments' => ['used' => $deployments, 'limit' => $deploymentsLimit]
    ],
    'storage' => [
        'home' => [
            'used_gb' => $storageUsedGb,
            'limit_gb' => $storageLimitGb,
            'percent' => $storagePercent,
            'enforced' => true
        ],
        's3' => ['used_gb' => $s3Gb],
        'containers' => ['used_gb' => $containerLayersGb, 'count' => $containerCount],
        'pool' => ['id' => 'default', 'fs' => 'xfs']
    ],
    'services' => $services,
    'ssh_keys' => $platformKeys,
    'sessions' => [
        'active' => $activeSessions,
        'recent_logins' => $recentLogins
    ]
]);
