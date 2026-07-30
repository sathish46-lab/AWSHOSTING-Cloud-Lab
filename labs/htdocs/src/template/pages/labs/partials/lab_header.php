<div class="blur banner mb-3 rounded-0 border-bottom border-secondary border-opacity-10">
    <div class="card-body p-0" style="margin-left: 1rem; margin-right: 1rem;">
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
                        <span class="avatar-status <?= $isRunning ? 'bg-success' : 'bg-secondary' ?> border-dark ring-2 position-absolute bottom-0 end-0 mb-1 me-1 p-1"></span>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="d-flex flex-column gap-1">
                    <!-- Title -->
                    <h3 class="fw-bold mb-0 ls-tight lab-header-title"><?= $cfg['title'] ?></h3>
                    
                    <!-- Meta Info (ID, Instance & Share Group) -->
                    <div class="d-flex flex-wrap align-items-center gap-2 small">
                        <?php 
                            // Determine the best "Share" URL (Professional Dashboard Path)
                            $shareUrl = "https://" . $_SERVER['HTTP_HOST'] . "/labs/dashboard/" . $labType;
                        ?>
                        
                        <!-- Lab ID Display -->
                        <div class="d-flex align-items-center text-secondary">
                            <span class="me-1 opacity-75">Lab ID:</span>
                            <code class="text-info fw-bold me-2"><?= $labType ?></code>
                        </div>

                        <!-- Action Button Group -->
                        <div class="d-flex align-items-center gap-3 border-start border-white border-opacity-10 ps-2">
                            <!-- Copy Lab ID -->
                            <button class="btn btn-link btn-sm p-0 btn-copy-utility clipboard transition-all" 
                                    data-clipboard-text="<?= $labType ?>"
                                    data-tooltip="Copy Lab ID">
                                <i class='bx bx-copy fs-6' style="color: #fff;"></i>
                            </button>

                            <!-- Copy Instance Hash (Icon only) -->
                            <button class="btn btn-link btn-sm p-0 btn-copy-utility clipboard transition-all" 
                                    data-clipboard-text="<?= $fullHash ?>"
                                    data-tooltip="Copy Instance ID">
                                <i class='bx bx-fingerprint fs-6' style="color: #fff;"></i>
                            </button>
                            
                            <!-- Share Dashboard Link -->
                            <button class="btn btn-link btn-sm p-0 btn-copy-utility clipboard text-decoration-none d-flex align-items-center transition-all" 
                                    data-clipboard-text="<?= $shareUrl ?>"
                                    data-tooltip="Copy Shareable Dashboard URL">
                                <i class='bx bx-share-alt fs-6' style="color: #fff;"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Description -->
                    <p class="small lab-header-desc">
                        <?= $cfg['desc'] ?>
                    </p>

                    <!-- Badges -->
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="badge bg-primary rounded-pill px-3 py-1">beta</span>
                        <span class="badge bg-warning rounded-pill px-3 py-1">public</span>
                        <span class="badge <?= $isRunning ? 'bg-success' : 'bg-danger' ?> rounded-pill px-3 py-1"><?= strtoupper($status) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="btn-group shadow-sm rounded-pill overflow-hidden me-5" role="group">
                <?php if($isRunning): ?>
                    <button class="btn btn-lab-launch"
                            onclick="launchService(this, '<?= $labType ?>')"
                            data-tooltip="Launch Cloud IDE / Code Server"
                            data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                        <i class='bx bx-code-alt fs-6'></i>
                        <span class="small"><?= $cfg['action'] ?></span>
                    </button>
                <?php endif; ?>
                
                <button class="btn btn-lab-deploy"
                        onclick="handleDeploy(this, '<?= $labType ?>')"
                        data-tooltip="<?= $isRunning ? 'Redeploy for a fresh instance' : 'Deploy this lab' ?>"
                        data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                    <i class='bx <?= $isRunning ? 'bx-refresh' : 'bx-cloud-upload' ?> fs-6 text-dark'></i>
                    <span class="small text-dark"><?= $isRunning ? 'Redeploy' : 'Deploy' ?></span>
                </button>

                <?php if($isRunning): ?>
                    <button id="btn-stop-action" class="btn btn-lab-stop"
                            onclick="handleStop()"
                            data-tooltip="Stop Instance Immediately"
                            data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                        <i class='bx bx-stop-circle fs-6' ></i>
                        <span class="small">Stop</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- <hr style="margin-top: 0"> -->
        
        <!-- Navigation Tabs -->
        <?php include __DIR__ . '/lab_nav.php'; ?>
        
    </div>
</div>
</div>
