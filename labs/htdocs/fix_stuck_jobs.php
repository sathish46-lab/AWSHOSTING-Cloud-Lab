<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/utils/config.php';
require_once __DIR__ . '/src/lib/core/DatabaseConnection.class.php';

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
