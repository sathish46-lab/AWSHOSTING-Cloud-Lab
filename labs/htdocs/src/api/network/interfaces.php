<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    
    // Get WireGuard server config
    $port = get_config('wireguard_endpoint_port') ?? 51820;
    $tunnelPrefix = get_config('tunnel_ip');
    $baseSubnet = preg_replace('/\.0\.$/', '.0.0/16', $tunnelPrefix);
    
    // Get total peer count (active devices across all users)
    $peerCount = $db->devices->countDocuments(['status' => 'active']);
    
    // Check real WireGuard interface status locally
    $wgStatus = 'unknown';
    $activePeers = 0;
    $totalRx = 0;
    $totalTx = 0;
    
    $wgCheck = @shell_exec('sudo wg show wg0 2>&1');
    if (strpos($wgCheck, 'Unable to access interface') !== false || strpos($wgCheck, 'No such device') !== false || empty(trim($wgCheck))) {
        $wgStatus = 'down';
    } elseif (strpos($wgCheck, 'interface: wg0') !== false || strpos($wgCheck, 'public key') !== false) {
        $wgStatus = 'up';
        
        // Parse peer info from wg show output
        if (preg_match_all('/peer:\s*(.+?)$/m', $wgCheck, $peerMatches)) {
            $activePeers = count($peerMatches[1]);
        }
        // Parse transfer stats
        if (preg_match_all('/transfer:\s*([\d.]+\s*\w+)\s*received,\s*([\d.]+\s*\w+)\s*sent/', $wgCheck, $transferMatches)) {
            foreach ($transferMatches[1] as $rx) { $totalRx += parseBytes($rx); }
            foreach ($transferMatches[2] as $tx) { $totalTx += parseBytes($tx); }
        }
    } else {
        $wgStatus = 'unknown';
    }
    
    // Only the server's actual WireGuard interface
    $interfaces = [
        [
            'name' => 'wg0',
            'label' => 'Public network',
            'description' => 'Public network — everyone',
            'cidr' => $baseSubnet,
            'port' => $port,
            'status' => $wgStatus,
            'peers' => $peerCount,
            'active_peers' => $activePeers,
            'total_rx' => formatBytes($totalRx),
            'total_tx' => formatBytes($totalTx),
        ],
    ];
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'interfaces' => $interfaces,
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}

function parseBytes($str) {
    $str = trim($str);
    preg_match('/([\d.]+)\s*([BKMGT]?)/i', $str, $m);
    $val = floatval($m[1] ?? 0);
    $unit = strtoupper($m[2] ?? 'B');
    $multipliers = ['B' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776];
    return $val * ($multipliers[$unit] ?? 1);
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}
