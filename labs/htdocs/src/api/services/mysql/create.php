<?php
require_once "../../../load.php";
require_once "../../../lib/services/MySqlManager.php";
require_once "../../../lib/core/AuditLog.class.php";

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

try {
    // Limit to 5 databases per user to prevent abuse
    $dbCount = $db->mysql_services->countDocuments(['user_id' => $user->getUserId(), 'status' => ['$ne' => 'deleted']]);
    if ($dbCount >= 5) {
        echo json_encode(['success' => false, 'error' => 'You have reached the maximum limit of 5 MySQL databases.']);
        exit;
    }

    $manager = new MySqlManager();

    // Generate unique credentials
    // Format: username_randomhex
    $safeUsername = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(explode('@', $user->getEmail())[0]));
    $shortHash = substr(md5(uniqid()), 0, 5);
    
    $dbName = "db_" . $safeUsername . "_" . $shortHash;
    $dbUser = "usr_" . $safeUsername . "_" . $shortHash;
    
    // Generate a strong random password
    $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'), 0, 16);

    // 1. Create MySQL User
    if (!$manager->createUser($dbUser, $password)) {
        throw new Exception("Failed to create MySQL user. It might already exist.");
    }

    // 2. Create MySQL Database
    if (!$manager->createDatabase($dbName, $dbUser)) {
        // Rollback user creation
        $manager->deleteUser($dbUser);
        throw new Exception("Failed to create MySQL database.");
    }

    // 3. Save to MongoDB
    $dbRecord = [
        'user_id' => $user->getUserId(),
        'db_name' => $dbName,
        'db_user' => $dbUser,
        'db_password' => base64_encode($password), // Base64 just to prevent accidental special char breakage, not encryption
        'created_at' => new MongoDB\BSON\UTCDateTime()
    ];
    
    $db->mysql_services->insertOne($dbRecord);

    AuditLog::log('create', 'service_mysql', $dbName, [
        'db_user' => $dbUser,
        'host' => get_config('tunnel_ip') . '1',
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'MySQL database created successfully.',
        'data' => [
            'host' => get_config('tunnel_ip') . '1',
            'port' => 3306,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $password
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
