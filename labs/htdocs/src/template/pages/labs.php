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
    <?php
        $isRunning = ($lab['status'] === 'running');
        $isPaused = ($lab['status'] === 'paused');
        $status = strtolower($lab['status']);
        $iconMap = [
            'tux'    => 'bxl-tux',
            'docker' => 'bxl-docker',
            'git-repo-forked' => 'bx-git-repo-forked'
        ];
        $bxClass = $iconMap[$lab['icon']] ?? 'bxl-ubuntu';
        if ($isRunning) $accentColor = 'var(--accent-green)';
        elseif ($isPaused) $accentColor = 'var(--accent-warning, #eab308)';
        else $accentColor = 'var(--accent-muted)';
    ?>
    <div class="col-12 col-md-4 card-entrance">
        <div class="card glass-lab-card h-100 border-0 shadow-lg rounded-4 blur position-relative" style="--card-accent: <?= $accentColor ?>;">
            
            <div class="glass-lab-card-glow"></div>

            <div class="glass-lab-card-body d-flex flex-column h-100 p-4 position-relative" style="z-index: 2;">
                
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="glass-lab-avatar <?= $isRunning ? 'is-running' : ($isPaused ? 'is-paused' : '') ?>">
                        <?php if ($lab['id'] === 'minio'): ?>
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" height="28" width="28">
                                <path d="M13.2072 0.006c-0.6216 -0.0478 -1.2 0.1943 -1.6211 0.582a2.15 2.15 0 0 0 -0.0938 3.0352l3.4082 3.5507a3.042 3.042 0 0 1 -0.664 4.6875l-0.463 0.2383V7.2853a15.4198 15.4198 0 0 0 -8.0174 10.4862v0.0176l6.5487 -3.3281v7.621L13.7794 24V13.6817l0.8965 -0.4629a4.4432 4.4432 0 0 0 1.2207 -7.0292l-3.371 -3.5254a0.7489 0.7489 0 0 1 0.037 -1.0547 0.7522 0.7522 0 0 1 1.0567 0.0371l0.4668 0.4863 -0.006 0.0059 4.0704 4.2441a0.0566 0.0566 0 0 0 0.082 0 0.06 0.06 0 0 0 0 -0.0703l-3.1406 -5.1425 -0.1484 0.1425 0.1484 -0.1445C14.4945 0.3926 13.8287 0.0538 13.2072 0.006Z" fill="currentColor"></path>
                            </svg>
                        <?php else: ?>
                            <i class="bx <?= $bxClass ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h5 class="glass-lab-title fw-bold mb-0 text-truncate">
                            <?= $lab['name'] ?>
                        </h5>
                        <div class="glass-lab-subtitle d-flex align-items-center gap-2 mt-1">
                            <?php if ($isRunning): ?>
                                <span class="glass-status-dot running"></span>
                                <span class="font-monospace small text-info"><?= $lab['ip'] ?></span>
                            <?php elseif ($isPaused): ?>
                                <span class="glass-status-dot paused"></span>
                                <span class="small text-warning">Paused</span>
                            <?php else: ?>
                                <span class="glass-status-dot"></span>
                                <span class="small text-secondary opacity-60">Instance Down</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="terminal-info-wrapper">
                        <i class="bx bx-info-circle glass-info-btn"></i>
                        <div class="terminal-tooltip">
                            <div class="fw-bold mb-1 text-uppercase text-secondary terminal-tooltip-title">Instance ID</div>
                            <div class="font-monospace text-warning text-break"><?= $lab['hash'] ?></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-1 mb-3">
                    <?php foreach($lab['badges'] as $b): ?>
                        <span class="glass-badge"><?= $b ?></span>
                    <?php endforeach; ?>
                    <span class="glass-badge badge-public"><?= strtoupper($lab['is_public']) ?></span>
                    <?php if ($isPaused): ?>
                        <span class="glass-badge badge-paused">&#9679; PAUSED</span>
                    <?php else: ?>
                        <span class="glass-badge <?= $isRunning ? 'badge-running' : 'badge-offline' ?>">
                            <?= $isRunning ? '&#9679; RUNNING' : '&#9679; OFFLINE' ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="mt-auto d-flex gap-2">
                    <?php if ($isPaused): ?>
                        <button type="button" class="glass-btn glass-btn-success flex-grow-1"
                                onclick="window.location.href='/labs/dashboard/<?= $lab['hash'] ?>'">
                            <i class='bx bx-play'></i> Resume
                        </button>
                    <?php elseif ($isRunning): ?>
                        <button type="button" class="glass-btn glass-btn-primary flex-grow-1"
                                onclick="openCodeModal('<?= $lab['hash'] ?>', '<?= $lab['name'] ?> Lab', '<?= $lab['status'] ?>')">
                            <i class='bx bx-code-alt'></i> Code
                        </button>
                    <?php endif; ?>
                    
                    <a href="/labs/dashboard/<?= $lab['hash'] ?>" class="glass-btn glass-btn-success flex-grow-1">
                        <i class='bx bx-grid-alt'></i> Dashboard
                    </a>
                    
                    <?php if ($isRunning): ?>
                        <button type="button" class="glass-btn glass-btn-info"
                                onclick="openConnectionModal('<?= $lab['hash'] ?>', '<?= $lab['name'] ?>', '<?= $lab['status'] ?>')">
                            <i class='bx bx-link-external'></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/labs/partials/lab_modals.php'; ?>