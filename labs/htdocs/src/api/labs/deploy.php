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
    
    $rabbit = new RabbitClient();
    $log_topic = "logs." . $instanceHash;
    
    // Flat structure: query by instance_hash directly
    $inst = $col->findOne(['instance_hash' => $instanceHash]);
    $existing = $inst ?: null;

    // 1. CAPTURE NEW UI FIELDS
    $user_domains = $_POST['domains'] ?? []; 
    $expose_web = (isset($_POST['expose_web']) && filter_var($_POST['expose_web'], FILTER_VALIDATE_BOOLEAN));

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
    
    // Compute docker_ip from internal_ip (last octet)
    // Uses labs_bridge network subnet 172.30.0.0/16
    $computeDockerIp = function($ip) {
        if (!$ip) return null;
        $parts = explode('.', $ip);
        $lastOctet = (int)$parts[3];
        // Avoid gateway IP (.1) — use .2 instead for first lab
        if ($lastOctet <= 1) $lastOctet = 2;
        return '172.30.0.' . $lastOctet;
    };

    if (!$existing || empty($existing['internal_ip'])) {
        // First deploy — reserve IP
        $selectedIp = $_POST['internal_ip'] ?? null;
        if ($selectedIp && $selectedIp !== 'new') {
            $internalIp = $ipManager->reserve($email, $user->getUserId(), $selectedIp);
        } else {
            $internalIp = $ipManager->reserve($email, $user->getUserId());
        }
        $dockerIp = $computeDockerIp($internalIp);
        
        $updateResult = $col->updateOne(
            ['instance_hash' => $instanceHash],
            ['$set' => [
                'user_id'       => $user->getUserId(),
                'email'         => $email,
                'username'      => $user->getUsername(),
                'instance_hash' => $instanceHash,
                'lab_type'      => $labName,
                'internal_ip'   => $internalIp, 
                'docker_ip'     => $dockerIp,
                'tunnel_ip'     => $internalIp,
                'domains'       => $user_domains,
                'code_domain'   => $code_domain,
                'gui_domain'    => $gui_domain,
                'expose_web'    => $expose_web,
                'http_proxies'  => $httpProxies,
                'status'        => 'deploying',
                'created_at'    => time(),
                'storage_path'  => get_config('storage_base') . "/" . md5($user->getEmail())
            ],
            '$push' => [
                'activity_log'  => [
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
        // Redeploy — check if user selected different IP
        $selectedIp = $_POST['internal_ip'] ?? null;
        if ($selectedIp && $selectedIp !== 'new' && $selectedIp !== $existing['internal_ip']) {
            $internalIp = $ipManager->reserve($email, $user->getUserId(), $selectedIp);
        } else {
            if ($selectedIp === 'new') {
                $internalIp = $ipManager->reserve($email, $user->getUserId());
            } else {
                $internalIp = $existing['internal_ip'];
            }
        }
        $dockerIp = $computeDockerIp($internalIp);

        $col->updateOne(
            ['instance_hash' => $instanceHash],
            ['$set' => [
                'domains'      => $user_domains, 
                'expose_web'   => $expose_web,
                'code_domain'  => $code_domain,
                'gui_domain'   => $gui_domain,
                'http_proxies' => $httpProxies,
                'storage_path' => get_config('storage_base') . "/" . md5($user->getEmail()),
                'internal_ip'  => $internalIp,
                'docker_ip'    => $dockerIp,
                'tunnel_ip'    => $internalIp,
                'status'       => 'deploying'
            ],
            '$push' => [
                'activity_log' => [
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

    // UPDATE DOMAIN INVENTORY STATUS
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

    // Trigger Python Orchestrator via QUEUE
    $work = [
        'action' => 'deploy',
        'lab' => $labName, 
        'hash' => $instanceHash, 
        'user' => $user->getUsername(),
        'vsc_domain' => $code_domain,
    ];

    $rabbit->sendToQueue('labs_jobs', $work);
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Deployment queued',
        'hash' => $instanceHash
    ]);

} catch (Exception $e) {
    error_log("Deploy API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}