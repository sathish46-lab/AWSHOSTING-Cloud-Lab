<?php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    Session::$pageTitle = "Network / Interfaces";
    Session::loadMaster();
    exit;
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

$port = get_config('wireguard_endpoint_port') ?? 51820;
$tunnelPrefix = get_config('tunnel_ip');
$baseSubnet = preg_replace('/\.0\.$/', '.0.0/16', $tunnelPrefix);

// Count peers from actual WireGuard config file
$wgConfPath = '/etc/wireguard/wg0.conf';
$peerCount = 0;
if (file_exists($wgConfPath)) {
    $wgConf = file_get_contents($wgConfPath);
    $peerCount = substr_count($wgConf, '[Peer]');
}

// Check real WireGuard interface status locally
$wgStatus = 'unknown';
$totalRx = 0;
$totalTx = 0;
$activePeers = 0;

$wgCheck = @shell_exec('sudo wg show wg0 2>&1');
if (strpos($wgCheck, 'Unable to access interface') !== false || strpos($wgCheck, 'No such device') !== false || empty(trim($wgCheck))) {
    $wgStatus = 'down';
} elseif (strpos($wgCheck, 'interface: wg0') !== false || strpos($wgCheck, 'public key') !== false) {
    $wgStatus = 'up';
    
    // Count peers
    if (preg_match_all('/peer:\s/', $wgCheck, $m)) {
        $activePeers = count($m[0]);
    }
    // Parse transfer: "transfer: 308 B received, 92 B sent"
    if (preg_match_all('/transfer:\s*([\d.]+\s*\w+)\s*received,\s*([\d.]+\s*\w+)\s*sent/', $wgCheck, $tm)) {
        foreach ($tm[1] as $rx) { $totalRx += parseBytes($rx); }
        foreach ($tm[2] as $tx) { $totalTx += parseBytes($tx); }
    }
} else {
    $wgStatus = 'unknown';
}

function parseBytes($str) {
    $str = trim($str);
    preg_match('/([\d.]+)\s*([BKMGT]?)/i', $str, $m);
    $val = floatval($m[1] ?? 0);
    $unit = strtoupper($m[2] ?? 'B');
    $map = ['B' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776];
    return $val * ($map[$unit] ?? 1);
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

$interfaces = [
    [
        'name' => 'wg0',
        'label' => 'Public network',
        'description' => 'Public network — everyone',
        'cidr' => $baseSubnet,
        'port' => $port,
        'status' => $wgStatus,
        'peers' => $activePeers ?: $peerCount,
        'active_peers' => $activePeers,
        'total_rx' => formatBytes($totalRx),
        'total_tx' => formatBytes($totalTx),
    ],
];

Session::$pageTitle = "Network / Interfaces";
Session::set('network_interfaces', $interfaces);
Session::loadMaster();
