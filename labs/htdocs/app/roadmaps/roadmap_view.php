<?php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin");
    exit;
}

$slug = $_GET['slug'] ?? '';
if (empty($slug)) { header("Location: /roadmaps"); exit; }

Session::$pageTitle = "Roadmap";
Session::set('is_roadmap', true);
Session::set('footer', true);
Session::loadMaster();
