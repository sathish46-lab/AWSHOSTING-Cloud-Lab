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

    if (!$inst) throw new Exception('Lab not found');
    if (($inst['user_id'] ?? null) !== $user->getUserId()) throw new Exception('Unauthorized');

    $labType = $inst['lab_type'] ?? 'essentials';
    $creds = (array)($inst['credentials'] ?? []);
    $password = $creds['password'] ?? '********';
    $username = $creds['username'] ?? $user->getUsername() ?? 'user';

    // Default lab type configs
    $labConfigs = [
        'essentials' => [
            'title' => 'Visual Studio Code on Web',
            'description' => 'Ubuntu 24.10 environment packed with all the essentials to code, host, build, and develop.',
            'features' => [
                'Code Effortlessly on Browser',
                'Browse the filesystem and do CRUD',
                'Access linux shell CLI',
                'Develop effortlessly on the go'
            ],
            'instruction' => 'You need this password in the next screen to login to VS Code on Web - Happy Coding!',
            'fields' => [
                ['label' => 'Code Server Password', 'value' => $password, 'type' => 'password']
            ],
            'actionLabel' => 'Launch Code IDE',
            'actionLink' => "https://{$hash}.tomweb.shop",
            'actionIcon' => 'bx-code-alt'
        ],
        'docker_lab' => [
            'title' => 'Visual Studio Code on Web',
            'description' => 'Ubuntu 24.10 environment equipped with full Docker-in-Docker capabilities.',
            'features' => [
                'Code Effortlessly on Browser',
                'Browse the filesystem and do CRUD',
                'Access linux shell CLI',
                'Docker-in-Docker Support'
            ],
            'instruction' => 'You need this password in the next screen to login to VS Code on Web - Happy Coding!',
            'fields' => [
                ['label' => 'Code Server Password', 'value' => $password, 'type' => 'password']
            ],
            'actionLabel' => 'Launch Code IDE',
            'actionLink' => "https://{$hash}.tomweb.shop",
            'actionIcon' => 'bx-code-alt'
        ],
        'minio' => [
            'title' => 'Launch MinIO S3',
            'description' => 'MinIO is a high-performance, S3-compatible object storage solution for machine learning, analytics, and application data workloads, released under the GNU AGPL v3.0.',
            'features' => [],
            'instruction' => 'You need these credentials in the next screen to login.',
            'fields' => [
                ['label' => 'Username', 'value' => $username, 'type' => 'text'],
                ['label' => 'MinIO S3 Password', 'value' => $creds['minio_secret_key'] ?? $password, 'type' => 'password']
            ],
            'actionLabel' => 'Launch',
            'actionLink' => "https://s3-{$hash}.tomweb.shop",
            'actionIcon' => 'bx-cloud'
        ],
        'n8n' => [
            'title' => 'Launch n8n Workflow',
            'description' => 'n8n is an extendable workflow automation tool with a fair-code license. Self-hosted control over your data.',
            'features' => [],
            'instruction' => 'You need these credentials in the next screen to login.',
            'fields' => [
                ['label' => 'Username', 'value' => $username, 'type' => 'text'],
                ['label' => 'n8n Password', 'value' => $creds['n8n_password'] ?? $password, 'type' => 'password']
            ],
            'actionLabel' => 'Launch',
            'actionLink' => "https://n8n-{$hash}.tomweb.shop",
            'actionIcon' => 'bx-network-chart'
        ],
        'gui_essentials' => [
            'title' => 'Launch Ubuntu Jammy LTS',
            'description' => 'Ubuntu Desktop GUI Lab: Code, host, build, develop with root access over the browser.',
            'features' => [],
            'instruction' => 'You need these credentials in the next screen to login.',
            'fields' => [
                ['label' => 'Username', 'value' => $username, 'type' => 'text'],
                ['label' => 'Ubuntu Jammy LTS Password', 'value' => $creds['vnc_password'] ?? $password, 'type' => 'password']
            ],
            'actionLabel' => 'Launch',
            'actionLink' => "https://vnc-{$hash}.tomweb.shop",
            'actionIcon' => 'bx-desktop'
        ]
    ];

    $config = $labConfigs[$labType] ?? $labConfigs['essentials'];

    // Render modal content
    echo '<div class="p-1">';
    
    // Description
    echo '<p class="text-white-50 small mb-3">' . htmlspecialchars($config['description']) . '</p>';

    // Features list (if any)
    if (!empty($config['features'])) {
        echo '<h6 class="fw-bold text-white small mb-2">What can you do here?</h6>';
        echo '<ul class="text-white-50 small ps-3 mb-3">';
        foreach ($config['features'] as $feature) {
            echo '<li>' . htmlspecialchars($feature) . '</li>';
        }
        echo '</ul>';
    }

    // Instruction
    echo '<div class="mb-3">';
    echo '<small class="text-white-50 fw-bold">' . htmlspecialchars($config['instruction']) . '</small>';
    echo '</div>';

    // Credential fields
    foreach ($config['fields'] as $field) {
        echo '<div class="mb-3">';
        echo '<label class="form-label text-white-50 small fw-bold mb-1">' . htmlspecialchars($field['label']) . '</label>';
        echo '<div class="input-group">';
        echo '<input type="' . $field['type'] . '" class="form-control form-control-sm bg-dark text-white border-secondary font-monospace" value="' . htmlspecialchars($field['value']) . '" readonly>';
        echo '<button class="btn btn-sm btn-outline-secondary border-secondary" type="button" onclick="copyToClipboard(this)" data-copy="' . htmlspecialchars($field['value']) . '"><i class="bx bx-copy"></i></button>';
        echo '</div></div>';
    }

    // Tip
    echo '<div class="d-flex align-items-start gap-2 mb-3">';
    echo '<i class="bx bx-info-circle text-info mt-1"></i>';
    echo '<small class="text-white-50"><strong>Tip:</strong> If login fails with the password above or the launcher doesn\'t work, redeploy and try again.</small>';
    echo '</div>';

    // Action buttons
    echo '<div class="d-flex gap-2 justify-content-end">';
    echo '<a href="' . htmlspecialchars($config['actionLink']) . '" target="_blank" class="btn btn-success fw-bold px-4 rounded-pill">';
    echo '<i class="bx ' . htmlspecialchars($config['actionIcon']) . ' me-1"></i> ' . htmlspecialchars($config['actionLabel']);
    echo '</a>';
    echo '<button type="button" class="btn btn-secondary fw-bold px-4 rounded-pill" data-coreui-dismiss="modal">Dismiss</button>';
    echo '</div>';

    echo '</div>';

} catch (Exception $e) {
    http_response_code(500);
    echo '<div class="text-center text-danger py-3"><i class="bx bx-error-circle fs-4 mb-2 d-block"></i>' . htmlspecialchars($e->getMessage()) . '</div>';
}
