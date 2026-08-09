<?php
// /app/instances/manage.php
require_once __DIR__ . '/../../src/load.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    if (!empty($_GET['tab']) && $isAjax) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="alert alert-danger">Unauthorized.</div>';
        exit;
    }
    Session::$pageTitle = "Instances | Manage";
    Session::loadMaster();
    exit;
}

$user = Session::getUser();
$hash = $_GET['slug'] ?? '';

if (empty($hash)) {
    if (!empty($_GET['tab']) && $isAjax) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="alert alert-danger">Missing instance hash.</div>';
        exit;
    }
    header("Location: /instances");
    exit;
}

// Fetch instance data by instance_hash
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instance = $db->instances->findOne(['instance_hash' => $hash]);

// Fallback: try by slug for old URLs
if (!$instance) {
    $instance = $db->instances->findOne(['slug' => $hash]);
}

if (!$instance) {
    // If not found in 'instances', optionally fallback or just leave it null
    // (For testing purposes, we'll proceed even if null so the UI can render)
}

// Handle AJAX tab request — return the raw HTML fragment (no JSON wrapper).
if (!empty($_GET['tab']) && $isAjax) {
    $tab = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['tab']); // sanitize
    $partialPath = __DIR__ . "/../../src/template/pages/instances/manage/{$tab}.php";

    header('Content-Type: text/html; charset=UTF-8');
    if (file_exists($partialPath)) {
        include $partialPath;
    } else {
        echo '<div class="alert alert-danger">Tab not found.</div>';
    }
    exit;
}

Session::$pageTitle = "Instances - " . htmlspecialchars($instance['name'] ?? $hash) . " | Tom Labs";
Session::loadMaster('instances/manage.php', ['slug' => $hash]);

