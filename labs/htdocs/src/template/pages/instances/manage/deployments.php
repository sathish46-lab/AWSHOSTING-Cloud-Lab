<?php
$hash = $instance['instance_hash'] ?? '';

$instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instDoc = $instDb->instances->findOne(['instance_hash' => $hash]);

$deploy = $instDoc['deploy'] ?? [];
$depStatus = $deploy['status'] ?? 'none';
$credentials = $deploy['credentials'] ?? [];
$codeUrl = $credentials['code_server_url'] ?? '';
$sshCmd = $credentials['ssh'] ?? '';
$dockerIp = $credentials['docker_ip'] ?? '';
$tunnelIp = $credentials['tunnel_ip'] ?? '';
$codeDomain = $deploy['code_domain'] ?? '';

// Auto-clean expired logs (older than 5 min)
$now = time();
$deployLog = $deploy['deploy_log'] ?? null;
$buildLog = $deploy['build_log'] ?? null;

if ($deployLog && isset($deployLog['expire_at']) && $now > $deployLog['expire_at']) {
    $instDb->instances->updateOne(['instance_hash' => $hash], ['$unset' => ['deploy.deploy_log' => '']]);
    $deployLog = null;
}
if ($buildLog && isset($buildLog['expire_at']) && $now > $buildLog['expire_at']) {
    $instDb->instances->updateOne(['instance_hash' => $hash], ['$unset' => ['deploy.build_log' => '']]);
    $buildLog = null;
}

$hasDeployLog = !empty($deployLog['logs']);
$hasBuildLog = !empty($buildLog['logs']);

$isRunning = ($depStatus === 'running');
$isStopped = in_array($depStatus, ['stopped', 'none', 'error']);
?>
<div class="card blur border-0 rounded-4 p-4 shadow-lg" id="deploymentsTab" data-hash="<?= htmlspecialchars($hash) ?>">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold theme-text m-0 d-flex align-items-center gap-2">
            <i class='bx bx-rocket fs-4'></i> Deploy & Run
        </h5>
        <div class="d-flex gap-2">
            <?php if ($isRunning): ?>
            <div class="btn-instance-group">
                <a href="<?= htmlspecialchars($codeUrl ?: '#') ?>" target="_blank"
                   class="btn-instance-seg btn-seg-code <?= empty($codeUrl) ? 'disabled' : '' ?>">
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

    <?php if ($depStatus === 'none'): ?>
    <div class="alert alert-dark border border-secondary border-opacity-25 bg-black bg-opacity-25 text-secondary mb-3 rounded-4 py-2 small">
        <i class='bx bx-info-circle me-2'></i> No deployment found. Click <strong>Deploy</strong> to start.
    </div>
    <?php else: ?>

    <div class="d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-25 pb-2 mb-3">
        <span class="text-secondary fw-bold small text-uppercase">DEPLOYMENT STATUS</span>
        <?php
            $depStatusColor = 'primary';
            if ($depStatus === 'running') $depStatusColor = 'success';
            elseif (in_array($depStatus, ['deploying', 'starting'])) $depStatusColor = 'warning';
            elseif (in_array($depStatus, ['stopping', 'stopped', 'error'])) $depStatusColor = 'danger';
        ?>
        <span class="badge bg-<?= $depStatusColor ?> rounded-pill px-3 py-1" id="deployStatusBadge" data-status="<?= htmlspecialchars($depStatus) ?>">
            <?= htmlspecialchars($depStatus) ?>
        </span>
    </div>

    <?php if ($isRunning): ?>
    <div class="row g-3 mb-3">
        <?php if ($codeUrl): ?>
        <div class="col-md-6">
            <div class="card liquid-rim border-0 rounded-4 p-3 h-100">
                <div class="text-secondary fw-bold small mb-2"><i class='bx bx-code-alt me-1'></i> Code Server</div>
                <a href="<?= htmlspecialchars($codeUrl) ?>" target="_blank" class="text-info fw-bold text-decoration-none"><?= htmlspecialchars($codeUrl) ?></a>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($sshCmd): ?>
        <div class="col-md-6">
            <div class="card liquid-rim border-0 rounded-4 p-3 h-100">
                <div class="text-secondary fw-bold small mb-2"><i class='bx bx-terminal me-1'></i> SSH Access</div>
                <code class="text-info small"><?= htmlspecialchars($sshCmd) ?></code>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($dockerIp): ?>
        <div class="col-md-4">
            <div class="card liquid-rim border-0 rounded-4 p-3 h-100">
                <div class="text-secondary fw-bold small mb-2"><i class='bx bx-network-chart me-1'></i> Docker IP</div>
                <span class="text-info fw-bold"><?= htmlspecialchars($dockerIp) ?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($tunnelIp): ?>
        <div class="col-md-4">
            <div class="card liquid-rim border-0 rounded-4 p-3 h-100">
                <div class="text-secondary fw-bold small mb-2"><i class='bx bx-shield-quarter me-1'></i> Tunnel IP</div>
                <span class="text-info fw-bold"><?= htmlspecialchars($tunnelIp) ?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($codeDomain): ?>
        <div class="col-md-4">
            <div class="card liquid-rim border-0 rounded-4 p-3 h-100">
                <div class="text-secondary fw-bold small mb-2"><i class='bx bx-globe me-1'></i> Domain</div>
                <span class="text-info fw-bold"><?= htmlspecialchars($codeDomain) ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isStopped && $depStatus !== 'none'): ?>
    <div class="alert alert-warning border-0 rounded-4 py-2 mb-3 small" style="background-color: rgba(255,165,0,0.1); color: #ffa502;">
        <i class='bx bx-pause-circle me-2'></i> Instance is stopped. Click <strong>Deploy</strong> to start fresh.
    </div>
    <?php endif; ?>

    <?php if ($depStatus === 'error'): ?>
    <?php $lastError = $deploy['last_error'] ?? ''; ?>
    <div class="alert alert-danger border-0 rounded-4 py-2 mb-3 small" style="background-color: rgba(255,107,107,0.1); color: #ff6b6b;">
        <i class='bx bx-error-circle me-2'></i> <?= $lastError ? htmlspecialchars($lastError) : 'Deployment failed. Check logs for details.' ?>
    </div>
    <?php endif; ?>

    <?php if ($hasDeployLog): ?>
    <div class="mb-3">
        <button class="btn btn-sm btn-outline-<?= $depStatus === 'error' ? 'danger' : 'success' ?> rounded-pill px-3 mb-2" type="button" data-coreui-toggle="collapse" data-coreui-target="#deployLogCollapse">
            <i class='bx bx-terminal me-1'></i> View Deploy Logs
            <?php if ($depStatus === 'error'): ?>
            <span class="badge bg-danger rounded-circle px-1 ms-1" style="font-size: 0.6rem;">!</span>
            <?php endif; ?>
        </button>
        <div class="collapse" id="deployLogCollapse">
            <div class="card blur border border-<?= $depStatus === 'error' ? 'danger' : 'success' ?> border-opacity-25 rounded-4 deployment-log-collapse">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-<?= $depStatus === 'error' ? 'danger' : 'success' ?> fw-bold">Deploy Logs</small>
                    <small class="text-secondary"><?= date('h:i:s A', $deployLog['created_at'] ?? time()) ?></small>
                </div>
                <pre class="mb-0 small"><?php
                    foreach (array_slice((array)($deployLog['logs'] ?? []), -100) as $logLine) {
                        echo htmlspecialchars($logLine) . "\n";
                    }
                ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasBuildLog): ?>
    <div class="mb-3">
        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 mb-2" type="button" data-coreui-toggle="collapse" data-coreui-target="#buildLogCollapse">
            <i class='bx bx-hammer me-1'></i> View Build Logs
            <?php if (($buildLog['status'] ?? '') === 'error'): ?>
            <span class="badge bg-danger rounded-circle px-1 ms-1" style="font-size: 0.6rem;">!</span>
            <?php endif; ?>
        </button>
        <div class="collapse" id="buildLogCollapse">
            <div class="card blur border border-secondary border-opacity-25 rounded-4 deployment-log-collapse">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-secondary fw-bold">Build Logs</small>
                    <small class="text-secondary"><?= date('H:i:s', $buildLog['created_at'] ?? time()) ?></small>
                </div>
                <pre class="mb-0 small"><?php
                    foreach (array_slice((array)($buildLog['logs'] ?? []), -100) as $logLine) {
                        echo htmlspecialchars($logLine) . "\n";
                    }
                ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
