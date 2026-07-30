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

    if (!$labData) throw new Exception('Lab not found');
    if ($labData['user_id'] !== $user->getUserId()) throw new Exception('Unauthorized');

    $labType = $labData['lab_type'] ?? 'essentials';
    $creds = (array)($labData['credentials'] ?? []);
    $password = $creds['password'] ?? '********';

    $data = [
        'title' => 'Visual Studio Code on Web',
        'description' => [
            "Code effortlessly on Browser.",
            "Browse the filesystem and do CRUD.",
            "Access linux shell CLI.",
            "Develop effortlessly on the go."
        ],
        'instruction' => "You need this password in the next screen to login to VS Code on Web - Happy Coding!",
        'primary' => ['label' => 'Code Server Password', 'value' => $password],
        'action' => ['label' => 'Launch Code IDE', 'link' => "https://{$hash}.tomweb.shop"]
    ];

    if ($labType === 'minio') {
        $data['title'] = 'MinIO S3 Storage';
        $data['description'] = ["High-performance S3 storage.", "Manage via Console.", "S3 API Integration."];
        $data['instruction'] = "Use the Secret Key below to login - Happy Coding!";
        $data['primary'] = ['label' => 'Minio Secret Key', 'value' => $creds['minio_secret_key'] ?? $password];
        $data['action'] = ['label' => 'Launch MinIO Console', 'link' => "https://s3-{$hash}.tomweb.shop"];
    } elseif ($labType === 'n8n') {
        $data['title'] = 'n8n Automation';
        $data['description'] = ["Visual workflow automation.", "Self-hosted control."];
        $data['instruction'] = "Enter your password in the next screen - Happy Coding!";
        $data['primary'] = ['label' => 'n8n Password', 'value' => $creds['n8n_password'] ?? $password];
        $data['action'] = ['label' => 'Open n8n Editor', 'link' => "https://n8n-{$hash}.tomweb.shop"];
    }

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
