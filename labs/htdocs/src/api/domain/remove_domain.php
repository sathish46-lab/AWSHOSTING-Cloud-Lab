<?php
require_once "../../load.php";

header('Content-Type: application/json');
$user = Session::getUser();
$data = json_decode(file_get_contents('php://input'), true);
$domainId = $data['domain_id'] ?? null;

if (!$domainId || Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']); exit;
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    
    // Security check: Soft-delete only if it belongs to the logged-in user
    $result = $db->domains->updateOne([
        '_id' => new MongoDB\BSON\ObjectId($domainId),
        'user_id' => $user->getUserId()
    ], [
        '$set' => [
            'status' => 'deleted',
            'deleted_at' => new MongoDB\BSON\UTCDateTime(),
            'deleted_by' => $user->getEmail(),
        ]
    ]);

    if ($result->getModifiedCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception("Domain not found or unauthorized access.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}