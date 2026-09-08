<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$user = Session::getUser();
$labHash = $_GET['hash'] ?? '';
$datatype = $_GET['type'] ?? 'all';

if (empty($labHash)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing hash parameter']));
}

// Verify ownership: check if this hash belongs to the user
$isOwner = false;
foreach (['essentials', 'minio', 'n8n', 'docker_lab', 'gui_essentials'] as $type) {
    if ($user->getLabHash($type) === $labHash) {
        $isOwner = true;
        break;
    }
}

if (!$isOwner) {
    http_response_code(403);
    exit(json_encode(['error' => 'Access denied']));
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $inst = $db->machine_labs->findOne(
        ['instance_hash' => $labHash],
        ['projection' => [
            'lab_type' => 1,
            'internal_ip' => 1,
            'credentials' => 1,
            'status' => 1,
            'code_domain' => 1,
            'gui_domain' => 1,
            'domains' => 1,
            'expose_web' => 1,
            'http_proxies' => 1
        ]]
    );

    if (!$inst) {
        http_response_code(404);
        exit(json_encode(['error' => 'Lab not found']));
    }

    $labType = $inst['lab_type'] ?? 'essentials';
    $currentUsername = $user->getUsername();

    $response = [];

    if ($datatype === 'config' || $datatype === 'all') {
        $configData = [
            'ip' => $inst['internal_ip'] ?? null,
            'domains' => $inst['domains'] ?? [],
            'code_domain' => $inst['code_domain'] ?? null,
            'gui_domain' => $inst['gui_domain'] ?? null,
            'expose_web' => $inst['expose_web'] ?? [],
            'http_proxies' => $inst['http_proxies'] ?? [],
        ];
        $labConfig = \TomLabs\Labs\LabTemplateConfig::getTemplate($labType, $configData, $currentUsername);
        $response['config'] = $labConfig;
    }

    if ($datatype === 'domains' || $datatype === 'all') {
        $dm = new DomainManager();
        $domainUsageMap = $dm->getDomainUsageMap($user->getUserId());
        $response['domains'] = $domainUsageMap;
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
