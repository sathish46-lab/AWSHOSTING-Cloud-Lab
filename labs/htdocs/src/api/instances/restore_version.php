<?php
require_once __DIR__ . '/../../../src/load.php';

use MongoDB\BSON\ObjectId;

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$hash = $_POST['hash'] ?? '';
$versionId = $_POST['version_id'] ?? '';

if (empty($hash) || empty($versionId)) {
    echo json_encode(['status' => 'error', 'error' => 'Missing hash or version_id']); exit;
}

try {
    $instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');

    $version = $instDb->instance_versions->findOne([
        '_id' => new ObjectId($versionId),
        'instance_hash' => $hash
    ]);

    if (!$version) {
        echo json_encode(['status' => 'error', 'error' => 'Version not found']); exit;
    }

    $filesDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
    $filesCol = $filesDb->instance_files;

    $snapshot = $version['files_snapshot'];
    if ($snapshot instanceof MongoDB\Model\BSONDocument) {
        $snapshot = $snapshot->getArrayCopy();
    } elseif (is_object($snapshot)) {
        $snapshot = (array)$snapshot;
    }

    $filesCol->updateOne(
        ['instance_id' => $hash],
        ['$set' => ['files' => $snapshot, 'updated_at' => new MongoDB\BSON\UTCDateTime()]],
        ['upsert' => true]
    );

    $configSnap = $version['config_snapshot'];
    if ($configSnap instanceof MongoDB\Model\BSONDocument) {
        $configSnap = $configSnap->getArrayCopy();
    } elseif (is_object($configSnap)) {
        $configSnap = (array)$configSnap;
    }

    $instDb->instances->updateOne(
        ['instance_hash' => $hash],
        ['$set' => array_merge($configSnap, ['updated_at' => time()])]
    );

    echo json_encode(['status' => 'success', 'message' => 'Version restored']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
