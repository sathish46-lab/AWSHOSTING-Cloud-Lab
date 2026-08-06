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
$data = json_decode(file_get_contents('php://input'), true);
$dbName = $data['db_name'] ?? '';

if (empty($dbName)) {
    echo json_encode(['success' => false, 'error' => 'Database name is required.']);
    exit;
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

try {
    // Verify ownership
    $dbRecord = $db->mysql_services->findOne([
        'user_id' => $user->getUserId(),
        'db_name' => $dbName
    ]);

    if (!$dbRecord) {
        echo json_encode(['success' => false, 'error' => 'Database not found or permission denied.']);
        exit;
    }

    $manager = new MySqlManager();

    // 1. Delete MySQL Database
    if (!$manager->deleteDatabase($dbRecord['db_name'])) {
        throw new Exception("Failed to drop MySQL database.");
    }

    // 2. Delete MySQL User
    if (!$manager->deleteUser($dbRecord['db_user'])) {
        // Not critical if user drop fails but db dropped, but good to know
        error_log("Failed to drop user {$dbRecord['db_user']}");
    }

    // 3. Remove from MongoDB
    $db->mysql_services->deleteOne(['_id' => $dbRecord['_id']]);

    AuditLog::log('delete', 'service_mysql', $dbName, [
        'db_user' => $dbRecord['db_user'],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'MySQL database deleted successfully.'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
