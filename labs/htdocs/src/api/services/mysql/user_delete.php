<?php
require_once "../../../load.php";
require_once "../../../lib/services/MySqlManager.php";

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

$data = json_decode(file_get_contents('php://input'), true);
$mysqlUsername = $data['username'] ?? '';

if (empty($mysqlUsername)) {
    echo json_encode(['success' => false, 'error' => 'Username is required.']);
    exit;
}

try {
    // Verify ownership of the MySQL user
    $userRecord = $db->mysql_users->findOne([
        'user_id' => $user->getUserId(),
        'email' => $user->getEmail(),
        'mysql_username' => $mysqlUsername
    ]);

    if (!$userRecord) {
        throw new Exception("MySQL User not found or permission denied.");
    }

    $manager = new MySqlManager();

    // 1. Find and Drop all databases owned by this user
    $databases = $db->mysql_databases->find([
        'mysql_user_id' => (string)$userRecord['_id'],
        'user_id' => $user->getUserId(),
        'email' => $user->getEmail()
    ]);
    foreach ($databases as $dbDoc) {
        $manager->deleteDatabase($dbDoc['db_name']);
        // Delete the MongoDB record
        $db->mysql_databases->deleteOne(['_id' => $dbDoc['_id']]);
    }

    // 2. Delete MySQL User
    if (!$manager->deleteUser($mysqlUsername)) {
        error_log("Failed to drop user {$mysqlUsername} in MySQL, but continuing with MongoDB cleanup.");
    }

    // 3. Soft-delete user from MongoDB (keep record for audit trail)
    $db->mysql_users->updateOne(['_id' => $userRecord['_id']], [
        '$set' => [
            'status' => 'deleted',
            'deleted_at' => new MongoDB\BSON\UTCDateTime(),
            'deleted_by' => $user->getEmail(),
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'MySQL user and all associated databases deleted successfully.'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
