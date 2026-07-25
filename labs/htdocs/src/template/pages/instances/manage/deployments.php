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

$statusColorMap = [
    'running'   => 'rgba(46,204,113,0.15)',
    'deploying' => 'rgba(255,165,0,0.15)',
    'starting'  => 'rgba(255,165,0,0.15)',
    'stopping'  => 'rgba(255,107,107,0.15)',
    'stopped'   => 'rgba(255,107,107,0.15)',
    'error'     => 'rgba(255,107,107,0.15)',
];
$textColorMap = [
    'running'   => '#2ecc71',
    'deploying' => '#ffa502',
    'starting'  => '#ffa502',
    'stopping'  => '#ff6b6b',
    'stopped'   => '#ff6b6b',
    'error'     => '#ff6b6b',
];
$bg = $statusColorMap[$depStatus] ?? 'rgba(255,255,255,0.1)';
$tc = $textColorMap[$depStatus] ?? 'rgba(255,255,255,0.5)';
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
            <button class="btn rounded-pill px-3 fw-bold btn-sm stop-deploy-btn"
                style="background-color: rgba(255,107,107,0.15); border: 1px solid rgba(255,107,107,0.3); color: #ff6b6b;"
                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                <i class='bx bx-stop-circle'></i> Stop
            </button>
            <?php elseif ($isStopped && !empty($tunnelIp)): ?>
            <button class="btn rounded-pill px-3 fw-bold btn-sm start-deploy-btn"
                style="background-color: rgba(46,204,113,0.15); border: 1px solid rgba(46,204,113,0.3); color: #2ecc71;"
                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                <i class='bx bx-play-circle'></i> Start
            </button>
            <?php else: ?>
            <button class="btn rounded-pill px-4 fw-bold deploy-now-btn"
                style="background-color: #ff4b2b; border-color: #ff4b2b; color: white;"
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
        <span class="badge rounded-pill fw-bold" id="deployStatusBadge"
            style="background-color: <?= $bg ?>; color: <?= $tc ?>;">
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

    <?php if ($isStopped): ?>
    <div class="alert alert-warning border-0 rounded-4 py-2 mb-3 small" style="background-color: rgba(255,165,0,0.1); color: #ffa502;">
        <i class='bx bx-pause-circle me-2'></i> Instance is stopped. Click <strong>Start</strong> to resume.
    </div>
    <?php endif; ?>

    <?php if ($depStatus === 'error'): ?>
    <div class="alert alert-danger border-0 rounded-4 py-2 mb-3 small" style="background-color: rgba(255,107,107,0.1); color: #ff6b6b;">
        <i class='bx bx-error-circle me-2'></i> Deployment failed. Try redeploying.
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
(function() {
    function setBtnLoading(btn, loading) {
        if (typeof Dashboard !== 'undefined' && Dashboard.toggleLoading) {
            Dashboard.toggleLoading(btn, loading);
        } else {
            if (loading) {
                btn.disabled = true;
                if (!btn.dataset.originalContent) btn.dataset.originalContent = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Processing';
            } else {
                if (btn.dataset.originalContent) { btn.innerHTML = btn.dataset.originalContent; delete btn.dataset.originalContent; }
                btn.disabled = false;
            }
        }
    }

    function reloadTab() {
        if (window.__loadInstanceTab) window.__loadInstanceTab('deployments');
    }

    document.addEventListener('click', async (e) => {
        const tab = document.getElementById('deploymentsTab');
        const hash = tab?.dataset.hash;
        if (!hash) return;

        if (e.target.closest('.deploy-now-btn')) {
            const btn = e.target.closest('.deploy-now-btn');
            setBtnLoading(btn, true);
            if (window.appendInstanceLog) window.appendInstanceLog('[*] Queuing deployment...');
            try {
                const res = await fetch('/api/instances/deploy_instance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'hash=' + encodeURIComponent(hash)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    if (window.appendInstanceLog) window.appendInstanceLog('[✓] Job queued. Streaming logs...');
                    const badge = document.getElementById('deployStatusBadge');
                    if (badge) { badge.textContent = 'deploying'; badge.style.backgroundColor = 'rgba(255,165,0,0.15)'; badge.style.color = '#ffa502'; }
                    btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Deploying';
                } else {
                    if (window.appendInstanceLog) window.appendInstanceLog('[!] ' + (data.error || 'Deploy failed'));
                    setBtnLoading(btn, false);
                }
            } catch (err) {
                if (window.appendInstanceLog) window.appendInstanceLog('[!] Network error');
                setBtnLoading(btn, false);
            }
        }

        if (e.target.closest('.stop-deploy-btn')) {
            const btn = e.target.closest('.stop-deploy-btn');
            if (!confirm('Stop this instance?')) return;
            setBtnLoading(btn, true);
            if (window.appendInstanceLog) window.appendInstanceLog('[*] Queuing stop...');
            try {
                const res = await fetch('/api/instances/stop_instance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'hash=' + encodeURIComponent(hash)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    if (window.appendInstanceLog) window.appendInstanceLog('[✓] Stop queued.');
                    const badge = document.getElementById('deployStatusBadge');
                    if (badge) { badge.textContent = 'stopping'; badge.style.backgroundColor = 'rgba(255,107,107,0.15)'; badge.style.color = '#ff6b6b'; }
                    btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Stopping';
                } else {
                    if (window.appendInstanceLog) window.appendInstanceLog('[!] ' + (data.error || 'Stop failed'));
                    setBtnLoading(btn, false);
                }
            } catch (err) {
                if (window.appendInstanceLog) window.appendInstanceLog('[!] Network error');
                setBtnLoading(btn, false);
            }
        }

        if (e.target.closest('.start-deploy-btn')) {
            const btn = e.target.closest('.start-deploy-btn');
            setBtnLoading(btn, true);
            if (window.appendInstanceLog) window.appendInstanceLog('[*] Queuing start...');
            try {
                const res = await fetch('/api/instances/start_instance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'hash=' + encodeURIComponent(hash)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    if (window.appendInstanceLog) window.appendInstanceLog('[✓] Start queued.');
                    const badge = document.getElementById('deployStatusBadge');
                    if (badge) { badge.textContent = 'starting'; badge.style.backgroundColor = 'rgba(255,165,0,0.15)'; badge.style.color = '#ffa502'; }
                    btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Starting';
                } else {
                    if (window.appendInstanceLog) window.appendInstanceLog('[!] ' + (data.error || 'Start failed'));
                    setBtnLoading(btn, false);
                }
            } catch (err) {
                if (window.appendInstanceLog) window.appendInstanceLog('[!] Network error');
                setBtnLoading(btn, false);
            }
        }
    });
})();
</script>
