<?php
require_once __DIR__ . '/../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header("Location: /signin"); exit;
}

$currentUser = Session::getUser();
if ($currentUser->getRole() !== 'superuser') {
    header("Location: /home"); exit; 
}

$email = $_GET['email'] ?? '';
if (!$email) {
    header("Location: /admin/users"); exit;
}

$db = DatabaseConnection::getDefaultDatabase();
$userData = $db->users->findOne(['email' => $email]);

if (!$userData) {
    header("Location: /admin/users"); exit;
}

$user = new User($email);
$avatar = "https://ui-avatars.com/api/?name=".urlencode($user->getFullName() ?? 'U')."&background=random";

// Get user labs and domains
$deployedLabs = iterator_to_array($db->machine_labs->find(['deploy.email' => $email]));
$domains = iterator_to_array($db->domains->find(['email' => $email]));
$quizzes = $userData['quizzes_completed'] ?? [];

Session::$pageTitle = "User Profile: " . htmlspecialchars($email);
Session::loadMaster();
