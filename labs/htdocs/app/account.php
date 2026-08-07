<?php
require_once __DIR__ . '/../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin"); exit;
}

$user = Session::getUser();
$username = $user->getUsername();

Session::$pageTitle = "Activity & Analytics - " . $username;
Session::loadMaster();