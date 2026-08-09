<?php
require_once __DIR__ . '/../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin"); exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();

// 1. Define your Available Lab Templates professionally
$labTemplates = [
    ['id' => 'essentials', 'name' => 'Essentials Lab', 'icon' => 'tux', 'badges' => ['free', 'beta']],
    ['id' => 'gui_essentials', 'name' => 'GUI Essentials Lab', 'icon' => 'tux', 'badges' => ['gui', 'beta']],
    ['id' => 'minio', 'name' => 'MinIO S3 Storage', 'icon' => 'docker', 'badges' => ['S3', 'beta']],
    ['id' => 'n8n', 'name' => 'n8n Workflow Lab', 'icon' => 'git-repo-forked', 'badges' => ['workflow', 'beta']],
    ['id' => 'docker_lab', 'name' => 'Tom Docker Lab', 'icon' => 'docker', 'badges' => ['docker', 'beta']]
];
$labsList = [];
foreach ($labTemplates as $tmpl) {
    // 1. FIXED: Use the specific template ID so each lab has a unique hash
    $hash = $user->getLabHash($tmpl['id']); 
    
    $data = $db->machine_labs->findOne(['instance_hash' => $hash]);

    $labsList[] = [
        'id'     => $tmpl['id'],
        'name'   => $tmpl['name'],
        'icon'   => $tmpl['icon'],
        'badges' => $tmpl['badges'],
        'hash'   => $hash,
        'status' => ($data && ($data['status'] ?? '') === 'running') ? 'running' : 'offline',
        'ip'     => ($data && ($data['status'] ?? '') === 'running' && isset($data['internal_ip'])) ? $data['internal_ip'] : 'Instance Down',
        'is_public' => ($data && isset($data['is_public'])) ? 'public' : 'private'
    ];
}

$runningCount = 0;
foreach ($labsList as $l) {
    if ($l['status'] === 'running') {
        $runningCount++;
    }
}

// Pass these explicitly to the session so the template can find them
Session::set('running_count', $runningCount);
Session::set('total_labs', count($labsList));

// 2. Set variables for the template
Session::set('labs_list', $labsList);
Session::set('footer', false); 
Session::$pageTitle = "Lab";
Session::loadMaster();