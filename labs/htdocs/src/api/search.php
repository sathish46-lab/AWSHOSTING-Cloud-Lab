<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/utils/config.php';
require_once __DIR__ . '/../../src/lib/core/DatabaseConnection.class.php';

session_start();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$qEscaped = preg_quote($q, '/');
$userId = $_SESSION['user_id'] ?? 0;

if (empty($q)) {
    echo json_encode(['result' => 'success', 'q' => '', 'groups' => (object)[]]);
    exit;
}

try {
    $mongoClient = DatabaseConnection::getClient();
    $db = $mongoClient->selectDatabase('tom_labs_db');
} catch (\Throwable $e) {
    echo json_encode(['result' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$results = [
    'running'    => [],
    'catalog'    => [],
    'apps'       => [],
    'challenges' => [],
    'quiz'       => [],
    'learn'      => [],
    'roadmaps'   => [],
    'syllabus'   => [],
];

$ql = strtolower($q);

/* ──────────── 1. RUNNING LABS ──────────── */
try {
    $runningLabs = $db->machine_labs->find([
        'user_id' => (int)$userId,
        'status'  => ['$in' => ['running', 'paused']],
    ]);
    foreach ($runningLabs as $lab) {
        $labType = $lab['lab_type'] ?? '';
        $labName = $lab['lab_name'] ?? '';
        $hash    = $lab['instance_hash'] ?? '';
        $icon    = $lab['icon'] ?? null;
        if (!empty($hash) && (stripos($labType, $ql) !== false || stripos($labName, $ql) !== false || stripos($hash, $ql) !== false)) {
            $results['running'][] = [
                'type' => 'running', 'label' => $labName ?: ucfirst(str_replace('_', ' ', $labType)),
                'sub' => $labType, 'icon' => $icon, 'iid' => $hash, 'lab' => $labType,
                'action' => 'code', 'codeserver' => true,
            ];
        }
    }
} catch (\Throwable $e) {}

/* ──────────── 2. LAB CATALOG ──────────── */
$labCatalog = [
    ['id' => 'essentials',     'name' => 'Essentials Lab',     'glyph' => 'bx-command', 'colour' => '#E95420'],
    ['id' => 'gui_essentials', 'name' => 'GUI Essentials Lab', 'glyph' => 'bx-desktop', 'colour' => '#E95420'],
    ['id' => 'minio',          'name' => 'MinIO S3 Storage',   'glyph' => 'bx-cloud',   'colour' => '#E95420'],
    ['id' => 'n8n',            'name' => 'n8n Workflow Lab',   'glyph' => 'bx-network-chart', 'colour' => '#E95420'],
    ['id' => 'docker_lab',     'name' => 'Docker Lab',         'glyph' => 'bxl-docker', 'colour' => '#E95420'],
];
foreach ($labCatalog as $lc) {
    if (stripos($lc['name'], $ql) !== false || stripos($lc['id'], $ql) !== false) {
        $results['catalog'][] = [
            'type' => 'catalog', 'label' => $lc['name'],
            'sub' => 'Deploy · ' . $lc['id'],
            'icon' => ['kind' => 'glyph', 'glyph' => $lc['glyph'], 'colour' => $lc['colour']],
            'lab' => $lc['id'], 'href' => '/labs/dashboard/' . $lc['id'],
        ];
    }
}

/* ──────────── 3. APPS / PAGES ──────────── */
$pages = [
    ['title' => 'Dashboard',       'section' => 'Main',     'url' => '/dashboard',          'glyph' => 'bx-home',         'colour' => '#3b82f6'],
    ['title' => 'Machine Labs',    'section' => 'Main',     'url' => '/labs',               'glyph' => 'bxLab',           'colour' => '#22c55e'],
    ['title' => 'Challenge Labs',  'section' => 'Main',     'url' => '/challenges',         'glyph' => 'bx-trophy',       'colour' => '#ef4444'],
    ['title' => 'Spot Quiz',       'section' => 'Learn',    'url' => '/quiz',               'glyph' => 'bx-check-circle', 'colour' => '#8b5cf6'],
    ['title' => 'Code Arena',      'section' => 'Learn',    'url' => '/code',               'glyph' => 'bx-code',         'colour' => '#f59e0b'],
    ['title' => 'Learn AI',        'section' => 'Learn',    'url' => '/learn',              'glyph' => 'bx-book-open',    'colour' => '#06b6d4'],
    ['title' => 'Roadmaps',        'section' => 'Learn',    'url' => '/roadmaps',           'glyph' => 'bx-map-pin',      'colour' => '#10b981'],
    ['title' => 'Syllabus AI',     'section' => 'Learn',    'url' => '/syllabus',           'glyph' => 'bx-notes',        'colour' => '#f472b6'],
    ['title' => 'Clubs',           'section' => 'Social',   'url' => '/clubs',              'glyph' => 'bx-group',        'colour' => '#ec4899'],
    ['title' => 'Clans',           'section' => 'Social',   'url' => '/clans',              'glyph' => 'bx-flag',         'colour' => '#ef4444'],
    ['title' => 'Leaderboard',     'section' => 'Social',   'url' => '/leaderboard-global', 'glyph' => 'bx-bar-chart',    'colour' => '#eab308'],
    ['title' => 'Feeling Lucky',   'section' => 'Social',   'url' => '/lucky',              'glyph' => 'bx-bolt-circle',  'colour' => '#a855f7'],
    ['title' => 'MCP Connections', 'section' => 'Network',  'url' => '/mcp',                'glyph' => 'bx-share-boxed',  'colour' => '#22d3ee'],
    ['title' => 'Domains',         'section' => 'Network',  'url' => '/domains',            'glyph' => 'bx-globe-alt',    'colour' => '#f59e0b'],
    ['title' => 'Account',         'section' => 'Settings', 'url' => '/account',            'glyph' => 'bx-user',         'colour' => '#6366f1'],
    ['title' => 'Admin Panel',     'section' => 'Settings', 'url' => '/admin/users',        'glyph' => 'bx-crown',        'colour' => '#ef4444'],
];
foreach ($pages as $p) {
    if (stripos($p['title'], $ql) !== false || stripos($p['section'], $ql) !== false) {
        $results['apps'][] = [
            'type' => 'app', 'label' => $p['title'], 'sub' => $p['section'],
            'glyph' => $p['glyph'], 'colour' => $p['colour'], 'href' => $p['url'],
        ];
    }
}

/* ──────────── 4. CHALLENGES ──────────── */
$challengesFile = __DIR__ . '/../config/challenges.json';
if (file_exists($challengesFile)) {
    $challenges = json_decode(file_get_contents($challengesFile), true) ?: [];
    foreach ($challenges as $ch) {
        $name = $ch['name'] ?? '';
        $tags = implode(' ', array_column($ch['tags'] ?? [], 'text'));
        if (stripos($name, $ql) !== false || stripos($tags, $ql) !== false) {
            $results['challenges'][] = [
                'type' => 'challenge', 'label' => $name,
                'sub' => ($ch['ribbon_text2'] ?? 'CTF') . ' · ' . ($ch['points'] ?? 0) . ' pts',
                'glyph' => 'bx-trophy', 'colour' => '#ef4444', 'href' => '/challenges',
            ];
        }
    }
}

/* ──────────── 5. QUIZ ──────────── */
$quizCatsFile = __DIR__ . '/../data/quiz_categories.json';
$quizSubFile  = __DIR__ . '/../data/quiz_subtopics.json';
$quizCats = file_exists($quizCatsFile) ? (json_decode(file_get_contents($quizCatsFile), true) ?: []) : [];
$quizSubs = file_exists($quizSubFile)  ? (json_decode(file_get_contents($quizSubFile), true)  ?: []) : [];

foreach ($quizCats as $cat) {
    if (stripos($cat['title'] ?? '', $ql) !== false || stripos($cat['desc'] ?? '', $ql) !== false) {
        $results['quiz'][] = [
            'type' => 'quiz_category', 'label' => $cat['title'],
            'sub' => 'Quiz · ' . ($cat['section'] ?? ''),
            'glyph' => 'bx-check-circle', 'colour' => '#8b5cf6', 'href' => '/quiz/' . ($cat['hash'] ?? ''),
        ];
    }
}
foreach ($quizSubs as $sub) {
    if (stripos($sub['title'] ?? '', $ql) !== false || stripos($sub['desc'] ?? '', $ql) !== false) {
        $parentHash = '';
        foreach ($quizCats as $cat) {
            if (($cat['id'] ?? '') === ($sub['category_id'] ?? '')) {
                $parentHash = $cat['hash'] ?? '';
                break;
            }
        }
        $results['quiz'][] = [
            'type' => 'quiz_subtopic', 'label' => $sub['title'],
            'sub' => 'Quiz · ' . ($sub['desc'] ?? ''),
            'glyph' => 'bx-check-circle', 'colour' => '#8b5cf6',
            'href' => $parentHash ? '/quiz/' . $parentHash : '/quiz',
        ];
    }
}

/* ──────────── 6. LEARN AI LESSONS ──────────── */
try {
    $lessonCursor = $db->ai_lessons->find([
        '$or' => [
            ['title'  => ['$regex' => $qEscaped, '$options' => 'i']],
            ['tags'   => ['$regex' => $qEscaped, '$options' => 'i']],
            ['author' => ['$regex' => $qEscaped, '$options' => 'i']],
        ],
        'visibility' => 'Public',
    ], ['limit' => 10]);
    foreach ($lessonCursor as $lesson) {
        $isSyllabus = $lesson['is_syllabus'] ?? false;
        $section = $isSyllabus ? 'syllabus' : 'learn';
        $results[$section][] = [
            'type' => 'topic', 'label' => $lesson['title'] ?? '',
            'sub' => ($isSyllabus ? 'Syllabus' : 'Lesson') . ' · ' . ($lesson['level'] ?? ''),
            'glyph' => 'bx-book-open', 'colour' => $isSyllabus ? '#f472b6' : '#06b6d4',
            'href' => '/learn/lesson/' . (string)($lesson['_id']),
        ];
    }
} catch (\Throwable $e) {}

/* ──────────── 7. ROADMAPS ──────────── */
foreach ($quizSubs as $sub) {
    if (($sub['category_id'] ?? '') === 'roadmap' && stripos($sub['title'] ?? '', $ql) !== false) {
        $results['roadmaps'][] = [
            'type' => 'topic', 'label' => $sub['title'],
            'sub' => 'Roadmap',             'glyph' => 'bx-map-pin', 'colour' => '#10b981',
            'href' => '/roadmaps/' . ($sub['hash'] ?? ''),
        ];
    }
}

/* ──────────── 8. SYLLABUS ──────────── */
foreach ($quizSubs as $sub) {
    if (($sub['category_id'] ?? '') === 'syllabus' && stripos($sub['title'] ?? '', $ql) !== false) {
        $results['syllabus'][] = [
            'type' => 'topic', 'label' => $sub['title'],
            'sub' => 'Syllabus', 'glyph' => 'bx-notes', 'colour' => '#f472b6',
            'href' => '/syllabus/' . ($sub['hash'] ?? ''),
        ];
    }
}

/* ──────────── Remove empty groups ──────────── */
$results = array_filter($results, function ($v) { return !empty($v); });

echo json_encode([
    'result' => 'success',
    'q'      => $q,
    'groups' => (object)$results,
]);
