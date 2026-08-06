<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();
$rawId = $_GET['id'] ?? '';

// Professional ID extraction
$deviceId = is_array($rawId) ? ($rawId['$oid'] ?? null) : $rawId;

if (!$deviceId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID format']);
    exit;
}

try {
    $device = $db->devices->findOne(['_id' => new MongoDB\BSON\ObjectId((string)$deviceId)]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Device ID']);
    exit;
}

if (!$device || (string)$device['user_id'] !== (string)Session::getUser()->getUserId()) {
    http_response_code(404);
    echo json_encode(['error' => 'Device not found or access denied']);
    exit;
}

// Build the Professional WireGuard Config
$serverPubKey = get_config('wireguard_public_key');
$port = get_config('wireguard_endpoint_port') ?? 51820;
$serverHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$inferredVpnDomain = str_replace(['labs.', 'dev.'], 'vpn.', $serverHost);
$endpointDomain = get_config('vpn_domain') ?: $inferredVpnDomain;
$endpoint = get_config('wireguard_endpoint') ?? "$endpointDomain:$port";

$config = "[Interface]\n";
$config .= "PrivateKey = " . ($device['private_key'] ?: '<PASTE_PRIVATE_KEY>') . "\n";
// The user gets an IP in the 172.30 range
$config .= "Address = " . $device['assigned_ip'] . "/32\n"; 
$config .= "DNS = 1.1.1.1\n\n";

$config .= "[Peer]\n";
$config .= "PublicKey = $serverPubKey\n";
$config .= "Endpoint = $endpoint\n";

// IMPORTANT: route the whole VPN range
$tunnelPrefix = get_config('tunnel_ip');
$baseSubnet = preg_replace('/\.0\.$/', '.0.0/16', $tunnelPrefix);
$config .= "AllowedIPs = $baseSubnet\n";
$config .= "PersistentKeepalive = 25\n";

// Clear output buffers to prevent corruption
if (ob_get_length()) ob_end_clean();

// Sanitize device name for Content-Disposition header (prevent CRLF injection)
$safeDeviceName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $device['device_name'] ?? 'vpn');

header('Content-Type: application/config'); // Professional WireGuard MIME
header('Content-Disposition: attachment; filename="' . $safeDeviceName . '.conf"');
header('Pragma: no-cache');
header('Expires: 0');

echo $config;
exit;
