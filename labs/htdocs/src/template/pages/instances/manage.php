<?php
// Fetch instance data for this page
$hash = $_GET['slug'] ?? '';
$user = Session::getUser();
$userId = (int)$user->getUserId();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instance = $db->instances->findOne(['instance_hash' => $hash]);
// Fallback: try by slug for old URLs
if (!$instance) {
    $instance = $db->instances->findOne(['slug' => $hash]);
}

$instName = $instance['name'] ?? ucfirst($hash);
$instType = $instance['type'] ?? 'machine';
$instStatus = $instance['status'] ?? 'draft';

// Check for active logs (not expired)
$deploy = $instance['deploy'] ?? [];
$now = time();
$deployLog = $deploy['deploy_log'] ?? null;
$buildLog = $deploy['build_log'] ?? null;
$hasActiveLog = false;
$logStatus = '';
if ($deployLog && !empty($deployLog['logs']) && isset($deployLog['expire_at']) && $now < $deployLog['expire_at']) {
    $hasActiveLog = true;
    $logStatus = $deployLog['status'] ?? 'success';
}
if ($buildLog && !empty($buildLog['logs']) && isset($buildLog['expire_at']) && $now < $buildLog['expire_at']) {
    $hasActiveLog = true;
    if ($logStatus !== 'error') $logStatus = $buildLog['status'] ?? 'success';
}
$instImage = $instance['image'] ?? 'ubuntu:24.04';
$instIcon = $instance['icon'] ?? 'bx-cube-alt';
$instColor = $instance['color'] ?? '#ff416c';
$instSlug = $instance['slug'] ?? $hash;
$instHash = $instance['instance_hash'] ?? $hash;
$instVisibility = $instance['visibility'] ?? 'private';
$instDescription = $instance['description'] ?? '';
$instVersion = $instance['version'] ?? 'v0.0.1';

// Server logs panel minimize state (same as machine labs)
$ui_prefs = Session::getUser()->getUiPreferences() ?? [];
$inst_logs_minimized = isset($ui_prefs['instance_serverlogs_min']) && $ui_prefs['instance_serverlogs_min'] === '1';
$inst_logs_min_class = $inst_logs_minimized ? 'logs-minimized' : '';
$inst_logs_chevron = $inst_logs_minimized ? 'bx-chevron-up' : 'bx-chevron-down';
$inst_logs_data_min = $inst_logs_minimized ? 'true' : 'false';

// Detect active tab from URL or GET param (.htaccess passes tab=)
$activeTab = $_GET['tab'] ?? 'configuration';
$validTabs = ['deployments', 'files', 'configuration', 'build', 'sharing', 'versions'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'configuration';
}

// AJAX tab requests: return only the tab content (not the full page)
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
if ($isAjax) {
    $tabFile = __DIR__ . '/manage/' . $activeTab . '.php';
    if (file_exists($tabFile)) {
        include $tabFile;
    } else {
        echo '<div class="alert alert-warning">Tab not found</div>';
    }
    exit;
}
?>

<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-4 pt-3">
        
        <!-- Top Header Navigation -->
        <!-- <a href="/instances" class="text-decoration-none theme-text d-flex align-items-center gap-2 mb-3 hover-text-primary transition-all small fw-bold">
            <i class='bx bx-left-arrow-alt'></i> Back to Instances
        </a> -->
        
        <!-- Header Block -->
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="text-white rounded-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background-color: <?= htmlspecialchars($instColor) ?> !important;">
                    <i class='bx <?= htmlspecialchars($instIcon) ?> fs-3'></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h3 class="fw-bold theme-text m-0"><?= htmlspecialchars($instName) ?></h3>
                        <span class="badge bg-primary rounded-pill px-2 py-1"><?= htmlspecialchars($instType) ?></span>
                        <?php
                            $statusColor = ($instStatus === 'running') ? 'success' : (($instStatus === 'draft') ? 'warning' : 'danger');
                        ?>
                        <span class="badge bg-<?= $statusColor ?> rounded-pill px-2 py-1"><?= htmlspecialchars($instStatus) ?></span>
                        <?php if ($instVisibility === 'public'): ?>
                        <span class="badge bg-info rounded-pill px-2 py-1">public</span>
                        <?php else: ?>
                        <span class="badge bg-primary rounded-pill px-2 py-1">private</span>
                        <?php endif; ?>
                        <span class="badge bg-primary rounded-pill px-2 py-1"><?= htmlspecialchars($instVersion) ?></span>
                    </div>
                    <div class="d-flex align-items-center gap-2 text-secondary small">
                        Template <span class="text-info font-monospace"><?= htmlspecialchars($instance['template'] ?? 'essentials') ?></span> - <?= htmlspecialchars($instImage) ?>
                        <button type="button" class="btn btn-link text-secondary p-0 border-0 ms-1 copy-hash-btn" data-hash="<?= htmlspecialchars($instHash) ?>" title="Copy Instance ID"><i class='bx bx-copy'></i></button>
                    </div>
                    <?php if (!empty($instDescription)): ?>
                    <div class="text-secondary small mt-1">
                        <?= htmlspecialchars($instDescription) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php
            $hdrDeployStatus = $instance['deploy']['status'] ?? 'none';
            $hdrIsRunning = ($hdrDeployStatus === 'running');
            $hdrCodeUrl = $instance['deploy']['credentials']['code_server_url'] ?? '';
            ?>
            <div class="instance-header-actions">
                <?php if ($hdrIsRunning): ?>
                <div class="btn-instance-group">
                    <a href="<?= htmlspecialchars($hdrCodeUrl ?: '#') ?>" target="_blank"
                       class="btn-instance-seg btn-seg-code <?= empty($hdrCodeUrl) ? 'disabled' : '' ?>">
                        <i class='bx bx-code-alt'></i> Code
                    </a>
                    <button class="btn-instance-seg btn-seg-redeploy">
                        <i class='bx bx-bullseye'></i> Redeploy
                    </button>
                    <button class="btn-instance-seg btn-seg-pause">
                        <i class='bx bx-pause'></i> Pause
                    </button>
                    <button class="btn-instance-seg btn-seg-stop">
                        <i class='bx bx-stop'></i> Stop
                    </button>
                </div>
                <?php else: ?>
                <button class="btn-instance-seg btn-seg-deploy"
                    data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                    <i class='bx bx-play'></i> Deploy
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab Navigation - inside blur header (same as challenges/labs) -->
        <div class="row m-0 p-0 mt-3">
            <ul class="nav nav-tabs lab-nav-tabs border-0" id="instanceTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link d-flex align-items-center gap-2 manage-tab-btn <?= $activeTab === 'deployments' ? 'active' : '' ?>" data-tab="deployments" type="button" role="tab">
                        <i class='bx bx-rocket'></i> Deployments
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link d-flex align-items-center gap-2 manage-tab-btn <?= $activeTab === 'files' ? 'active' : '' ?>" data-tab="files" type="button" role="tab">
                        <i class='bx bx-folder'></i> Files
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link d-flex align-items-center gap-2 manage-tab-btn <?= $activeTab === 'configuration' ? 'active' : '' ?>" data-tab="configuration" type="button" role="tab">
                        <i class='bx bx-cog'></i> Configuration
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link d-flex align-items-center gap-2 manage-tab-btn <?= $activeTab === 'build' ? 'active' : '' ?>" data-tab="build" type="button" role="tab">
                        <i class='bx bx-hammer'></i> Build & validate
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link d-flex align-items-center gap-2 manage-tab-btn <?= $activeTab === 'sharing' ? 'active' : '' ?>" data-tab="sharing" type="button" role="tab">
                        <i class='bx bx-share-alt'></i> Sharing
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link d-flex align-items-center gap-2 manage-tab-btn <?= $activeTab === 'versions' ? 'active' : '' ?>" data-tab="versions" type="button" role="tab">
                        <i class='bx bx-history'></i> Versions
                    </button>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="container-fluid px-3 py-2">

    <!-- Progress Pipeline Bar -->
    <div class="card blur border-0 rounded-3 shadow-sm px-4 py-3 mb-2">
        <div class="d-flex align-items-center gap-3 text-secondary small fw-bold">
            <span class="text-success d-flex align-items-center gap-1">Configure <i class='bx bx-check'></i></span>
            <i class='bx bx-chevron-right fs-5 opacity-50'></i>
            <span class="text-warning d-flex align-items-center gap-1"><span class="badge bg-warning rounded-pill px-2 py-1">2</span> Build & validate</span>
            <i class='bx bx-chevron-right fs-5 opacity-50'></i>
            <span>Deploy</span>
            <i class='bx bx-chevron-right fs-5 opacity-50'></i>
            <span>Share</span>
        </div>
    </div>

    <!-- Dynamic Tab Content Container -->
    <div id="instanceTabsContent">
        <?php
        $tabFile = __DIR__ . '/manage/' . $activeTab . '.php';
        if (file_exists($tabFile)) {
            include $tabFile;
        } else {
            echo '<div class="alert alert-warning">Tab not found: ' . htmlspecialchars($activeTab) . '</div>';
        }
        ?>
    </div>
</div>

<!-- Server Logs Panel (footer, same as lab dashboard) -->
<div class="server-logs-panel shadow-lg px-4">
    <div class="logs-header d-flex justify-content-between align-items-center logs-header-clickable"
         id="instanceLogsToggleBtn"
         data-minimized="<?= $inst_logs_data_min ?>">
        <div class="logs-title d-flex align-items-center gap-2">
            <i class='bx bx-terminal fs-5'></i>
            <i class="bx bxs-circle" id="mq-status-dot"></i>
            <span class="small fw-bold ls-1 opacity-75">Server Logs</span>
            <div class="terminal-info-wrapper ms-1">
                <i class='bx bx-info-circle opacity-50'></i>
                <div class="terminal-tooltip">Live build/deploy logs from the worker</div>
            </div>
            <i class='bx <?= $inst_logs_chevron ?> server-logs-chevron ms-1'></i>
        </div>
        <div class="logs-action text-secondary opacity-75 pe-2">
            <i class='bx <?= $inst_logs_chevron ?> server-logs-chevron'></i>
        </div>
    </div>
    <div class="logs-body <?= $inst_logs_min_class ?>" id="terminal-viewport">
        <div id="live-logs-container" class="small"></div>
    </div>
</div>

<script src="<?= Session::cacheCDN('/assets/js/instances.js') ?>"></script>

