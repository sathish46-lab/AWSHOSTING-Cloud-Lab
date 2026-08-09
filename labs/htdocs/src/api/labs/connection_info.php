<?php
require_once __DIR__ . '/../../../src/load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    exit('Unauthorized');
}

$user = Session::getUser();
$hash = $_GET['hash'] ?? '';

if (empty($hash)) {
    http_response_code(400);
    exit('Missing hash');
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $inst = $db->machine_labs->findOne(['instance_hash' => $hash]);

    if (!$inst) {
        throw new Exception('Lab not found');
    }

    if (($inst['user_id'] ?? null) !== $user->getUserId()) {
        throw new Exception('Unauthorized access to lab');
    }

    $labType = $inst['lab_type'] ?? 'essentials';
    $labConfig = \TomLabs\Labs\LabTemplateConfig::getTemplate($labType, (array)$inst, $user->getUsername());

    $title = $labConfig['title'] ?? 'Connection Details';
    $fields = $labConfig['fields'] ?? [];

    // Lab type specific descriptions
    $descriptions = [
        'essentials' => 'This lab is reachable with the following credentials. You can reach this IP from within your Essentials Lab. You can do VS Code Forward if you have connected Essentials Lab using VS Code Desktop.',
        'docker_lab' => 'This lab is reachable with the following credentials. You can reach this IP from within your Docker Lab. You can do VS Code Forward if you have connected Docker Lab using VS Code Desktop.',
        'minio' => 'Use the following credentials to access MinIO S3 Storage console and API.',
        'n8n' => 'Use the following credentials to access n8n Workflow Automation.',
        'gui_essentials' => 'This lab provides a full Ubuntu desktop environment. Use VNC credentials to access the GUI and code-server credentials for web-based development.'
    ];

    $description = $descriptions[$labType] ?? 'This lab is reachable with the following credentials.';

    echo '<div class="p-1">';
    
    // Description
    echo '<p class="text-white-50 small mb-4">' . htmlspecialchars($description) . '</p>';

    // Fields
    echo '<div class="d-flex flex-column gap-3">';
    foreach ($fields as $field) {
        $label = htmlspecialchars($field['label'] ?? '');
        $value = htmlspecialchars($field['value'] ?? '');
        $rawValue = $field['value'] ?? '';
        $isPassword = ($field['type'] ?? '') === 'password';
        $isLink = ($field['type'] ?? '') === 'link';
        $isMono = !empty($field['mono']) ? 'font-monospace' : '';

        if ($isLink) {
            echo '<div class="d-flex align-items-center gap-3">';
            echo '<div class="text-white-50 small fw-bold" style="min-width: 140px;">' . $label . '</div>';
            echo '<div class="flex-grow-1"><a href="' . $rawValue . '" target="_blank" class="text-decoration-none small fw-bold text-info">' . $value . ' <i class="bx bx-link-external ms-1"></i></a></div>';
            echo '</div>';
        } else {
            echo '<div class="d-flex align-items-center gap-3">';
            echo '<div class="text-white-50 small fw-bold" style="min-width: 140px;">' . $label . '</div>';
            echo '<div class="flex-grow-1 d-flex align-items-center gap-2">';
            echo '<input type="' . ($isPassword ? 'password' : 'text') . '" class="form-control form-control-sm bg-dark text-white border-secondary ' . $isMono . '" value="' . $rawValue . '" readonly style="opacity: 0.9;">';
            echo '<button class="btn btn-sm btn-outline-secondary border-secondary rounded-pill px-2" type="button" onclick="copyToClipboard(this)" data-copy="' . $rawValue . '"><i class="bx bx-copy"></i></button>';
            echo '</div></div>';
        }
    }
    echo '</div>';

    echo '</div>';

} catch (Exception $e) {
    http_response_code(500);
    echo '<div class="text-center text-danger py-3"><i class="bx bx-error-circle fs-4 mb-2 d-block"></i>' . htmlspecialchars($e->getMessage()) . '</div>';
}
