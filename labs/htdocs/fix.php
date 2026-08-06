<?php
require_once __DIR__ . "/src/load.php";

// S4: Require superuser authentication
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$user = Session::getUser();
if (!$user || $user->getRole() !== 'superuser') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: superuser required']);
    exit;
}

// Log the action
error_log("ADMIN ACTION: fix.php called by {$user->getEmail()} ({$user->getUserId()})");

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$result = $db->machine_labs->deleteMany(["deploy.lab_type" => ['$exists' => false]]);
echo "Deleted: " . $result->getDeletedCount();
