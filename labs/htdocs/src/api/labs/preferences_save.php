<?php
/**
 * POST /api/labs/preferences_save
 * Save preferences (http_proxies, always_on, init_script) to MongoDB without triggering a redeploy.
 */
require_once __DIR__ . '/../../../src/load.php';

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

// Validate the hash matches
if ($instanceHash !== $input['hash']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Hash mismatch']); exit;
}

try {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $col = $db->machine_labs;

    $inst = $col->findOne(['deploy.instance_hash' => $instanceHash]);
    $existing = $inst ? ($inst['deploy'] ?? []) : null;
    $status = $existing['status'] ?? 'not_deployed';

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

    // Sanitize init_script
    if (!\TomLabs\Labs\LabTemplateConfig::supportsFeature($labName, 'startup_script')) {
        $initScript = $existing['init_script'] ?? '#!/bin/bash';
    } else {
        $initScript = isset($input['init_script']) ? (string)$input['init_script'] : '#!/bin/bash';
    }

    $changes = [
        'proxies' => false,
        'init_script' => false,
        'passwords' => false
    ];

    // Check what changed
    if (json_encode($existing['http_proxies'] ?? []) !== json_encode($httpProxies)) {
        $changes['proxies'] = true;
    }
    if (($existing['init_script'] ?? '') !== $initScript) {
        $changes['init_script'] = true;
    }

    $setFields = [
        'deploy.http_proxies' => $httpProxies,
        'deploy.always_on'    => $alwaysOn,
        'deploy.init_script'  => $initScript,
        'deploy.prefs_updated_at' => time(),
    ];

    if (isset($input['su_pass'])) {
        $setFields['deploy.staged_preferences.su_pass'] = trim((string)$input['su_pass']);
        $currentSuPass = $existing['staged_preferences']['su_pass'] ?? $existing['credentials']['su_pass'] ?? '';
        if ($currentSuPass !== trim((string)$input['su_pass'])) {
            $changes['passwords'] = true;
        }
    }
    if (isset($input['code_server_pass'])) {
        $setFields['deploy.staged_preferences.code_server_pass'] = trim((string)$input['code_server_pass']);
        $setFields['deploy.staged_preferences.password'] = trim((string)$input['code_server_pass']);
        $currentCodePass = $existing['staged_preferences']['code_server_pass'] ?? $existing['credentials']['code_server_pass'] ?? '';
        if ($currentCodePass !== trim((string)$input['code_server_pass'])) {
            $changes['passwords'] = true;
        }
    }
    if (isset($input['vnc_pass'])) {
        $setFields['deploy.staged_preferences.vnc_pass'] = trim((string)$input['vnc_pass']);
        $currentVncPass = $existing['staged_preferences']['vnc_pass'] ?? $existing['credentials']['vnc_pass'] ?? '';
        if ($currentVncPass !== trim((string)$input['vnc_pass'])) {
            $changes['passwords'] = true;
        }
    }

    $col->updateOne(
        ['deploy.instance_hash' => $instanceHash],
        [
            '$set' => $setFields,
            '$setOnInsert' => [
                'deploy.user_id'       => $user->getUserId(),
                'deploy.email'         => $user->getEmail(),
                'deploy.username'      => $user->getUsername(),
                'deploy.lab_type'      => $labName,
                'deploy.status'        => 'not_deployed',
                'deploy.created_at'    => time()
            ]
        ],
        ['upsert' => true]
    );

    $warning = null;
    if ($status !== 'running') {
        $warning = 'Saved successfully, but you need to deploy or start the lab to run scripts.';
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Preferences saved',
        'hash' => $instanceHash,
        'changes' => $changes,
        'warning' => $warning
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
