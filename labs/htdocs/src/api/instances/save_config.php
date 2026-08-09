<?php
require_once __DIR__ . '/../../load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername() ?? '';
$email = $user->getEmail() ?? '';

$input = json_decode(file_get_contents('php://input'), true);
$hash = $input['slug'] ?? '';
$config = $input['config'] ?? [];

if (empty($hash)) {
    echo json_encode(['status' => 'error', 'error' => 'Instance hash is required']);
    exit;
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instance = $db->instances->findOne(['instance_hash' => $hash, 'user_id' => $userId]);
if (!$instance) {
    $instance = $db->instances->findOne(['slug' => $hash, 'user_id' => $userId]);
}
if (!$instance) {
    echo json_encode(['status' => 'error', 'error' => 'Instance not found']);
    exit;
}

// Build the nested config object from incoming flat fields
$existingConfig = $instance['config'] ?? [];

$newConfig = [
    'general' => [
        'name'        => $config['name']        ?? $existingConfig['general']['name']        ?? $instance['name'] ?? '',
        'slug'        => $instance['slug'] ?? $hash,
        'description' => $config['description'] ?? $existingConfig['general']['description'] ?? $instance['description'] ?? '',
        'stability'   => $config['stability']   ?? $existingConfig['general']['stability']   ?? $instance['stability'] ?? 'alpha',
    ],
    'resources' => [
        'cpu'     => $config['cpu']     ?? $existingConfig['resources']['cpu']     ?? $instance['cpu'] ?? '2',
        'memory'  => $config['memory']  ?? $existingConfig['resources']['memory']  ?? $instance['memory'] ?? '512m',
        'ports'   => $config['ports']   ?? $existingConfig['resources']['ports']   ?? ($instance['ports'] ?? []),
        'network' => $config['network'] ?? $existingConfig['resources']['network'] ?? $instance['network'] ?? 'Default (wg0)',
    ],
    'users' => [
        'list'                   => $config['users']              ?? $existingConfig['users']['list']              ?? ($instance['users'] ?? []),
        'run_as_signed_in_user'  => $config['run_as_signed_in_user'] ?? $existingConfig['users']['run_as_signed_in_user'] ?? !empty($instance['run_as_signed_in_user']),
        'ssh_enabled'            => $config['ssh_enabled']        ?? $existingConfig['users']['ssh_enabled']        ?? !empty($instance['ssh_enabled']),
    ],
    'storage' => [
        'home_mount_enabled' => $config['home_mount_enabled'] ?? $existingConfig['storage']['home_mount_enabled'] ?? !empty($instance['home_mount_enabled']),
        'home_mount_path'    => $config['home_mount_path']    ?? $existingConfig['storage']['home_mount_path']    ?? $instance['home_mount_path'] ?? '/home',
        'bind_mounts'        => $config['bind_mounts']        ?? $existingConfig['storage']['bind_mounts']        ?? ($instance['bind_mounts'] ?? []),
    ],
];

// Save to instances collection
$db->instances->updateOne(
    ['_id' => $instance['_id']],
    ['$set' => [
        'config'     => $newConfig,
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ]]
);

// Also update config.json in the file store (for labsctl/worker compatibility)
$instanceHash = $instance['instance_hash'] ?? $hash;
$templateFolder = InstanceFileStore::resolveTemplateFolder($instance);

if ($templateFolder) {
    $userDoc = InstanceFileStore::collection()->findOne(['instance_id' => $instanceHash]);
    $userFiles = $userDoc ? (array)($userDoc['files'] ?? []) : [];
    $existing = $userFiles['config.json'] ?? null;

    $json = null;
    if ($existing && is_array($existing) && !empty($existing['content'])) {
        $json = json_decode($existing['content'], true);
    }

    if (!is_array($json)) {
        $base = InstanceFileStore::readBaseFile($templateFolder, 'config.json');
        if ($base && !empty($base['content'])) {
            $json = json_decode($base['content'], true);
        }
    }

    if (is_array($json)) {
        $json['lab_name'] = $newConfig['general']['name'];
        $json['stability'] = $newConfig['general']['stability'];
        $json['resources']['cpus'] = (string) $newConfig['resources']['cpu'];
        $json['resources']['memory'] = $newConfig['resources']['memory'];
        $json['network']['mode'] = $newConfig['resources']['network'];

        $ports = $newConfig['resources']['ports'];
        if (is_string($ports)) $ports = explode(' ', $ports);
        $ports = array_filter(array_map('trim', $ports));
        if (!empty($ports) && isset($json['services'])) {
            $portIdx = 0;
            foreach ($json['services'] as $svcName => &$svc) {
                if (isset($svc['port']) && isset($ports[$portIdx])) {
                    $svc['port'] = (int) $ports[$portIdx];
                    $portIdx++;
                }
            }
            unset($svc);
        }

        $newContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        InstanceFileStore::saveFile($instanceHash, $templateFolder, 'config.json', $newContent, $username, $email);
        InstanceFileStore::deleteNode($instanceHash, 'config');
    }
}

echo json_encode(['status' => 'success']);
