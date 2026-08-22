<div class="flex-grow-1 px-3 blur rounded-0 border-0 shadow-none d-flex flex-column">
    <div class="quiz-container flex-grow-1 d-flex flex-column">
        <!-- 1. Hero Section -->
        <div class="d-flex flex-column align-items-center text-center py-2 mb-3 w-100">
            <h1 class="display-4 fw-bold theme-text mb-2 d-flex align-items-center justify-content-center">
                <i class="bx bxs-zap text-warning me-2"></i>Spot Quiz
            </h1>
            <p class="text-body-secondary fs-5 mb-4">Quick 5-question challenges • Earn rewards • Boost your skills</p>
            
            <!-- Mode Pills -->
            <div class="d-flex flex-wrap justify-content-center gap-2 mb-3 max-width-800">
                <span class="badge border border-primary text-primary bg-transparent px-3 py-2 rounded-pill">
                    <i class="bx bx-target-lock me-1"></i> Tech Master
                </span>
                <span class="badge border border-warning text-warning bg-transparent px-3 py-2 rounded-pill">
                    <i class="bx bx-timer me-1"></i> Time Trial
                </span>
                <span class="badge border border-danger text-danger bg-transparent px-3 py-2 rounded-pill">
                    <i class="bx bxs-flame me-1"></i> Rapid Fire
                </span>
                <span class="badge border border-info text-info bg-transparent px-3 py-2 rounded-pill">
                    <i class="bx bx-bolt-circle me-1"></i> Blitz
                </span>
                <span class="badge border border-success text-success bg-transparent px-3 py-2 rounded-pill">
                    <i class="bx bx-shield-quarter me-1"></i> Endurance
                </span>
                <span class="badge border border-secondary text-body-tertiary bg-transparent px-3 py-2 rounded-pill opacity-75">
                    <i class="bx bx-graduation-cap me-1"></i> Self Evaluation
                </span>
            </div>
            <small class="text-body-tertiary opacity-50">AI can make mistakes. Found an issue? <a href="#" class="text-info text-decoration-none">Report it</a></small>
        </div>

        <!-- 2. Tracked Achievements Section -->
        <div class="container-fluid px-lg-5">
            <div class="d-flex justify-content-between align-items-end mb-3">
                <h4 class="fw-bold theme-text m-0">Tracked Achievements</h4>
                <a href="/achievements" class="btn btn-sm btn-outline-info rounded-pill px-3">View All</a>
            </div>

            <div class="row g-3 mb-2">
                <!-- Achievement Card 1 -->
                <div class="col-md-3">
                    <div class="card p-3 liquid-rim h-100 hvr-grow shadow-lg transition-all">
                        <div class="card-body p-0">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="achievement-icon bg-primary bg-opacity-10 rounded-3 p-2">
                                    <i class="bx bxs-trophy text-primary fs-3"></i>
                                </div>
                                <div>
                                    <span class="badge bg-secondary-subtle text-body-secondary text-uppercase x-small mb-1">Common</span>
                                    <h6 class="fw-bold m-0 card-title-text">Normal Sampler</h6>
                                </div>
                            </div>
                            <p class="text-body-secondary x-small mb-3">Complete 1 normal quiz in 10 different topics. Normal difficulty.</p>
                            <div class="d-flex justify-content-between x-small text-body-tertiary mb-1">
                                <span>9/10 topics (Normal)</span>
                                <span>90%</span>
                            </div>
                            <div class="progress mb-3" style="height: 6px; background: rgba(var(--bs-emphasis-color-rgb), 0.05);">
                                <div class="progress-bar bg-primary" role="progressbar" style="width: 90%;"></div>
                            </div>
                            <button class="btn btn-primary rounded-pill w-100 py-1 fw-semibold text-white d-flex align-items-center justify-content-center gap-1 shadow-sm transition-all" style="font-size: 0.75rem;" onclick="location.href='/quiz'">
                                <i class="bx bx-target-lock"></i> Track
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Achievement Card 2 -->
                <div class="col-md-3">
                    <div class="card p-3 liquid-rim h-100 hvr-grow shadow-lg transition-all">
                        <div class="card-body p-0">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="achievement-icon bg-info bg-opacity-10 rounded-3 p-2">
                                    <i class="bx bx-run text-info fs-3"></i>
                                </div>
                                <div>
                                    <span class="badge bg-secondary-subtle text-body-secondary text-uppercase x-small mb-1">Rare</span>
                                    <h6 class="fw-bold m-0 card-title-text">Normal Decathlon</h6>
                                </div>
                            </div>
                            <p class="text-body-secondary x-small mb-3">Complete 10 normal quizzes in 10 different topics. Normal diff...</p>
                            <div class="d-flex justify-content-between x-small text-body-tertiary mb-1">
                                <span>9/10 topics (Normal)</span>
                                <span>90%</span>
                            </div>
                            <div class="progress mb-3" style="height: 6px; background: rgba(var(--bs-emphasis-color-rgb), 0.05);">
                                <div class="progress-bar bg-info" role="progressbar" style="width: 90%;"></div>
                            </div>
                            <button class="btn btn-info rounded-pill w-100 py-1 fw-semibold text-white d-flex align-items-center justify-content-center gap-1 shadow-sm transition-all" style="font-size: 0.75rem;" onclick="location.href='/quiz'">
                                <i class="bx bx-target-lock"></i> Track
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Achievement Card 3 -->
                <div class="col-md-3">
                    <div class="card p-3 liquid-rim h-100 hvr-grow shadow-lg transition-all position-relative overflow-hidden">
                        <div class="card-body p-0 d-flex flex-column justify-content-between h-100 text-center">
                            <div class="my-auto py-2">
                                <h2 class="fw-bold m-0 text-gradient display-5">62 / 128</h2>
                                <span class="x-small text-body-secondary text-uppercase tracking-wider fw-semibold">Collected</span>
                                <div class="progress my-2 mx-auto" style="height: 4px; width: 60%; background: rgba(var(--bs-emphasis-color-rgb), 0.05);">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: 48%;"></div>
                                </div>
                                <span class="x-small text-body-tertiary">48% COMPLETE</span>
                            </div>
                            <div class="d-flex justify-content-center gap-3 pt-2 border-top border-secondary border-opacity-10 x-small fw-semibold">
                                <span class="text-warning"><i class="bx bxs-flame"></i> 9,541</span>
                                <span class="text-info"><i class="bx bxs-bolt-circle"></i> 4,764</span>
                            </div>
                        </div>
                        <a href="/achievements" class="stretched-link" title="View Collected"></a>
                    </div>
                </div>

                <!-- Achievement Card 4 -->
                <div class="col-md-3">
                    <div class="card p-3 liquid-rim h-100 hvr-grow shadow-lg transition-all text-center d-flex flex-column justify-content-center align-items-center">
                        <div class="card-body p-0 d-flex flex-column justify-content-center w-100">
                            <div class="mb-2">
                                <span class="display-4 fw-bold text-warning">4</span>
                                <div class="x-small text-body-secondary text-uppercase tracking-wider fw-semibold mt-1">Ready to Collect</div>
                            </div>
                            <p class="text-body-tertiary x-small mb-3">Rewards waiting!</p>
                            <a href="/achievements" class="btn btn-warning rounded-pill w-100 py-2 fw-bold text-dark shadow-sm hvr-grow d-flex align-items-center justify-content-center gap-1" style="font-size: 0.8rem;">
                                Collect Rewards
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        <hr class="opacity-10 my-3">

        <!-- 3. Dynamic Learning Sections from MongoDB -->
        <?php 
        use TomLabs\Labs\Quiz;
        $sections = Quiz::getAllCategories();
        
        foreach ($sections as $sectionName => $categories): ?>
        <div class="mb-5">
            <h4 class="fw-bold theme-text mb-3"><?= $sectionName ?></h4>
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($categories as $cat): ?>
                <div class="col">
                    <a href="/quiz/<?= $cat['hash'] ?? $cat['_id'] ?>" class="card p-4 liquid-rim d-flex align-items-stretch hvr-grow text-decoration-none text-reset shadow-lg" style="height: 100%; cursor: pointer;">
                        <div class="card-body p-0 align-self-start justify-self-start">
                            <h5 class="card-title fw-bold mb-2"><?= $cat['title'] ?></h5>
                            <p class="card-text text-body-secondary small mb-0 opacity-75"><?= $cat['desc'] ?></p>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
