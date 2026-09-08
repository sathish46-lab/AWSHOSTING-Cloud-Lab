<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/utils/config.php';
require_once __DIR__ . '/src/lib/core/DatabaseConnection.class.php';
require_once __DIR__ . '/src/lib/core/Session.class.php';
require_once __DIR__ . '/src/lib/core/Constants.class.php';

// Auth check — only superusers can run this
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
$user = Session::getUser();
if ($user->getRole() !== 'superuser') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: superuser only']);
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

// Reset all stuck running/streaming jobs
$result = $db->ai_roadmap_jobs->updateMany(
    ['status' => ['$in' => ['running', 'streaming']]],
    ['$set' => ['status' => 'failed', 'error_message' => 'Reset - stale job']]
);
echo "Reset " . $result->getModifiedCount() . " stuck jobs\n";

// Show remaining
$count = $db->ai_roadmap_jobs->countDocuments(['status' => ['$in' => ['running', 'streaming']]]);
echo "Remaining running/streaming: " . $count . "\n";
