<?php
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../lib/core/jobs/Process.class.php';
require_once __DIR__ . '/../../lib/core/jobs/Worker.class.php';
require_once __DIR__ . '/../../lib/core/RabbitClient.class.php';

use TomLabs\Labs\IPManager;

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$email = $user->getEmail();
$labName = $_POST['lab'] ?? 'essentials';
$instanceHash = $user->getLabHash($labName); 

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $col = $db->machine_labs;
    
    $ipManager = new IPManager();
    
    $rabbit = new RabbitClient(); // Defaults to amq.topic
    $log_topic = "logs." . $instanceHash;
    // We can use $rabbit->sendMessage($msg, $log_topic) if we want to send logs from PHP
    $inst = $col->findOne(['deploy.instance_hash' => $instanceHash]);
    $existing = $inst ? ($inst['deploy'] ?? []) : null;

    // 1. CAPTURE NEW UI FIELDS
    $user_domains = $_POST['domains'] ?? []; 
    $expose_web = (isset($_POST['expose_web']) && filter_var($_POST['expose_web'], FILTER_VALIDATE_BOOLEAN));

    // FIX: If user provides domains, they implicitly want to expose web
    if (!empty($user_domains)) {
        $expose_web = true;
    }
    $code_domain = (!empty($_POST['code_domain'])) ? $_POST['code_domain'] : ($instanceHash . ".tomweb.shop");
    $gui_domain = (!empty($_POST['gui_domain_selector'])) ? $_POST['gui_domain_selector'] : ("gui-" . $instanceHash . ".tomweb.shop");
    
    // HTTP Proxies
    $httpProxies = $existing['http_proxies'] ?? [];
    if (\TomLabs\Labs\LabFeatures::supports($labName, 'http_proxies')) {
        if (isset($_POST['deploy_proxy_port']) && is_array($_POST['deploy_proxy_port']) && isset($_POST['deploy_proxy_domain']) && is_array($_POST['deploy_proxy_domain'])) {
            $httpProxies = [];
            foreach ($_POST['deploy_proxy_port'] as $idx => $port) {
                $port = (int)$port;
                $domain = trim((string)($_POST['deploy_proxy_domain'][$idx] ?? ''));
                if ($port > 0 && $port <= 65535 && !empty($domain)) {
                    $httpProxies[] = [
                        'port' => $port,
                        'domain' => $domain
                    ];
                }
            }
        }
    }
    
    // error_log("PHPLOG: User selected domain: " . $code_domain);

    if (!$existing || empty($existing['internal_ip'])) {
        // Reserve IP for this lab — use selected IP if provided, otherwise auto-assign
        $selectedIp = $_POST['internal_ip'] ?? null;
        if ($selectedIp && $selectedIp !== 'new') {
            $internalIp = $ipManager->reserve($email, $user->getUserId(), $selectedIp);
        } else {
            $internalIp = $ipManager->reserve($email, $user->getUserId());
        }
        
        $updateResult = $col->updateOne(
            ['deploy.instance_hash' => $instanceHash],
            ['$set' => [
                'deploy.user_id'       => $user->getUserId(),
                'deploy.email'         => $email,
                'deploy.username'      => $user->getUsername(),
                'deploy.instance_hash' => $instanceHash,
                'deploy.lab_type'      => $labName,
                'deploy.internal_ip'   => $internalIp, 
                'deploy.domains'       => $user_domains,
                'deploy.code_domain'   => $code_domain,
                'deploy.gui_domain'    => $gui_domain,
                'deploy.expose_web'    => $expose_web,
                'deploy.http_proxies'  => $httpProxies,
                'deploy.status'        => 'deploying',
                'deploy.created_at'    => time(),
                'deploy.storage_path'  => "/var/labsstorage/" . $user->getUsername()
            ],
            '$push' => [
                'deploy.activity_log'  => [
                    '$each' => [
                        [
                            'action' => 'Deployed',
                            'user' => $user->getUsername(),
                            'timestamp' => time(),
                            'type' => 'lab'
                        ]
                    ],
                    '$position' => 0
                ]
            ]],
            ['upsert' => true]
        );
        
        if (!$updateResult) { 
            throw new Exception('Failed to create lab record'); 
        }
        
    } else {
        $internalIp = $existing['internal_ip'];
        
        // Update domains, expose_web, AND code_domain on redeploy
        $col->updateOne(
            ['deploy.instance_hash' => $instanceHash],
            ['$set' => [
                'deploy.domains'     => $user_domains, 
                'deploy.expose_web'  => $expose_web,
                'deploy.code_domain' => $code_domain,
                'deploy.gui_domain'  => $gui_domain,
                'deploy.http_proxies'=> $httpProxies,
                'deploy.storage_path'=> "/var/labsstorage/" . $user->getUsername(),
                'deploy.status'      => 'deploying'
            ],
            '$push' => [
                'deploy.activity_log' => [
                    '$each' => [
                        [
                            'action' => 'Redeployed',
                            'user' => $user->getUsername(),
                            'timestamp' => time(),
                            'type' => 'lab'
                        ]
                    ],
                    '$position' => 0,
                    '$slice' => 50
                ]
            ]]
        );
    }

    // ============================================================
    // UPDATE DOMAIN INVENTORY STATUS
    // ============================================================
    $domainsCol = $db->domains;
    $domainsCol->updateMany(
        ['user_id' => $user->getUserId()], 
        ['$set' => ['in_use' => false]]
    );

    if (!empty($user_domains) && $expose_web) {
        $domainsCol->updateMany(
            ['domain' => ['$in' => $user_domains], 'user_id' => $user->getUserId()],
            ['$set' => ['in_use' => true]]
        );
    }

    // 4. Trigger the Python Orchestrator via QUEUE (Scalable)
    $work = [
        'action' => 'deploy',
        'lab' => $labName, 
        'hash' => $instanceHash, 
        'user' => $user->getUsername(),
        'vsc_domain' => $code_domain,
        'gui_domain' => $gui_domain
    ];

    // Capture MinIO specific domains if present
    if (!empty($_POST['minio_console_domain'])) {
        $work['minio_console_domain'] = $_POST['minio_console_domain'];
    }
    if (!empty($_POST['minio_api_domain'])) {
        $work['minio_api_domain'] = $_POST['minio_api_domain'];
    }
    if (!empty($_POST['n8n_domain'])) {
        $work['n8n_domain'] = $_POST['n8n_domain'];
    }
    
    // Create RabbitMQ Client for the Job Queue
    // We reuse the existing client or create a specific one if needed. 
    // The existing $rabbit was for logs, but we can reuse the connection for the queue.
    $rabbit->sendToQueue('labs_jobs', $work);
    
    // Response (Immediate "Queued" status)
    echo json_encode([
        'status' => 'success',
        'message' => 'Employment queued', 
        'hash'   => $instanceHash,
        'ip'     => $internalIp,
        'queued' => true
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}