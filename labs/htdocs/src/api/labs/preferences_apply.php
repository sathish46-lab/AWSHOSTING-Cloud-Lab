<?php
/**
 * POST /api/labs/preferences_apply
 * Save preferences AND queue an apply-preferences job via RabbitMQ.
 * This updates Traefik routing and runs init.sh without a full container redeploy.
 */
require_once __DIR__ . '/../../../src/load.php';
require_once __DIR__ . '/../../lib/core/jobs/Process.class.php';
require_once __DIR__ . '/../../lib/core/jobs/Worker.class.php';
require_once __DIR__ . '/../../lib/core/RabbitClient.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['hash'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing required fields']); exit;
}

$labName = $input['lab'] ?? 'essentials';
$instanceHash = $user->getLabHash($labName);

if ($instanceHash !== $input['hash']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Hash mismatch']); exit;
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $col = $db->machine_labs;

    $inst = $col->findOne(['deploy.instance_hash' => $instanceHash]);
    $existing = $inst ? ($inst['deploy'] ?? []) : null;
    if (!$existing) {
        throw new Exception('Lab not found. Please deploy first.');
    }

    // Check if the lab is actually running
    $status = $existing['status'] ?? 'offline';
    if ($status !== 'running') {
        throw new Exception('Lab is not running. Start or redeploy your lab first.');
    }

    // Sanitize HTTP proxies
    $httpProxies = [];
    if (!\TomLabs\Labs\LabTemplateConfig::supportsFeature($labName, 'http_proxies')) {
        $httpProxies = $existing['http_proxies'] ?? [];
    } elseif (isset($input['http_proxies']) && is_array($input['http_proxies'])) {
        foreach ($input['http_proxies'] as $proxy) {
            $port = isset($proxy['port']) ? (int)$proxy['port'] : 0;
            $domain = isset($proxy['domain']) ? trim((string)$proxy['domain']) : '';
            if ($port > 0 && $port <= 65535 && !empty($domain)) {
                $httpProxies[] = [
                    'port' => $port,
                    'domain' => $domain
                ];
            }
        }
    }

    // Sanitize always_on
    if (!\TomLabs\Labs\LabTemplateConfig::supportsFeature($labName, 'always_on')) {
        $alwaysOn = $existing['always_on'] ?? false;
    } else {
        $alwaysOn = !empty($input['always_on']);
    }

    if (!\TomLabs\Labs\LabTemplateConfig::supportsFeature($labName, 'startup_script')) {
        $initScript = $existing['init_script'] ?? '#!/bin/bash';
    } else {
        $initScript = isset($input['init_script']) ? (string)$input['init_script'] : '#!/bin/bash';
    }

    // Build details string for activity log by comparing with existing data
    $changedDetails = [];
    
    // Check HTTP Proxies
    $oldProxies = $existing['http_proxies'] ?? [];
    // Normalize arrays for comparison
    $oldProxiesJson = json_encode($oldProxies);
    $newProxiesJson = json_encode($httpProxies);
    if ($oldProxiesJson !== $newProxiesJson) {
        $changedDetails[] = "HTTP Proxies";
    }
    
    // Check Always-on
    $oldAlwaysOn = !empty($existing['always_on']);
    if ($alwaysOn !== $oldAlwaysOn) {
        $changedDetails[] = "Always-On State";
    }
    
    // Check Init Script
    $oldInit = $existing['init_script'] ?? '#!/bin/bash';
    if ($initScript !== $oldInit) {
        $changedDetails[] = "Init Script";
    }
    
    // Check Sudo Password
    if (isset($input['su_pass'])) {
        $staged = $existing['staged_preferences'] ?? [];
        $oldSuPass = $staged['su_pass'] ?? '';
        if (trim((string)$input['su_pass']) !== $oldSuPass) {
            $changedDetails[] = "Sudo Password";
        }
    }
    
    // Check Code-Server Password
    if (isset($input['code_server_pass'])) {
        $staged = $existing['staged_preferences'] ?? [];
        $oldCodePass = $staged['code_server_pass'] ?? '';
        if (trim((string)$input['code_server_pass']) !== $oldCodePass) {
            $changedDetails[] = "Code-Server Password";
        }
    }
    
    $detailsString = !empty($changedDetails) ? "Changes: " . implode(", ", $changedDetails) : "Applied Preferences";

    // 1. Save to DB first
    $col->updateOne(
        ['deploy.instance_hash' => $instanceHash],
        [
            '$set' => [
                'deploy.http_proxies' => $httpProxies,
                'deploy.always_on'    => $alwaysOn,
                'deploy.init_script'  => $initScript,
                'deploy.prefs_updated_at' => time(),
                'deploy.staged_preferences.su_pass' => $updateData['staged_preferences.su_pass'] ?? null,
                'deploy.staged_preferences.code_server_pass' => $updateData['staged_preferences.code_server_pass'] ?? null,
                'deploy.staged_preferences.password' => $updateData['staged_preferences.password'] ?? null,
            ],
            '$push' => [
                'deploy.activity_log' => [
                    '$each' => [
                        [
                            'action' => 'Fast Apply',
                            'user' => $user->getUsername(),
                            'timestamp' => time(),
                            'type' => 'preference',
                            'details' => $detailsString
                        ]
                    ],
                    '$position' => 0,
                    '$slice' => 50
                ]
            ]
        ]
    );

    // 2. Queue an apply-preferences job via RabbitMQ
    $work = [
        'action' => 'apply-preferences',
        'lab'    => $labName,
        'hash'   => $instanceHash,
        'user'   => $user->getUsername()
    ];

    $rabbit = new RabbitClient();
    $rabbit->sendToQueue('labs_jobs', $work);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Apply job queued',
        'hash'    => $instanceHash,
        'queued'  => true
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
