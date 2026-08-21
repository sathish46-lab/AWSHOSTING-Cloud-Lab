<?php
// /var/www/labs/htdocs/src/api/labs/code_status.php
// Lightweight, synchronous check of whether code-server is running for this user.
// Used by the Launch Code IDE button to open the link instantly when already running
// and only trigger the (heavier) ensure-codeserver worker when it is NOT running.
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
$labName = $_POST['lab'] ?? 'essentials';

try {
    $instanceHash = $user->getLabHash($labName);
    $username = $user->getUsername();

    $python  = '/usr/bin/python3';
    $script  = '/opt/labs-control-panel/labsctl.py';
    $cmd = sprintf(
        'timeout 15 sudo %s %s lab code-status --user=%s --hash=%s 2>&1',
        $python,
        $script,
        escapeshellarg($username),
        escapeshellarg($instanceHash)
    );

    $output = [];
    exec($cmd, $output, $exitCode);

    // Parse the last stdout line that looks like our JSON result.
    $running = false;
    foreach (array_reverse($output) as $line) {
        $line = trim($line);
        if (strncmp($line, '{', 1) === 0) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['codeserver_running'])) {
                $running = (bool) $decoded['codeserver_running'];
            }
            break;
        }
    }

    // Fresh URL (code_server_url is stored under credentials in the deploy doc).
    $latestData = $user->getLabData($labName);
    $freshUrl = (is_array($latestData) && isset($latestData['credentials']['code_server_url']))
        ? $latestData['credentials']['code_server_url']
        : "";

    echo json_encode([
        'status'  => 'success',
        'running' => $running,
        'url'     => $freshUrl,
        'hash'    => $instanceHash,
    ]);

} catch (Exception $e) {
    // Never break the launch flow — report not-running so the UI falls back to ensure.
    echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'running' => false]);
}
