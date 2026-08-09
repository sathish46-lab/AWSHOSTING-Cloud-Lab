<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$username = $user->getUsername();
$userEmail = $user->getEmail();
$storageBase = get_config('storage_base') ?: '/var/tomlabs/storage';
$userHash = md5($userEmail);
// Scan the hash folder root (contains home/, .ubuntu/, .kali/, usr/, cron/, etc.)
$userHome = $storageBase . '/' . $userHash;

// Rate limit: 3 refreshes per 10 minutes
$rateKey = 'storage_lens_' . $user->getUserId();
$rateFile = sys_get_temp_dir() . '/tom_' . md5($rateKey) . '.json';
$rateData = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : ['count' => 0, 'window' => 0];

$now = time();
if ($now - ($rateData['window'] ?? 0) > 600) {
    $rateData = ['count' => 0, 'window' => $now];
}
$remaining = max(0, 3 - $rateData['count']);

if (isset($_GET['action']) && $_GET['action'] === 'refresh') {
    if ($rateData['count'] >= 3) {
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Try again later.', 'remaining_refreshes' => 0]); exit;
    }
    $rateData['count']++;
    $rateData['window'] = $now;
    file_put_contents($rateFile, json_encode($rateData));
    $remaining = max(0, 3 - $rateData['count']);
}

// Handle POST delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['action'] ?? '') === 'delete') {
        $deletePath = trim($input['path'] ?? '', '/');
        if ($deletePath === '') {
            echo json_encode(['success' => false, 'error' => 'Cannot delete root']); exit;
        }
        $deleteTarget = realpath($userHome . '/' . $deletePath);
        if ($deleteTarget === false || strpos($deleteTarget, $userHome) !== 0) {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        if (!is_dir($deleteTarget) && !is_file($deleteTarget)) {
            echo json_encode(['success' => false, 'error' => 'Not found']); exit;
        }
        // Only allow deleting lab directories (starting with .) and specific system dirs
        $baseName = basename($deleteTarget);
        $allowed = preg_match('/^\./', $baseName) || in_array($baseName, ['cron', 'usr']);
        if (!$allowed) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete this item']); exit;
        }
        exec('rm -rf ' . escapeshellarg($deleteTarget), $out, $ret);
        if ($ret === 0) {
            echo json_encode(['success' => true]); exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Delete failed']); exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid action']); exit;
}

// Path navigation:
// "" = user home root (/var/tomlabs/storage/{hash}/home/{username})
// "sub" = /var/tomlabs/storage/{hash}/home/{username}/sub
$relPath = isset($_GET['path']) ? trim($_GET['path'], '/') : '';

// SECURITY: ensure path stays within user's home
$scanPath = $userHome;
if ($relPath !== '') {
    $candidate = realpath($userHome . '/' . $relPath);
    if ($candidate === false || strpos($candidate, $userHome) !== 0) {
        echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
    }
    $scanPath = $candidate;
}

if (!is_dir($scanPath)) {
    echo json_encode(['success' => false, 'error' => 'Directory not found']); exit;
}

// Get user home size
$totalBytes = 0;
$output = [];
exec("du -sb " . escapeshellarg($userHome) . " 2>/dev/null", $output, $ret);
if ($ret === 0 && !empty($output[0])) {
    preg_match('/^(\d+)/', $output[0], $m);
    $totalBytes = (int)($m[1] ?? 0);
}

// Build breadcrumb: just Home Volume at root
$friendlyBc = ['home' => 'Home directory', 'usr' => 'usr', 'cron' => 'cron'];
$breadcrumbs = [
    ['name' => 'Home Volume', 'path' => '']
];

if ($relPath !== '') {
    $parts = explode('/', $relPath);
    $cumulative = '';
    foreach ($parts as $part) {
        $cumulative = $cumulative === '' ? $part : $cumulative . '/' . $part;
        $displayName = $friendlyBc[$part] ?? $part;
        $breadcrumbs[] = ['name' => $displayName, 'path' => $cumulative];
    }
}

// Scan entries
$entries = [];
$items = scandir($scanPath);
if ($items) {
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $fullPath = $scanPath . '/' . $item;
        $isDir = is_dir($fullPath);

        $entryBytes = filesize($fullPath);

        if ($isDir) {
            $entryOutput = [];
            exec("du -sb " . escapeshellarg($fullPath) . " 2>/dev/null", $entryOutput, $entryRet);
            if ($entryRet === 0 && !empty($entryOutput[0])) {
                preg_match('/^(\d+)/', $entryOutput[0], $em);
                $entryBytes = (int)($em[1] ?? 0);
            }

            $dirCountOutput = [];
            exec("find " . escapeshellarg($fullPath) . " 2>/dev/null | wc -l", $dirCountOutput, $wcRet);
            $itemCount = ($wcRet === 0 && !empty($dirCountOutput[0])) ? (int)trim($dirCountOutput[0]) : 0;
        } else {
            $itemCount = 0;
        }

        $mtime = filemtime($fullPath);

        $label = null;
        if ($isDir) {
            if (preg_match('/^\.(\w[\w-]*)$/', $item, $labMatch)) {
                $label = 'Lab: ' . $labMatch[1];
            } elseif (isset($GLOBALS['friendly_names'][$item])) {
                $label = $GLOBALS['friendly_names'][$item];
            }
        }

        $entries[] = [
            'name' => $item,
            'type' => $isDir ? 'dir' : 'file',
            'bytes' => $entryBytes,
            'items' => $itemCount,
            'mtime' => $mtime,
            'label' => $label,
            'protected' => false
        ];
    }
}

// Sort by size descending
usort($entries, function($a, $b) { return $b['bytes'] - $a['bytes']; });

$totalUsedMb = round($totalBytes / (1024 * 1024));
$limitGb = 25;

echo json_encode([
    'success' => true,
    'owner' => $user->getEmail(),
    'username' => $username,
    'rel' => $relPath,
    'path_display' => $relPath ?: $username,
    'breadcrumbs' => $breadcrumbs,
    'total_bytes' => $totalBytes,
    'entries' => $entries,
    'quota' => [
        'limit_gb' => $limitGb,
        'used_mb' => $totalUsedMb
    ],
    'pool' => [
        'id' => 'default',
        'fs' => 'xfs',
        'cow_clone' => false
    ],
    'friendly_names' => [
        'home' => 'Home directory',
        'Desktop' => 'Desktop',
        'Documents' => 'Documents',
        'Downloads' => 'Downloads',
        'Uploads' => 'Uploads',
        '.deployments' => 'Deployments copy',
        '.trash' => 'Trash (restorable)',
        '.cache' => 'Cache',
        '.config' => 'Lab: config',
        '.local' => 'Lab: local',
        '.ssh' => 'Lab: ssh',
        'transfers' => 'Received transfers',
        'usr' => 'usr',
        'cron' => 'cron',
        'htdocs' => 'htdocs',
        'htconfig' => 'htconfig',
        '_other' => 'Other'
    ],
    'remaining_refreshes' => $remaining
]);
