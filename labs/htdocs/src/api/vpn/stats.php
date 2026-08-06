<?php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../../src/lib/core/VPN.class.php';
header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

$myDevices = $db->devices->find(['user_id' => $user->getUserId(), 'status' => ['$ne' => 'deleted']])->toArray();
$myKeys = array_column($myDevices, 'public_key');

$apiResponse = VPN::request('wg', 'get_peers', ['device' => 'wg0']);
$allPeers = $apiResponse['peers'] ?? [];

$results = [];
foreach ($allPeers as $peer) {
    if (!in_array($peer['peer'], $myKeys)) continue;

    $handshakeStr = $peer['latest handshake'] ?? '';
    $status = "Offline"; $color = "danger";

    if (!empty($handshakeStr)) {
        // Convert "X minutes, Y seconds ago" to a comparable timestamp
        $seentime = strtotime("-" . str_replace(" ago", "", $handshakeStr));
        $diff = time() - $seentime;

        if ($diff < 120) { // Less than 2 minutes
            $status = "Online"; $color = "success";
        } else if ($diff < 180) { // Between 2 and 10 minutes
            $status = "Unreachable"; $color = "warning";
        } else {
            $status = "Offline"; $color = "danger";
        }
    }

    $transfer = explode(',', $peer['transfer'] ?? '0 B received, 0 B sent');
    $results[] = [
        "id" => $peer['peer'], 
        "status" => $status, // Sentence case: Online/Offline
        "color" => $color,
        "rx" => trim(str_replace('received', '', $transfer[0])),
        "tx" => isset($transfer[1]) ? trim(str_replace('sent', '', $transfer[1])) : '0 B',
        "origin" => explode(':', $peer['endpoint'] ?? 'No Connection')[0]
    ];
}
echo json_encode($results);
