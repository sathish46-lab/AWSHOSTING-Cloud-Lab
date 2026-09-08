<?php
    // Lab data now fetched via API (lab_data.php) — no HTML embedding needed
?>
<div class="blur banner mb-3 rounded-0 border-bottom border-secondary border-opacity-10">
    <div class="card-body p-0">
        <div class="container-fluid pt-3 pb-1">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <!-- Avatar + Info Section -->
            <div class="d-flex align-items-center gap-4">
                <!-- Avatar Section -->
                <div class="position-relative flex-shrink-0">
                    <div class="avatar lab-header-avatar">
                        <div class="avatar-img d-flex align-items-center justify-content-center bg-dark bg-opacity-25 rounded-circle p-2" >
                            <?php if (strpos($cfg['icon'], 'http') === 0): ?>
                                <img src="<?= $cfg['icon'] ?>" >
                            <?php else: ?>
                                <i class="bx <?= $cfg['icon'] ?>" ></i>
                            <?php endif; ?>
                        </div>
                        <span class="avatar-status <?= $status === 'paused' ? 'bg-warning' : ($isRunning ? 'bg-success' : 'bg-secondary') ?> border-dark ring-2 position-absolute bottom-0 end-0 mb-1 me-1 p-1"></span>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="d-flex flex-column gap-1">
                    <!-- Title -->
                    <h3 class="fw-bold mb-0 ls-tight lab-header-title"><?= $cfg['title'] ?></h3>
                    
                    <!-- Description -->
                    <p class="small lab-header-desc mb-1">
                        <?= $cfg['desc'] ?>
                    </p>

                    <!-- Badges + Meta Info -->
                    <div class="d-flex flex-wrap align-items-center column-gap-1 row-gap-1 small">
                        <?php 
                            $badgeClass = 'bg-danger';
                            if ($status === 'running') $badgeClass = 'bg-success';
                            elseif ($status === 'paused') $badgeClass = 'bg-warning';
                            elseif ($status === 'deploying') $badgeClass = 'bg-info';
                            $shareUrl = "https://" . $_SERVER['HTTP_HOST'] . "/labs/dashboard/" . $labType;
                        ?>
                        <span class="badge bg-primary rounded-pill">beta</span>
                        <span class="badge bg-warning rounded-pill">public</span>
                        <span class="badge <?= $badgeClass ?> rounded-pill"><?= $status === 'not_deployed' ? 'NOT RUNNING' : strtoupper($status) ?></span>
                        <span class="text-body-secondary mx-1">&middot;</span>
                        <span class="text-body-secondary">
                            Instance <code><?= substr($fullHash, 0, 6) ?>…</code>
                        </span>
                        <button class="btn btn-link btn-sm clipboard p-0" data-clipboard-text="<?= $fullHash ?>" data-tooltip="Copy Instance ID" style="text-decoration:none;">
                            <i class='bx bx-copy text-body-secondary'></i>
                        </button>
                        <span class="text-body-secondary mx-1">&middot;</span>
                        <span class="text-body-secondary">
                            Lab ID: <code><?= $labType ?></code>
                        </span>
                        <button class="btn btn-link btn-sm clipboard p-0" data-clipboard-text="<?= $labType ?>" data-tooltip="Copy Lab ID" style="text-decoration:none;">
                            <i class='bx bx-copy text-body-secondary'></i>
                        </button>
                        <button class="btn btn-link btn-sm clipboard p-0" data-clipboard-text="<?= $shareUrl ?>" data-tooltip="Copy Shareable URL" style="text-decoration:none;">
                            <i class='bx bx-link-alt text-body-secondary'></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons + Progress -->
            <div class="d-flex flex-column align-items-end gap-2 me-3">
                <!-- Button Group -->
                <div class="btn-group shadow-sm rounded-pill overflow-hidden lab-btn-group" role="group">
                    <?php if($isRunning): ?>
                        <!-- RUNNING: Code + Redeploy + Pause(icon) + Stop(icon) -->
                        <button class="btn btn-lab-launch"
                                onclick="<?= $labType === 'gui_essentials' ? 'launchGui(this)' : "launchService(this, '$labType')" ?>"
                                data-tooltip="<?= $labType === 'gui_essentials' ? 'Launch VNC Desktop' : 'Launch Cloud IDE / Code Server' ?>"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <svg class="icon" viewBox="0 0 256 256" width="18" height="18"><use href="/assets/icons/duotone.svg#<?= $labType === 'gui_essentials' ? 'tom-desktop' : 'tom-terminal-window' ?>"></use></svg>
                            <span class="small"><?= $cfg['action'] ?></span>
                        </button>
                        <button id="btn-deploy-action" class="btn btn-lab-deploy"
                                onclick="handleDeploy(this, '<?= $labType ?>')"
                                data-tooltip="Redeploy for a fresh instance"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <svg class="icon" viewBox="0 0 256 256" width="18" height="18"><use href="/assets/icons/duotone.svg#tom-fiber-new"></use></svg>
                            <span class="small text-dark">Redeploy</span>
                        </button>
                        <button id="btn-pause-action" class="btn btn-lab-pause"
                                onclick="handlePause()"
                                data-tooltip="Pause lab"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <i class='bx bx-pause fs-5'></i>
                        </button>
                        <button id="btn-stop-action" class="btn btn-lab-stop"
                                onclick="handleStop()"
                                data-tooltip="Stop lab"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <i class='bx bx-stop-circle fs-5'></i>
                        </button>

                    <?php elseif($status === 'paused'): ?>
                        <!-- PAUSED: Resume + Redeploy -->
                        <button id="btn-resume-action" class="btn btn-lab-resume"
                                onclick="handleResume()"
                                data-tooltip="Resume paused lab"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <i class='bx bx-play fs-6'></i>
                            <span class="small">Resume</span>
                        </button>
                        <button id="btn-deploy-action" class="btn btn-lab-deploy"
                                onclick="handleDeploy(this, '<?= $labType ?>')"
                                data-tooltip="Redeploy for a fresh instance"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <svg class="icon" viewBox="0 0 256 256" width="18" height="18"><use href="/assets/icons/duotone.svg#tom-fiber-new"></use></svg>
                            <span class="small text-dark">Redeploy</span>
                        </button>

                    <?php else: ?>
                        <!-- STOPPED/ERROR: Deploy -->
                        <button id="btn-deploy-action" class="btn btn-lab-deploy"
                                onclick="handleDeploy(this, '<?= $labType ?>')"
                                data-tooltip="Deploy this lab"
                                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                            <i class='bx bx-cloud-upload fs-6 text-dark'></i>
                            <span class="small text-dark">Deploy</span>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Deployment Progress Bar (hidden by default) -->
                <div id="deploy-progress-container" class="d-none" style="min-width: 280px;">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span id="deploy-progress-icon">🚀</span>
                            <span id="deploy-progress-label" class="small fw-bold">Deploying...</span>
                        </div>
                        <span id="deploy-progress-percent" class="small fw-bold">0%</span>
                    </div>
                    <div class="progress" style="height: 8px; border-radius: 4px; background: rgba(255,255,255,0.1);">
                        <div id="deploy-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: 0%; background: linear-gradient(90deg, #00d4ff, #00ff88); border-radius: 4px;"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- <hr style="margin-top: 0"> -->
        
        <!-- Navigation Tabs -->
        <?php include __DIR__ . '/lab_nav.php'; ?>
        
    </div>
</div>
</div>

<script>
    window.SESSION_HASH = "<?= $fullHash ?>";
    window.LAB_USER = "<?= htmlspecialchars($currentUsername ?? Session::getUser()->getUsername()) ?>";
    window.LAB_TYPE = "<?= htmlspecialchars($labType ?? 'essentials') ?>";
</script>
