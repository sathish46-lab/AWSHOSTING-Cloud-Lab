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

Session::$pageTitle = "Network / Interfaces";
Session::set('network_interfaces', $interfaces);
Session::loadMaster();
