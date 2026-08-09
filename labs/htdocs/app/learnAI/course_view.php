<?php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin");
    exit;
}

$lesson_id = $_GET['id'] ?? null;
$chapter_id = $_GET['chapter_id'] ?? null;

if (!$lesson_id) {
    header("Location: /learn");
    exit;
}

Session::$pageTitle = $chapter_id ? "Chapter Content | Learn AI" : "Lesson Details | Learn AI";
Session::set('is_learn_ai', true);
// Session::set('footer', true);
Session::addCustomJs('/js/learnAI/layout.js');

// Populate labs_list so the Required Lab card has real data
if (!Session::get('labs_list')) {
    $user = Session::getUser();
    $db = DatabaseConnection::getDefaultDatabase();
    $labTemplates = [
        ['id' => 'essentials', 'name' => 'Essentials', 'icon' => 'tux', 'badges' => ['free', 'beta']],
        ['id' => 'minio', 'name' => 'MinIO S3 Storage', 'icon' => 'docker', 'badges' => ['S3', 'beta']],
        ['id' => 'n8n', 'name' => 'n8n Workflow Lab', 'icon' => 'git-repo-forked', 'badges' => ['workflow', 'beta']],
        ['id' => 'docker_lab', 'name' => 'Tom Docker Lab', 'icon' => 'docker', 'badges' => ['docker', 'beta']]
    ];
    $labsList = [];
    foreach ($labTemplates as $tmpl) {
        $hash = $user->getLabHash($tmpl['id']);
        $data = $db->machine_labs->findOne(['instance_hash' => $hash]);
        $labsList[] = [
            'id'        => $tmpl['id'],
            'name'      => $tmpl['name'],
            'icon'      => $tmpl['icon'],
            'badges'    => $tmpl['badges'],
            'hash'      => $hash,
            'status'    => ($data && ($data['status'] ?? '') === 'running') ? 'running' : 'offline',
            'ip'        => ($data && ($data['status'] ?? '') === 'running' && isset($data['internal_ip'])) ? $data['internal_ip'] : 'Instance Down',
            'is_public' => ($data && isset($data['is_public'])) ? 'public' : 'private'
        ];
    }
    Session::set('labs_list', $labsList);
}

// HTMX-Style Partial Rendering for AJAX Requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $db = DatabaseConnection::getDefaultDatabase();
    $lesson = $db->ai_lessons->findOne(['_id' => new MongoDB\BSON\ObjectId($lesson_id)]);
    $chapter = null;
    $chapters = [];
    $modules = [];
    
    if ($chapter_id) {
        $chapter = $db->ai_chapters->findOne(['_id' => new MongoDB\BSON\ObjectId($chapter_id)]);
    } else {
        $chapters = $db->ai_chapters->find(['lesson_id' => new MongoDB\BSON\ObjectId($lesson_id)], ['sort' => ['order' => 1]])->toArray();
        foreach ($chapters as $chap) {
            $modules[$chap['module_name']][] = $chap;
        }
    }
    
    if ($chapter_id) {
        include __DIR__ . '/../../src/template/partials/learnAI/chapter_content.php';
    } else {
        include __DIR__ . '/../../src/template/partials/learnAI/course_overview.php';
    }
    
    exit;
}

Session::loadMaster();
