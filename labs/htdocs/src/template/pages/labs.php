<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-3">
        <div class="row align-items-center py-3">
            <div class="col">
                <h1 class="fw-bold theme-text m-0 labs-page-title">Labs</h1>
                <p class="text-secondary opacity-75 mt-2 mb-0 labs-page-desc">
                    Explore the Labs, a technical playground for you. Each lab is a portal to virtual experiences, fostering innovation and digital mastery. Immerse yourself in this journey of tech exploration and discovery.
                </p>
            </div>
            <div class="col-auto">
                <div class="d-flex flex-column align-items-center justify-content-center text-center running-stat-wrapper">
                    <div class="d-flex align-items-center justify-content-center mb-1">
                        <span class="fw-bold theme-text running-stat-val"><?= Session::get('running_count', 0) ?></span>
                        <span class="text-secondary opacity-50 ms-2 running-stat-total">/ <?= Session::get('total_labs', 0) ?></span>
                    </div>
                    <?php 
                        $total = (int)Session::get('total_labs', 1);
                        if ($total <= 0) $total = 1;
                        $percent = ((int)Session::get('running_count', 0) / $total) * 100;
                    ?>
                    <div class="progress bg-secondary bg-opacity-10 rounded-pill mb-2 w-100 running-stat-progress">
                        <div class="progress-bar bg-success rounded-pill" role="progressbar" style="width: <?= $percent ?>%" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="text-secondary opacity-50 text-uppercase fw-bold ls-1 running-stat-label">Running Labs</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-3">
    <div class="row g-4 mb-4">
    <?php foreach(Session::get('labs_list', []) as $lab): ?>
    <div class="col-12 col-md-4 card-entrance">
        <div class="card h-100 border-0 shadow-lg rounded-4 blur position-relative">

            <div class="position-absolute end-0 top-50 translate-middle-y pe-3 lab-card-bg-icon">
                <?php if ($lab['id'] === 'minio'): ?>
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" height="140" width="140">
                        <path d="M13.2072 0.006c-0.6216 -0.0478 -1.2 0.1943 -1.6211 0.582a2.15 2.15 0 0 0 -0.0938 3.0352l3.4082 3.5507a3.042 3.042 0 0 1 -0.664 4.6875l-0.463 0.2383V7.2853a15.4198 15.4198 0 0 0 -8.0174 10.4862v0.0176l6.5487 -3.3281v7.621L13.7794 24V13.6817l0.8965 -0.4629a4.4432 4.4432 0 0 0 1.2207 -7.0292l-3.371 -3.5254a0.7489 0.7489 0 0 1 0.037 -1.0547 0.7522 0.7522 0 0 1 1.0567 0.0371l0.4668 0.4863 -0.006 0.0059 4.0704 4.2441a0.0566 0.0566 0 0 0 0.082 0 0.06 0.06 0 0 0 0 -0.0703l-3.1406 -5.1425 -0.1484 0.1425 0.1484 -0.1445C14.4945 0.3926 13.8287 0.0538 13.2072 0.006Zm-0.9024 9.8652v2.9941l-4.1523 2.1484a13.9787 13.9787 0 0 1 2.7676 -3.9277 14.1784 14.1784 0 0 1 1.3847 -1.2148z" fill="currentColor"></path>
                    </svg>
                <?php else:
                    $iconMap = [
                        'tux'    => 'bxl-tux',
                        'docker' => 'bxl-docker',
                        'git-repo-forked' => 'bx-git-repo-forked'
                    ];
                    $bxClass = $iconMap[$lab['icon']] ?? 'bxl-ubuntu';
                ?>
                    <i class="bx <?= $bxClass ?> lab-card-icon-lg"></i>
                <?php endif; ?>
            </div>

            <div class="card-body p-4 text-center d-flex flex-column align-items-center position-relative lab-card-content">
                <div class="mb-2">
                    <h5 class="card-title fw-bold mb-0 lab-card-title">
                        <?= $lab['name'] ?>
                        <div class="terminal-info-wrapper ms-1">
                            <i class="bx bx-info-circle small text-muted"></i>
                            <div class="terminal-tooltip">
                                <div class="fw-bold mb-1 text-uppercase text-secondary terminal-tooltip-title">Instance ID</div>
                                <div class="font-monospace text-warning text-break"><?= $lab['hash'] ?></div>
                            </div>
                        </div>
                    </h5>
                </div>

                <div class="mb-3">
                    <span class="text-<?= $lab['status'] == 'running' ? 'info' : 'secondary opacity-50' ?> font-monospace small fw-bold">
                        <?= $lab['ip'] ?>
                    </span>
                </div>

                <div class="d-flex justify-content-center flex-wrap gap-1 mb-4">
                    <?php foreach($lab['badges'] as $b): ?>
                        <span class="badge bg-primary rounded-pill px-2 py-1"><?= $b ?></span>
                    <?php endforeach; ?>

                    <span class="badge bg-warning rounded-pill px-2 py-1">
                        <?= strtoupper($lab['is_public']) ?>
                    </span>

                    <?php 
                        $status = strtolower($lab['status']);
                        $statusClass = ($status === 'running') ? 'bg-success' : 'bg-danger';
                    ?>
                    <span class="badge <?= $statusClass ?> rounded-pill px-3 py-1">
                        <?= strtoupper($status) ?>
                    </span>
                </div>

                <div class="mt-auto w-100 d-flex gap-2">
                    <?php if($lab['status'] === 'running'): ?>
                        <button type="button" class="btn btn-primary flex-grow-1 d-flex align-items-center justify-content-center fw-bold small rounded-3 py-2"
                                onclick="openCodeModal('<?= $lab['hash'] ?>', '<?= $lab['name'] ?> Lab', '<?= $lab['status'] ?>')">
                            <i class='bx bx-code-alt me-1'></i> Code
                        </button>
                    <?php endif; ?>
                    
                    <a href="/labs/dashboard/<?= $lab['hash'] ?>" 
                       class="btn btn-success text-white flex-grow-1 d-flex align-items-center justify-content-center fw-bold small rounded-3 py-2">
                        <i class='bx bx-grid-alt me-1'></i> Dashboard
                    </a>
                    
                    <!-- INFO MODAL TRIGGER -->
                    <button type="button" class="btn btn-info text-white d-flex align-items-center justify-content-center rounded-3 px-3"
                            onclick="openConnectionModal('<?= $lab['hash'] ?>', '<?= $lab['name'] ?> Lab', '<?= $lab['status'] ?>')">
                        <i class='bx bx-info-circle'></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/labs/partials/lab_action_modals.php'; ?>