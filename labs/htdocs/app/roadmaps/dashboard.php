<?php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin");
    exit;
}

Session::$pageTitle = "Roadmaps";
Session::set('is_roadmap', true);
Session::loadMaster();
