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
    
    // Only the server's actual WireGuard interface
    $interfaces = [
        [
            'name' => 'wg0',
            'label' => 'Public network',
            'description' => 'Public network — everyone',
            'cidr' => $baseSubnet,
            'port' => $port,
            'status' => 'provisioned',
            'peers' => $peerCount,
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
