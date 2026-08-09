<?php
// /app/instances/dashboard.php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    Session::$pageTitle = "Instances / Developer Area";
    Session::loadMaster();
    exit;
}

$user = Session::getUser();

Session::$pageTitle = "Instances - Developer Area";
Session::loadMaster();
