<?php
require_once __DIR__ . '/../../load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername();
$email = $user->getEmail();
$dbInstances = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');

$source_id = $_POST['source_id'] ?? '';
$name = trim($_POST['name'] ?? '');
$visibility = $_POST['visibility'] ?? 'private';

if (empty($source_id)) {
    http_response_code(400);
    echo 'Source lab is required';
    exit;
}

// Handle base template forking (base:essentials, base:minio, etc.)
$isBaseTemplate = (strpos($source_id, 'base:') === 0);

if ($isBaseTemplate) {
    $labType = substr($source_id, 5);
    $template = $labType;
    $source = ['name' => ucfirst($labType) . ' Lab', 'type' => 'machine', 'image' => 'ubuntu:24.04'];
} else {
    // Find source lab in instances
    $source = $dbInstances->instances->findOne(['instance_hash' => $source_id]);
    if (!$source) {
        try {
            $source = $dbInstances->instances->findOne(['_id' => new MongoDB\BSON\ObjectId($source_id)]);
        } catch (Exception $e) {
            $source = $dbInstances->instances->findOne(['slug' => $source_id]);
        }
    }

    if (!$source) {
        http_response_code(404);
        echo 'Source lab not found';
        exit;
    }

    $labType = $source['lab_type'] ?? ($source['type'] ?? 'machine');
    $template = $source['template'] ?? $labType;
}
$bgMap = ['essentials' => '#e95420', 'minio' => '#2f3542', 'n8n' => '#ff6b81', 'docker_lab' => '#2496ed'];
$typeIconMap = ['essentials' => 'bxl-tux', 'minio' => 'bx-cube', 'n8n' => 'bx-git-repo-forked', 'docker_lab' => 'bxl-docker'];

$bgColor = $bgMap[$labType] ?? 'rgba(0,0,0,0.5)';
$icon = $typeIconMap[$labType] ?? 'bx-cube-alt';

if (empty($name)) {
    $name = $source['name'] ?? ucfirst($labType);
}

// Ensure unique name for this user
$nameBase = $name;
$nameCount = $dbInstances->instances->countDocuments(['user_id' => $userId, 'name' => $name]);
if ($nameCount > 0) {
    $name = $nameBase . ' (' . ($nameCount + 1) . ')';
}

// Generate slug
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'));
if (empty($slug)) $slug = 'lab-' . time();

// Ensure unique slug
$existing = $dbInstances->instances->findOne(['slug' => $slug]);
if ($existing) {
    $slug .= '-' . rand(1000, 9999);
}

// Generate unique instance hash (email + slug + salt)
$instanceHash = md5($email . $slug . '8b51626f3a468904e8b6f83747f2fcf1');

$instance = [
    'user_id' => $userId,
    'username' => $username,
    'email' => $email,
    'name' => $name,
    'slug' => $slug,
    'instance_hash' => $instanceHash,
    'visibility' => $visibility,
    'type' => $labType === 'n8n' || $labType === 'minio' || $labType === 'essentials' || $labType === 'docker_lab' ? 'machine' : ($source['type'] ?? 'machine'),
    'template' => $template,
    'status' => 'draft',
    'image' => $source['image'] ?? 'ubuntu:24.04',
    'color' => $bgColor,
    'icon' => $icon,
    'forked_from' => $source['_id'] ?? $source_id,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
];

$result = $dbInstances->instances->insertOne($instance);

if ($result->getInsertedCount() > 0) {
    try {
        $newId = $result->getInsertedId();
        $instance['_id'] = $newId;
        InstanceFileStore::ensureBaseForInstance($instance);
    } catch (Exception $e) {
        error_log('Instance file seed failed: ' . $e->getMessage());
    }

    $slug        = $slug;
    $instanceHash = $instanceHash;
    $name        = $name;
    $type        = $instance['type'];
    $status      = $instance['status'];
    $visibility  = $visibility;
    $image       = $instance['image'];
    $bgColor     = $bgColor;
    $icon        = $icon;
    $tplKey      = $instance['template'] ?? $instance['type'] ?? 'machine';
    $forked_from = !empty($instance['forked_from']);
    $updatedLabel = 'just now';

    $avatarMap = [
        'essentials' => 'essentials_avatar.png',
        'minio'      => 'minio_avatar.png',
        'docker_lab' => 'docker_avatar.png',
        'docker'     => 'docker_avatar.png',
        'zephyr'     => 'zephyr_avatar.png',
        'kali'       => 'kali-background.png',
        'n8n'        => 'essentials_avatar.png',
    ];
    $avatarFile = $avatarMap[$tplKey] ?? 'essentials_avatar.png';
    $cover = Session::cdn3('labassets/avatar/' . $avatarFile);

    ob_start();
    include __DIR__ . '/../../template/partials/_instance_card.php';
    $html = ob_get_clean();

    if (ob_get_length()) ob_clean();
    echo $html;
} else {
    http_response_code(400);
    echo 'Database insert failed';
}
