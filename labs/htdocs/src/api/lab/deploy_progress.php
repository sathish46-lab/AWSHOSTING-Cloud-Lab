<?php
/**
 * Deployment Progress API
 * GET /api/lab/deploy_progress.php?hash=INSTANCE_HASH
 * 
 * Returns real-time deployment progress for a lab.
 */

require_once __DIR__ . '/../../load.php';

use TomLabs\Core\{Auth, DatabaseConnection, Response};

// Set JSON content type
header('Content-Type: application/json');

try {
    // Require authentication
    $user = Auth::requireLogin();
    
    // Get instance hash from request
    $hash = $_GET['hash'] ?? null;
    
    if (empty($hash)) {
        Response::error('Missing hash parameter', 400);
        exit;
    }
    
    // Get database
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    
    // Find lab instance
    $lab = $db->machine_labs->findOne([
        'instance_hash' => $hash,
        'user_id' => $user['user_id']
    ]);
    
    if (!$lab) {
        Response::error('Lab not found', 404);
        exit;
    }
    
    $deployLog = $lab['deploy_log'] ?? [];
    $logs = $deployLog['logs'] ?? [];
    $status = $deployLog['status'] ?? '';
    $labStatus = $lab['status'] ?? 'unknown';
    
    // Deployment progress patterns (same as Python parser)
    $steps = [
        // (pattern, percentage, label)
        ['Deployment initiated', 5, 'Initializing'],
        ['Fetching lab metadata', 8, 'Loading metadata'],
        ['Starting deployment for user', 10, 'Starting deployment'],
        ['Instance ID:', 12, 'Preparing instance'],
        ['Reusing existing lab IP|Assigned Docker IP', 15, 'Assigning IP'],
        ['Checking for conflicting containers', 18, 'Checking containers'],
        ['No existing container found|Removing existing container', 20, 'Cleaning up'],
        ['Storage preserved', 25, 'Preserving storage'],
        ['Clearing stale VPN sessions', 28, 'Clearing VPN sessions'],
        ['Removing stale WireGuard peer', 30, 'Removing old peer'],
        ['Peer removed', 32, 'Peer removed'],
        ['Reusing existing keys|Generating new keys', 35, 'Configuring keys'],
        ['Peer re-registered', 38, 'Re-registering peer'],
        ['Provisioning', 40, 'Provisioning lab'],
        ['Waiting for container services', 45, 'Starting container'],
        ['Configuring network routing', 50, 'Configuring network'],
        ['Routing and firewall configured', 55, 'Firewall ready'],
        ['Optimizing Apache', 58, 'Configuring Apache'],
        ['Configuring user environment', 60, 'Setting up user'],
        ['Syncing ssh authorized_keys', 62, 'Syncing SSH keys'],
        ['Starting user configuration', 65, 'Creating user'],
        ['User .* created', 68, 'User created'],
        ['System password set', 70, 'Password set'],
        ['SSH configured and reloaded', 72, 'SSH ready'],
        ['Bash environment configured', 74, 'Shell configured'],
        ['Configuring WireGuard tunnel', 76, 'Setting up VPN'],
        ['WireGuard configured', 80, 'VPN ready'],
        ['Configuring persistent storage', 82, 'Linking storage'],
        ['Storage links configured', 85, 'Storage ready'],
        ['Setting up Code-Server', 88, 'Starting Code-Server'],
        ['Code-server started', 90, 'Code-Server ready'],
        ['Applying firewall rules', 92, 'Applying firewall'],
        ['Firewall rules applied', 94, 'Firewall ready'],
        ['Finalizing Traefik routing', 96, 'Configuring proxy'],
        ['Traefik config written', 98, 'Proxy configured'],
        ['Deployment Complete|Deploy complete', 100, 'Complete'],
        ['Apache routes added', 99, 'Routes added'],
        ['Access URL:', 100, 'Deployment complete'],
    ];
    
    // Parse logs for progress
    $highestProgress = 0;
    $currentLabel = 'Starting';
    
    foreach ($logs as $logLine) {
        foreach ($steps as [$pattern, $percentage, $label]) {
            if (preg_match('/' . $pattern . '/i', $logLine)) {
                if ($percentage > $highestProgress) {
                    $highestProgress = $percentage;
                    $currentLabel = $label;
                }
            }
        }
    }
    
    // Determine status
    if ($status === 'success' || $labStatus === 'running') {
        $progress = 100;
        $label = 'Complete';
        $status = 'completed';
    } elseif ($status === 'failed') {
        $progress = $highestProgress;
        $label = 'Failed';
        $status = 'failed';
    } else {
        $progress = $highestProgress;
        $label = $currentLabel;
        $status = ($progress >= 100) ? 'completed' : 'running';
    }
    
    // Return response
    Response::json([
        'instance_hash' => $hash,
        'progress' => $progress,
        'label' => $label,
        'status' => $status,
        'current_step' => $progress . '% - ' . $label,
        'total_steps' => count($logs),
        'recent_logs' => array_slice($logs, -10),
    ]);
    
} catch (Exception $e) {
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}
