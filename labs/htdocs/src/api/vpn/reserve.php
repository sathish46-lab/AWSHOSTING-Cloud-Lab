<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['result' => false]); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ip = $data['ip'] ?? null;

if (!$ip) {
    echo json_encode(['result' => false]); exit;
}

$db = DatabaseConnection::getDefaultDatabase();
$user = Session::getUser();

try {
    $db->ip_registry->updateOne(
        ['ip_addr' => $ip, 'status' => 'available'],
        ['$set' => ['status' => 'reserved', 'email' => $user->getEmail(), 'user_id' => $user->getUserId(), 'reserved_at' => time()]]
    );
    echo json_encode(['result' => true]);
} catch (Exception $e) {
    echo json_encode(['result' => false]);
}
