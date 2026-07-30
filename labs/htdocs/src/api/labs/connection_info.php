<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$hash = $_GET['hash'] ?? '';

if (empty($hash)) {
    echo json_encode(['status' => 'error', 'error' => 'Missing hash']);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    $inst = $db->instances->findOne(['instance_hash' => $hash]);
    $labData = $inst ? ($inst['deploy'] ?? []) : null;

    if (!$labData) {
        throw new Exception('Lab not found');
    }

    // Security check: Ensure the lab belongs to the user
    if ($labData['user_id'] !== $user->getUserId()) {
        throw new Exception('Unauthorized access to lab');
    }

    $labType = $labData['lab_type'] ?? 'essentials';
    
    // Generate the connection configuration
    $labConfig = \TomLabs\Labs\LabTemplateConfig::getTemplate($labType, (array)$labData, $user->getUsername());

    echo json_encode([
        'status' => 'success',
        'data'   => $labConfig
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
