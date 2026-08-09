<?php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin");
    exit;
}

Session::$pageTitle = "Learn AI";
Session::set('is_learn_ai', true);
Session::addCustomJs('/js/learnAI/layout.js');
Session::loadMaster();
