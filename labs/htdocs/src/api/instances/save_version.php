<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$hash = $_POST['hash'] ?? '';
$label = $_POST['label'] ?? 'untitled';

if (empty($hash)) {
    echo json_encode(['status' => 'error', 'error' => 'Missing hash']); exit;
}

try {
    $instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
    $instance = $instDb->instances->findOne([
        'instance_hash' => $hash,
        'user_id' => $user->getUserId()
    ]);

    if (!$instance) {
        echo json_encode(['status' => 'error', 'error' => 'Instance not found']); exit;
    }

    $filesDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
    $fileDoc = $filesDb->instance_files->findOne(['instance_id' => $hash]);

    $instDb->instance_versions->insertOne([
        'instance_hash'  => $hash,
        'label'          => preg_replace('/[^a-zA-Z0-9._-]/', '', $label),
        'files_snapshot' => $fileDoc['files'] ?? new MongoDB\BSON\Document(),
        'config_snapshot' => [
            'name'        => $instance['name'] ?? '',
            'template'    => $instance['template'] ?? 'essentials',
            'image'       => $instance['image'] ?? 'ubuntu:24.04',
            'cpu'         => $instance['cpu'] ?? '2',
            'memory'      => $instance['memory'] ?? '512m',
            'ports'       => $instance['ports'] ?? [],
            'network'     => $instance['network'] ?? 'Default (wg0)',
            'ssh_enabled' => $instance['ssh_enabled'] ?? false,
            'users'       => $instance['users'] ?? [],
            'bind_mounts' => $instance['bind_mounts'] ?? [],
        ],
        'created_by'     => $user->getUsername(),
        'created_at'     => time()
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Version saved']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
