<?php
    $fullHash = Session::get('full_instance_hash');
    $db = DatabaseConnection::getDefaultDatabase();

    $inst = $db->machine_labs->findOne(
        ['instance_hash' => $fullHash],
        ['projection' => ['lab_type' => 1, 'status' => 1, 'activity_log' => 1, 'deploy_log' => 1, 'deploy_history' => 1]]
    );
    $labData = $inst;
    $user = Session::getUser();
    $currentUsername = $user->getUsername();

    $labType = 'essentials';
    if ($fullHash === $user->getLabHash('minio')) {
        $labType = 'minio';
    } elseif ($fullHash === $user->getLabHash('n8n')) {
        $labType = 'n8n';
    } elseif ($fullHash === $user->getLabHash('docker_lab')) {
        $labType = 'docker_lab';
    } elseif ($fullHash === $user->getLabHash('gui_essentials')) {
        $labType = 'gui_essentials';
    }
    $labType = $labData['lab_type'] ?? $labType;

    if (!$labData) {
        $status = 'not_deployed';
    } else {
        $status = $labData['status'] ?? 'offline';
    }
    $isRunning = ($status === 'running');

    $uiConfigs = [
        'essentials' => ['title' => 'Essentials Lab', 'desc' => 'Ubuntu 24.10 environment for general development.', 'icon' => 'bxl-tux', 'color' => '#E95420', 'action' => 'Code', 'action_icon' => 'bx-code-alt'],
        'minio' => ['title' => 'MinIO S3 Storage', 'desc' => 'MinIO is a high-performance, S3-compatible object storage solution.', 'icon' => 'bxl-docker', 'color' => '#00a6e0', 'action' => 'Launch', 'action_icon' => 'bx-cloud'],
        'n8n' => ['title' => 'n8n Workflow Automation', 'desc' => 'n8n is an extendable workflow automation tool.', 'icon' => 'bx-git-repo-forked', 'color' => '#ea4b71', 'action' => 'Launch', 'action_icon' => 'bx-network-chart'],
        'docker_lab' => ['title' => 'Tom Docker Lab', 'desc' => 'Ubuntu 24.10 with full Docker-in-Docker capabilities.', 'icon' => 'bxl-docker', 'color' => '#2496ed', 'action' => 'Code', 'action_icon' => 'bx-code-alt'],
        'gui_essentials' => ['title' => 'GUI Essentials Lab', 'desc' => 'Ubuntu 24.10 with XFCE4 desktop, VNC GUI access, and code-server.', 'icon' => 'bx-desktop', 'color' => '#8b5cf6', 'action' => 'VNC', 'action_icon' => 'bx-desktop'],
    ];
    $cfg = $uiConfigs[$labType] ?? $uiConfigs['essentials'];

    $activityLog = [];
    if (isset($labData['activity_log'])) {
        foreach ($labData['activity_log'] as $logItem) {
            $activityLog[] = (array)$logItem;
        }
    }

    $deployHistory = [];
    if (isset($labData['deploy_history'])) {
        foreach ($labData['deploy_history'] as $dh) {
            $deployHistory[] = (array)$dh;
        }
    } elseif (isset($labData['deploy_log'])) {
        $dl = (array)$labData['deploy_log'];
        if (!empty($dl)) {
            $deployHistory[] = $dl;
        }
    }

    function timeAgo($time_ago) {
        $time_difference = time() - $time_ago;
        if ($time_difference < 1) { return 'just now'; }
        $condition = [
            12 * 30 * 24 * 60 * 60 => 'year',
            30 * 24 * 60 * 60      => 'month',
            24 * 60 * 60           => 'day',
            60 * 60                => 'hour',
            60                     => 'minute',
            1                      => 'second'
        ];
        foreach ($condition as $secs => $str) {
            $d = $time_difference / $secs;
            if ($d >= 1) {
                $t = round($d);
                return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
            }
        }
        return 'just now';
    }
?>

<?php
    $current_page = 'activity';
    include __DIR__ . '/partials/lab_header.php';
?>

<div class="container-fluid py-3 px-3">
    <!-- Deploy Attempts Card -->
    <div class="card border-0 shadow-sm blur rounded-4 mb-4">
        <div class="card-header bg-transparent border-0 p-4 d-flex justify-content-between align-items-center" role="button" data-coreui-toggle="collapse" data-coreui-target="#collapseDeployAttempts" aria-expanded="true" aria-controls="collapseDeployAttempts">
            <h6 class="fw-bold mb-0">Deploy attempts <span class="small text-muted fw-normal ms-1">Outcome of each deploy</span></h6>
            <i class='bx bx-chevron-down fs-4 text-muted'></i>
        </div>
        <div id="collapseDeployAttempts" class="collapse show">
            <div class="card-body p-0">
                <?php if (empty($deployHistory)): ?>
                    <div class="p-4 text-center text-muted small">No deploy attempts recorded yet.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach (array_slice($deployHistory, 0, 20) as $dh):
                            $status = $dh['status'] ?? 'unknown';
                            $createdAt = isset($dh['created_at']) ? (int)$dh['created_at'] : time();
                            $duration = $dh['duration'] ?? null;
                            $exitCode = $dh['exit_code'] ?? null;
                            $deployUser = $dh['user'] ?? $currentUsername;
                            $action = $dh['action'] ?? 'deploy';
                            $isSuccess = ($status === 'success');
                        ?>
                            <div class="list-group-item bg-transparent border-bottom border-opacity-10 py-3 px-4 d-flex align-items-center gap-3">
                                <span class="badge <?= $isSuccess ? 'bg-success' : 'bg-danger' ?> rounded-pill px-3 py-1"><?= $isSuccess ? 'succeeded' : 'failed' ?></span>
                                <span class="text-body small">
                                    <?= timeAgo($createdAt) ?>
                                    <?php if ($duration !== null): ?>
                                        &middot; took <?= htmlspecialchars($duration) ?>
                                    <?php endif; ?>
                                    <?php if ($exitCode !== null): ?>
                                        &middot; exit <?= htmlspecialchars($exitCode) ?>
                                    <?php endif; ?>
                                    &middot; <?= htmlspecialchars($deployUser) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Activity Lifecycle Card -->
    <div class="card border-0 shadow-sm blur rounded-4">
        <div class="card-header bg-transparent border-0 p-4 d-flex justify-content-between align-items-center" role="button" data-coreui-toggle="collapse" data-coreui-target="#collapseActivity" aria-expanded="false" aria-controls="collapseActivity">
            <h6 class="fw-bold mb-0 text-success">Activity <span class="small text-muted fw-normal ms-1">Recent lifecycle</span></h6>
            <i class='bx bx-chevron-down fs-4 text-muted'></i>
        </div>
        <div id="collapseActivity" class="collapse">
            <div class="card-body p-4 border-top border-success border-opacity-10 mt-2">
                <div class="row g-4">
                    <!-- Left Side: Lab Logs -->
                    <div class="col-md-6">
                        <h6 class="fw-bold mb-3 small text-muted text-uppercase">Lab Lifecycle</h6>
                        <?php
                        $labLogs = array_filter($activityLog, function($log) {
                            return isset($log['type']) && $log['type'] === 'lab';
                        });
                        if (empty($labLogs)): ?>
                            <p class="small text-muted mb-0">No recent lab activity.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush bg-transparent">
                                <?php foreach(array_slice($labLogs, 0, 10) as $log):
                                    $actionLower = strtolower($log['action']);
                                    $iconClass = 'bx-refresh text-success';
                                    if (strpos($actionLower, 'stop') !== false) $iconClass = 'bx-power-off text-danger';
                                ?>
                                    <div class="list-group-item bg-transparent border-bottom border-success border-opacity-10 py-2 px-0 d-flex gap-3 align-items-center">
                                        <i class='bx <?= $iconClass ?> fs-5'></i>
                                        <div>
                                            <div class="fw-bold text-body small"><?= htmlspecialchars($log['action']) ?></div>
                                            <div class="text-muted small opacity-75"><?= htmlspecialchars($log['user']) ?> &bull; <?= timeAgo($log['timestamp']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Side: Preferences Logs -->
                    <div class="col-md-6">
                        <h6 class="fw-bold mb-3 small text-muted text-uppercase">Fast Apply (Preferences)</h6>
                        <?php
                        $prefLogs = array_filter($activityLog, function($log) {
                            return isset($log['type']) && $log['type'] === 'preference';
                        });
                        if (empty($prefLogs)): ?>
                            <p class="small text-muted mb-0">No recent preference updates.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush bg-transparent">
                                <?php foreach(array_slice($prefLogs, 0, 10) as $log): ?>
                                    <div class="list-group-item bg-transparent border-bottom border-success border-opacity-10 py-2 px-0 d-flex gap-3 align-items-center">
                                        <i class='bx bx-cog text-primary fs-5'></i>
                                        <div class="overflow-hidden flex-grow-1">
                                            <div class="fw-bold text-body small text-truncate"><?= htmlspecialchars($log['action']) ?></div>
                                            <div class="text-muted small opacity-75 mb-1 text-truncate"><?= htmlspecialchars($log['user']) ?> &bull; <?= timeAgo($log['timestamp']) ?></div>
                                            <div class="small text-secondary stat-desc-wrap">
                                                <?= ucfirst(strtolower(htmlspecialchars($log['details'] ?? 'Applied Preferences'))) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
