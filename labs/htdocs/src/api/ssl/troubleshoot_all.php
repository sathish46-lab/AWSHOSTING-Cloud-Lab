<?php
/**
 * GET /api/ssl/troubleshoot_all
 * Checks all user certificates in real-time and returns a summary.
 */
require_once "../../load.php";
require_once "../../lib/core/SSLManager.class.php";

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User session invalid']);
    exit;
}

try {
    $ssl = new SSLManager();
    $certs = $ssl->getCertificates($user->getUserId());
    $dm = new DomainManager();
    $serverIP = $dm->getServerIP();

    $findings = [];
    $highCount = 0;

    foreach ($certs as $cert) {
        $result = $ssl->troubleshootCertificate($cert['main_domain'], $user->getUserId());

        if (isset($result['error'])) {
            $findings[] = [
                'domain' => $cert['main_domain'],
                'status' => 'error',
                'message' => $result['error'],
                'issues' => 1
            ];
            $highCount++;
            continue;
        }

        $issuesFound = $result['issues_found'] ?? 0;
        $status = $issuesFound === 0 ? 'ok' : ($issuesFound <= 2 ? 'warning' : 'error');

        $findings[] = [
            'domain' => $cert['main_domain'],
            'status' => $status,
            'resolver' => $cert['resolver'],
            'total_sans' => $result['total_sans'] ?? count($cert['sans']),
            'issues' => $issuesFound,
            'details' => $result['domains'] ?? [],
            'used_by' => $cert['used_by'] ?? null,
            'expires' => $cert['expires'] ?? null,
            'days_left' => $cert['days_left'] ?? null
        ];

        if ($status === 'error') {
            $highCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'findings' => $findings,
        'checked_at' => time(),
        'cert_count' => count($certs),
        'high' => $highCount
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
