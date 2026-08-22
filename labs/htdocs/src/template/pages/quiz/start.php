<?php
use TomLabs\Labs\Quiz;

$parentTopic = Session::get('parent_topic');
$subtopic = Session::get('current_subtopic');

$difficultyFilter = $_GET['difficulty'] ?? $_COOKIE['quiz_difficulty_filter'] ?? 'normal';
$difficultyFilter = strtolower($difficultyFilter);

// Reinforce cookie from PHP side
if (isset($_GET['difficulty']) || isset($_COOKIE['quiz_difficulty_filter'])) {
    setcookie('quiz_difficulty_filter', $difficultyFilter, time() + (86400 * 30), "/");
}

// Debugging
if (class_exists('Session')) {
    logit("Difficulty Filter active: " . $difficultyFilter, "quiz_debug");
}

// Fetch real quizzes from MongoDB (High Density Limit)
$quizzes = Quiz::getRecentForSubtopic($subtopic['_id'], 8, 0, $difficultyFilter);

$user = Session::getUser();
$userStats = $user ? Quiz::getUserStats($user->getEmail()) : ['zeal' => 0, 'jolt' => 0];
$availableJolt = $userStats['jolt'] ?? 0;
?>

<div class="flex-grow-1 px-3 blur rounded-0 border-0 shadow-none d-flex flex-column">
<div class="fade-in pb-2 quiz-container flex-grow-1 d-flex flex-column">
    <div class="evaluation-bg"></div>
    <div class="container-fluid px-4 pt-2">
        
        <!-- 1. Header Section -->
        <div class="row g-3 mb-4 position-relative" style="z-index: 2;">
            <div class="col-lg-8">
                <h1 class="fs-4 fw-bold theme-text mb-0"><?= $parentTopic['title'] ?></h1>
                <p class="text-body-secondary mb-3"><?= $parentTopic['desc'] ?></p>
                <p class="text-body-secondary small">
                    Quiz based on <strong class="theme-text fw-bold"><?= $subtopic['title'] ?></strong>, <?= $subtopic['desc'] ?? '' ?>
                </p>
            </div>
            <div class="col-lg-4 text-lg-end d-flex flex-column justify-content-center align-items-lg-end">
                <div class="d-flex flex-column align-items-end gap-2">
                    <button class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm transition-all" onclick="triggerGeneration()">
                        <i class="bx bxs-zap me-1"></i>Spot Quiz
                    </button>
                    <div id="difficultySelector" class="difficulty-modes">
                        <button class="diff-btn btn-easy <?= $difficultyFilter == 'easy' ? 'active' : '' ?>" data-diff="easy">Easy</button>
                        <button class="diff-btn btn-normal <?= $difficultyFilter == 'normal' ? 'active' : '' ?>" data-diff="normal">Normal</button>
                        <button class="diff-btn btn-hard <?= $difficultyFilter == 'hard' ? 'active' : '' ?>" data-diff="hard">Hard</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Navigation Tabs -->
        <?php 
            $parentId = $parentTopic['hash'] ?? $parentTopic['_id'];
            $subtopicId = $subtopic['hash'] ?? $subtopic['_id'];
        ?>
        <div class="d-flex align-items-center gap-2 mb-4 overflow-auto pb-2 flex-nowrap quiz-tabs-row position-relative" style="z-index: 2;">
            <a href="/quiz/<?= $parentId ?>" class="btn btn-pill-outline">
                <i class="bx bx-left-arrow-alt me-1"></i>Topics
            </a>
            <a href="/quiz/<?= $parentId ?>/Recent/<?= $subtopicId ?>" class="btn <?= $activeTab === 'recent' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">
                Recent 📂 in <?= $subtopic['title'] ?>
            </a>
            <a href="/quiz/<?= $parentId ?>/trending" class="btn <?= $activeTab === 'trending' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Trending</a>
            <a href="/quiz/<?= $parentId ?>/completed" class="btn <?= $activeTab === 'completed' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Completed ✅</a>
            <a href="/quiz/<?= $parentId ?>/leaderboard" class="btn <?= $activeTab === 'leaderboard' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Leaderboard <span class="badge-new">New 🆕 ✨</span></a>
        </div>

        <hr class="border-secondary opacity-10 mb-4 position-relative" style="z-index: 2;">

        <!-- 3. Content Area -->
<script>
// Masonry Initialization Engine
function initQuizMasonry() {
    const grids = document.querySelectorAll('.quiz-masonry-row');
    grids.forEach(grid => {
        // Initialize Masonry
        const msnry = new Masonry(grid, {
            itemSelector: '.quiz-card-item',
            percentPosition: true,
            transitionDuration: '0.4s'
        });

        // Layout again after images/fonts load
        if (typeof imagesLoaded !== 'undefined') {
            imagesLoaded(grid).on('progress', () => {
                msnry.layout();
            });
        }
        
        // Expose to window for manual triggers
        grid.msnry = msnry;
    });
}

// Trigger on load
document.addEventListener('DOMContentLoaded', () => {
    initQuizMasonry();
    
    // Mutation Observer to handle infinite scroll / AJAX loads
    const observer = new MutationObserver((mutations) => {
        initQuizMasonry();
    });
    
    const container = document.getElementById('recent-quizzes-grid');
    if (container) {
        observer.observe(container, { childList: true });
        
        // Re-layout when visibility changes (Fixes card size issue when switching tabs)
        const visibilityObserver = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) {
                console.log("[Quiz Hub] Grid visible, re-laying out Masonry...");
                if (container.msnry) container.msnry.layout();
            }
        }, { threshold: 0.1 });
        visibilityObserver.observe(container);
    }

    // Watch for CoreUI theme changes (Light/Dark toggle) and re-layout to fix vertical gaps
    const themeObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === 'data-coreui-theme') {
                setTimeout(() => {
                    document.querySelectorAll('.quiz-masonry-row').forEach(grid => {
                        if (grid.msnry) grid.msnry.layout();
                    });
                }, 150);
            }
        });
    });
    themeObserver.observe(document.documentElement, { attributes: true });
});
</script>

<div class="container-fluid py-4 px-0">
    <div id="topic-content-container" class="position-relative" style="z-index: 2;">
        <!-- Default: Quizzes Grid -->
        <?php $activeTab = Session::get('active_tab', 'recent'); ?>
        <div id="recent-quizzes-grid" class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-4 quiz-masonry-row <?= $activeTab !== 'recent' ? 'd-none' : '' ?>">
            <?php if (!empty($quizzes)): foreach ($quizzes as $q): 
                $qDiff = strtolower($q['difficulty'] ?? 'normal');
                $qJolt = ($qDiff === 'easy') ? 1 : (($qDiff === 'hard') ? 5 : 2);
                include __DIR__ . '/_card.php'; 
            endforeach; endif; ?>
        </div>

        <!-- Empty State (Positioned outside the grid to prevent Masonry layout bugs) -->
        <div id="recent-empty-state" class="<?= ($activeTab === 'recent' && empty($quizzes)) ? '' : 'd-none' ?>">
            <div class="empty-state-container mx-auto mt-4 p-5 text-center position-relative liquid-rim shadow-lg" 
                 style="max-width: 650px; border: 1px dashed rgba(var(--cui-emphasis-color-rgb), 0.15); border-radius: 24px;">
                <div class="position-absolute top-50 start-50 translate-middle w-100 h-100" style="background: radial-gradient(circle, rgba(46, 184, 87, 0.05) 0%, transparent 60%); z-index: 0; pointer-events: none;"></div>
                <div class="position-relative" style="z-index: 1;">
                    <div class="mb-4 position-relative d-inline-block">
                        <div class="position-absolute top-50 start-50 translate-middle bg-success rounded-circle" style="width: 80px; height: 80px; opacity: 0.1; filter: blur(15px);"></div>
                        <div class="bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center mx-auto border border-success border-opacity-25 shadow-sm" style="width: 80px; height: 80px;">
                            <i class="bx bxs-bot display-4 text-success opacity-75"></i>
                        </div>
                    </div>
                    <h4 class="fw-bold theme-text mb-3">No Active Intelligence Found</h4>
                    <p class="text-body-secondary small mb-4 mx-auto lh-base" style="max-width: 450px;">
                        The mission parameters for <strong class="theme-text fw-bold"><?= strtolower($subtopic['title'] ?? 'this topic') ?></strong> are currently blank. 
                        Deploy a new <strong class="text-success">Spot Quiz</strong> using the engine above to begin your training.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <button class="btn btn-outline-secondary rounded-pill px-4 fw-bold shadow-sm" onclick="window.history.back()">Return to Topics</button>
                        <button class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" style="background: #2eb857 !important; border: none !important;" onclick="triggerGeneration()">Deploy Spot Quiz</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Infinite Scroll Sentinel -->
        <div id="infinite-scroll-sentinel" class="text-center py-4" style="min-height: 60px;">
            <div id="scroll-loader" class="spinner-border theme-text d-none" role="status" style="width: 2rem; height: 2rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div id="no-more-msg" class="text-body-secondary small d-none mt-2">
                <span class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill border" 
                      style="background: rgba(var(--cui-emphasis-color-rgb), 0.05); 
                             color: var(--cui-secondary-color, rgba(var(--cui-emphasis-color-rgb), 0.7)); 
                             border-color: rgba(var(--cui-emphasis-color-rgb), 0.12) !important; 
                             font-size: 0.75rem; 
                             font-weight: 500;
                             letter-spacing: 0.3px;
                             backdrop-filter: blur(8px);
                             -webkit-backdrop-filter: blur(8px);">
                    <i class="bx bx-check-circle text-success fs-6"></i> You've explored all challenges for this topic.
                </span>
            </div>
        </div>

        <!-- AJAX Content Container -->
        <div id="ajax-tab-content" class="<?= $activeTab !== 'recent' ? '' : 'd-none' ?>">
            <?php 
            if ($activeTab === 'trending') {
                $quizzes = \TomLabs\Labs\Quiz::getTrendingForTopic($parentTopic['_id']);
                if (empty($quizzes)) {
                    echo '<div class="col-12 text-center py-5 animate__animated animate__fadeIn"><div class="empty-state-card opacity-50"><i class="bx bx-trending-up display-1 mb-3"></i><h5 class="text-body-secondary">No trending quizzes yet.</h5></div></div>';
                } else {
                    echo '<div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-4 quiz-masonry-row animate__animated animate__fadeIn">';
                    foreach ($quizzes as $q) {
                        $qDiff = strtolower($q['difficulty'] ?? 'normal');
                        $qJolt = ($qDiff === 'easy') ? 1 : (($qDiff === 'hard') ? 5 : 2);
                        $userEmail = Session::getUser() ? Session::getUser()->getEmail() : null;
                        $isAttempted = $userEmail ? \TomLabs\Labs\Quiz::hasAttempted($userEmail, $q['hash']) : false;
                        include __DIR__ . '/_card.php';
                    }
                    echo '</div>';
                }
            } elseif ($activeTab === 'completed') {
                $userEmail = Session::getUser() ? Session::getUser()->getEmail() : null;
                $quizzes = \TomLabs\Labs\Quiz::getCompletedForUser($userEmail, $parentTopic['_id']);
                if (empty($quizzes)) {
                    echo '<div class="col-12 text-center py-5 animate__animated animate__fadeIn"><div class="empty-state-card opacity-50"><i class="bx bx-check-circle display-1 mb-3"></i><h5 class="text-body-secondary">No completed quizzes yet.</h5></div></div>';
                } else {
                    echo '<div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-4 quiz-masonry-row animate__animated animate__fadeIn">';
                    foreach ($quizzes as $q) {
                        $qDiff = strtolower($q['difficulty'] ?? 'normal');
                        $qJolt = ($qDiff === 'easy') ? 1 : (($qDiff === 'hard') ? 5 : 2);
                        $isAttempted = true;
                        include __DIR__ . '/_card.php';
                    }
                    echo '</div>';
                }
            } elseif ($activeTab === 'leaderboard') {
                $leaderboard = \TomLabs\Labs\Quiz::getLeaderboardForTopic($parentTopic['_id']);
                include __DIR__ . '/_leaderboard.php';
            }
            ?>
        </div>
    </div>
</div>
</div>
</div>

<!-- Empty State Template for Difficulty Filter -->
<template id="quiz-empty-state-template">
    <div class="empty-state-container mx-auto mt-4 p-5 text-center position-relative liquid-rim shadow-lg animate__animated animate__fadeIn" 
         style="max-width: 650px; border: 1px dashed rgba(var(--cui-emphasis-color-rgb), 0.15); border-radius: 24px;">
        <div class="position-absolute top-50 start-50 translate-middle w-100 h-100" style="background: radial-gradient(circle, rgba(46, 184, 87, 0.05) 0%, transparent 60%); z-index: 0; pointer-events: none;"></div>
        <div class="position-relative" style="z-index: 1;">
            <div class="mb-4 position-relative d-inline-block">
                <div class="position-absolute top-50 start-50 translate-middle bg-success rounded-circle" style="width: 80px; height: 80px; opacity: 0.1; filter: blur(15px);"></div>
                <div class="bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center mx-auto border border-success border-opacity-25 shadow-sm" style="width: 80px; height: 80px;">
                    <i class="bx bxs-bot display-4 text-success opacity-75"></i>
                </div>
            </div>
            <h4 class="fw-bold theme-text mb-3">No Quizzes Found</h4>
            <p class="text-body-secondary small mb-4 lh-base mx-auto" style="max-width: 420px;">
                There are currently no challenges for the <strong class="selected-diff-text text-warning"></strong> level in this topic. Be the first to generate a professional AI-powered quiz!
            </p>
            <button class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm transition-all" onclick="triggerGeneration()" style="background: #2eb857 !important; border: none !important;">
                <i class="bx bxs-zap me-1"></i> Generate with AI
            </button>
        </div>
    </div>
</template>

<script>
// Tab switching is now handled by full page loads via <a> tags.

// Handle initial tab from server-side session
document.addEventListener('DOMContentLoaded', () => {
    const initialTab = '<?= Session::get('active_tab', 'recent') ?>';
    // No action needed for pre-rendered content
});
</script>

<!-- Spot Quiz Modal (Two-Step: Confirm -> Generate) -->
<div class="modal fade" id="spotQuizModal" tabindex="-1" aria-hidden="true" data-coreui-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content liquid-rim border-0 shadow-lg overflow-hidden" style="background-color: rgba(15, 20, 25, 0.95) !important; border: 1px solid rgba(255, 255, 255, 0.08) !important;">
            
            <!-- STEP 1: Confirmation View -->
            <div id="modal-view-confirm" class="modal-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4 border-bottom border-white border-opacity-10 pb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center border border-success border-opacity-25" style="width: 40px; height: 40px;">
                            <i class="bx bxs-zap fs-4 text-success"></i>
                        </div>
                        <h5 class="fw-bold text-white mb-0">Spot Quiz AI Deployment</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <p class="text-white small mb-4 opacity-90">
                    System ready for **AI Intelligence Deployment**. We will generate a unique quiz for this topic based on your current difficulty settings.<br>
                    <span class="opacity-60 smaller mt-2 d-block">A debit of 1 ⚡️ will be applied upon successful initialization.</span>
                </p>

                <!-- Cost Table (Reference Match) -->
                <div class="table-responsive mb-4 rounded-3 overflow-hidden border border-white border-opacity-10">
                    <table class="table table-dark table-borderless table-sm text-center small mb-0" style="background: rgba(255,255,255,0.03);">
                        <thead>
                            <tr class="opacity-40 border-bottom border-white border-opacity-10" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px;">
                                <th class="py-2">Deployment Cost</th>
                                <th class="py-2">Current Jolt</th>
                                <th class="py-2">Projected Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="fw-bold fs-6">
                                <td class="py-3">1 ⚡️</td>
                                <td class="py-3 text-warning"><?= $availableJolt ?> ⚡️</td>
                                <td class="py-3 text-success"><?= max(0, $availableJolt - 1) ?> ⚡️</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-link text-white text-decoration-none px-4 btn-sm fw-bold opacity-50 hover-opacity-100" data-coreui-dismiss="modal">Abort</button>
                    <button type="button" class="btn btn-success rounded-pill px-4 btn-sm fw-bold shadow-sm" onclick="startGenerationProcess()" style="background: #2eb857 !important; border: none !important;">
                        Initialize Deployment
                    </button>
                </div>
            </div>

            <!-- STEP 2: Generation View (Hidden initially) -->
            <div id="modal-view-progress" class="modal-body p-5 text-center d-none">
                <div class="spinner-grow text-success mb-4" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h5 class="fw-bold text-white mb-2">Generating Intelligence...</h5>
                
                <p class="text-body-secondary small mb-4" id="modalSubtext">
                    Our AI is currently drafting your specialized quiz questions. This mission takes a few moments.
                </p>

                <div class="mb-2">
                    <div class="fw-bold small theme-text mb-2 text-start d-flex justify-content-between">
                        <span id="genStatus" class="opacity-50">Initializing...</span>
                        <span id="genPercent" class="text-success">0%</span>
                    </div>
                    <div class="progress rounded-pill bg-white bg-opacity-10" style="height: 6px;">
                        <div id="genProgress" class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                             role="progressbar" style="width: 0%; transition: width 0.5s ease;"></div>
                    </div>
                </div>

                <div id="modalActions" class="d-none mt-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-coreui-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info rounded-pill px-4 fw-bold shadow-sm ms-2" onclick="triggerGeneration()">Retry Mission</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Assets are now bundled into app.js/app.css
?>

<script>
window.QuizConfig = {
    parentTopicId: '<?= $parentTopic['_id'] ?>',
    subtopicId: '<?= $subtopic['_id'] ?>'
};

/**
 * Quiz Start Module (Restored to Template for Stability)
 * Handles AI generation triggers and Infinite Scroll
 */

let selectedDiff = '<?= $difficultyFilter ?>';
let generationModal = null;

// Initialize Modal and Handle BFcache
function initModal() {
    const modalEl = document.getElementById('spotQuizModal');
    if (modalEl) {
        // Move modal to body so it's not trapped inside containers
        // with backdrop-filter/transform that break position:fixed
        document.body.appendChild(modalEl);
        generationModal = coreui.Modal.getInstance(modalEl) || new coreui.Modal(modalEl);
        generationModal.hide();
    }
}

document.addEventListener('DOMContentLoaded', initModal);
window.addEventListener('pageshow', function (event) {
    if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
        if (!generationModal) initModal();
        if (generationModal) generationModal.hide();
    }
});

// Difficulty Selection
document.querySelectorAll('.difficulty-modes .diff-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        if (this.classList.contains('active')) return; 

        document.querySelectorAll('.difficulty-modes .diff-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selectedDiff = this.dataset.diff;
        
        // Persist to cookie for PHP access on reload
        document.cookie = "quiz_difficulty_filter=" + selectedDiff + "; path=/; max-age=" + (86400 * 30) + "; SameSite=Lax";
        
        // Reset and Reload
        const container = document.getElementById('recent-quizzes-grid');
        if (container) container.innerHTML = '<div class="w-100 text-center py-5"><div class="spinner-border theme-text" role="status"></div></div>';
        
        scrollOffset = 0;
        hasMoreQuizzes = true;
        loadMoreQuizzes(true); 
    });
});

/**
 * Global Trigger: AI Generation (Now Two-Step)
 */
window.triggerGeneration = function () {
    console.log("[Quiz Hub] Opening Spot Quiz Confirmation...");
    
    // Ensure views are reset to Step 1 (Confirm)
    const confirmView = document.getElementById('modal-view-confirm');
    const progressView = document.getElementById('modal-view-progress');
    if (confirmView) confirmView.classList.remove('d-none');
    if (progressView) progressView.classList.add('d-none');

    const modalEl = document.getElementById('spotQuizModal');
    if (!modalEl) {
        console.error("[Quiz Hub] spotQuizModal NOT FOUND in DOM!");
        return;
    }
    
    const modalInstance = coreui.Modal.getInstance(modalEl) || new coreui.Modal(modalEl);
    if (modalInstance) modalInstance.show();
};

window.startGenerationProcess = function() {
    console.log("[Quiz Hub] Starting Generation Process...");
    
    // Switch to Progress View
    const confirmView = document.getElementById('modal-view-confirm');
    const progressView = document.getElementById('modal-view-progress');
    if (confirmView) confirmView.classList.add('d-none');
    if (progressView) progressView.classList.remove('d-none');

    // Reset Progress UI
    const progress = document.getElementById('genProgress');
    const percent = document.getElementById('genPercent');
    const status = document.getElementById('genStatus');
    const actions = document.getElementById('modalActions');
    const subtext = document.getElementById('modalSubtext');

    if (progress) {
        progress.style.width = '5%';
        progress.classList.remove('bg-danger');
        progress.classList.add('bg-info');
    }
    if (percent) percent.innerText = '5%';
    if (status) status.innerText = 'Initializing...';
    if (actions) actions.classList.add('d-none');
    if (subtext) {
        subtext.classList.remove('text-danger');
        subtext.innerText = 'Please wait while we generate your quiz... this may take couple minutes.';
    }

    // Trigger API
    fetch(`/api/quiz/generate?topic=${window.QuizConfig.parentTopicId}&subtopic=${window.QuizConfig.subtopicId}&diff=${selectedDiff}`)
        .then(res => res.json())
        .then(data => {
            if (data.id) {
                pollStatus(data.id);
                // Update header Jolt balance immediately
                if (typeof data.new_jolt !== 'undefined') {
                    const globalJolt = document.getElementById('header-jolt');
                    if (globalJolt) globalJolt.innerText = data.new_jolt.toLocaleString();
                }
            } else {
                showError(data.error || 'Failed to start generation.');
            }
        })
        .catch(err => {
            console.error("[Quiz Hub] API Error:", err);
            showError('Connection error. Please check your network.');
        });
};

function pollStatus(jobId) {
    const check = () => {
        fetch(`/api/quiz/job_status?job_id=${jobId}`)
            .then(res => res.json())
            .then(job => {
                if (!job) return;

                const progress = job.percentage || 5;
                const pBar = document.getElementById('genProgress');
                const pText = document.getElementById('genPercent');
                const sText = document.getElementById('genStatus');

                if (pBar) pBar.style.width = progress + '%';
                if (pText) pText.innerText = progress + '%';
                if (sText) sText.innerText = job.status_text || `Attempt: 1 / 3`;

                if (job.generation_success && job.result_hash) {
                    if (sText) sText.innerText = 'Generation Complete!';
                    setTimeout(() => {
                        htmx.ajax('GET', `/quiz/v/${job.result_hash}`, {target: '#main-content'});
                    }, 800);
                } else if (job.generation_failed) {
                    showError(job.error || 'AI Generation failed.');
                } else {
                    setTimeout(check, 1500);
                }
            });
    };
    check();
}

function showError(msg) {
    const subtext = document.getElementById('modalSubtext');
    const actions = document.getElementById('modalActions');
    const progress = document.getElementById('genProgress');

    if (subtext) {
        subtext.innerText = msg;
        subtext.classList.add('text-danger');
    }
    if (actions) actions.classList.remove('d-none');
    if (progress) progress.classList.add('bg-danger');
}

// --- INFINITE SCROLL & STATE PERSISTENCE ---
if (window.QuizConfig && window.QuizConfig.subtopicId) {
    const subtopicIdForState = window.QuizConfig.subtopicId;
    let scrollOffset = 8;
    let isScrollLoading = false;
    let hasMoreQuizzes = true;

    const scrollObserver = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && !isScrollLoading && hasMoreQuizzes) {
            loadMoreQuizzes();
        }
    }, { threshold: 0.5 });

    const scrollSentinel = document.getElementById('infinite-scroll-sentinel');
    if (scrollSentinel) {
        scrollObserver.observe(scrollSentinel);
        document.addEventListener('DOMContentLoaded', restoreScrollState);
    }

    function saveScrollState() {
        const state = { offset: scrollOffset, scrollTop: window.scrollY, subtopic: subtopicIdForState };
        sessionStorage.setItem('quiz_hub_state_' + subtopicIdForState, JSON.stringify(state));
    }

    function restoreScrollState() {
        const saved = sessionStorage.getItem('quiz_hub_state_' + subtopicIdForState);
        if (!saved) return;
        const state = JSON.parse(saved);
        if (state.subtopic !== subtopicIdForState) return;
        
        // We rely on PHP-initialized selectedDiff for consistency
        document.querySelectorAll('.difficulty-modes .diff-btn').forEach(b => {
            if (b.dataset.diff === selectedDiff) b.classList.add('active');
            else b.classList.remove('active');
        });

        if (state.offset > 8) {
            const fetchLimit = state.offset;
            isScrollLoading = true;
            fetch(`/api/quiz/list?subtopic_id=${subtopicIdForState}&offset=0&limit=${fetchLimit}&difficulty=${selectedDiff}`)
                .then(res => res.text())
                .then(html => {
                    if (html && html.trim().length > 0) {
                        const container = document.getElementById('recent-quizzes-grid');
                        container.innerHTML = html; 
                        
                        const temp = document.createElement('div');
                        temp.innerHTML = html;
                        const cardCount = temp.querySelectorAll('.quiz-card-item').length;
                        
                        scrollOffset = cardCount;
                        // For restoration, we assume if we got cards, there might be more if we hit the limit
                        hasMoreQuizzes = cardCount >= 8; 
                        setTimeout(() => window.scrollTo({ top: state.scrollTop, behavior: 'instant' }), 100);
                    }
                })
                .finally(() => isScrollLoading = false);
        }
    }



    function loadMoreQuizzes(replace = false) {
        isScrollLoading = true;
        const loader = document.getElementById('scroll-loader');
        if (loader) loader.classList.remove('d-none');
        
        const currentOffset = replace ? 0 : scrollOffset;
        const fetchLimit = 8;
        fetch(`/api/quiz/list?subtopic_id=${subtopicIdForState}&offset=${currentOffset}&limit=${fetchLimit}&difficulty=${selectedDiff}`)
            .then(res => res.text())
            .then(html => {
                const container = document.getElementById('recent-quizzes-grid');
                if (!container) return;

                if (html && html.trim().length > 0) {
                    const temp = document.createElement('div');
                    temp.innerHTML = html;
                    const newItems = Array.from(temp.querySelectorAll('.quiz-card-item'));

                    if (replace) {
                        const emptyState = document.getElementById('recent-empty-state');
                        if (emptyState) emptyState.classList.add('d-none');
                        
                        container.innerHTML = html;
                    } else {
                        newItems.forEach(item => {
                            container.appendChild(item);
                        });
                    }

                    if (container.msnry) {
                        if (replace) {
                            container.msnry.reloadItems();
                        } else {
                            container.msnry.appended(newItems);
                        }
                        imagesLoaded(container).on('progress', () => {
                            container.msnry.layout();
                        });
                    }

                    const newCardsCount = newItems.length;
                    if (replace) scrollOffset = newCardsCount;
                    else scrollOffset += newCardsCount;
                    
                    hasMoreQuizzes = newCardsCount >= fetchLimit;
                    saveScrollState();
                } else {
                    console.log("[Quiz Hub] No more quizzes found.");
                    hasMoreQuizzes = false;
                    if (replace) {
                        container.innerHTML = '';
                        const emptyState = document.getElementById('recent-empty-state');
                        if (emptyState) {
                            emptyState.classList.remove('d-none');
                            // Update text if difficulty filter was used
                            const diffText = emptyState.querySelector('.selected-diff-text');
                            if (diffText) diffText.innerText = selectedDiff.toUpperCase();
                        } else {
                            // Fallback to template if div doesn't exist
                            const template = document.getElementById('quiz-empty-state-template');
                            if (template) {
                                const clone = template.content.cloneNode(true);
                                const diffText = clone.querySelector('.selected-diff-text');
                                if (diffText) diffText.innerText = selectedDiff.toUpperCase();
                                container.parentNode.insertBefore(clone, container.nextSibling);
                            }
                        }
                    }
                }
            })
            .finally(() => {
                isScrollLoading = false;
                if (loader) loader.classList.add('d-none');
                const noMore = document.getElementById('no-more-msg');
                if (!hasMoreQuizzes) {
                    if (noMore) noMore.classList.remove('d-none');
                } else {
                    if (noMore) noMore.classList.add('d-none');
                }
            });
    }
    window.addEventListener('scroll', () => { if (!isScrollLoading) saveScrollState(); });
}
</script>
<style>.difficulty-modes {
    display: flex !important;
    padding: 4px !important;
    border-radius: 50px !important;
    gap: 4px !important;
    background: rgba(255, 255, 255, 0.08) !important;
    backdrop-filter: saturate(2.2) brightness(1.1) blur(18px) !important;
    -webkit-backdrop-filter: saturate(2.2) brightness(1.1) blur(18px) !important;
    border: none !important;
    box-shadow: 
        inset 0 0 0 1px rgba(255, 255, 255, 0.12), 
        inset 0 1px 0 0 rgba(255, 255, 255, 0.2), 
        rgba(0, 0, 0, 0.15) 0px 4px 12px 0px !important;
    transition: all 0.4s ease !important;
}

.diff-btn {
    border: none !important;
    background: transparent !important;
    color: var(--cui-body-color) !important;
    opacity: 0.7 !important;
    padding: 6px 18px !important;
    border-radius: 50px !important;
    font-size: 0.75rem !important;
    font-weight: 800 !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    text-transform: uppercase !important;
    letter-spacing: 0.8px !important;
    white-space: nowrap !important;
}

.diff-btn:hover {
    color: var(--cui-body-color) !important;
    opacity: 1 !important;
    background: rgba(var(--cui-emphasis-color-rgb), 0.08) !important;
}

.diff-btn.active {
    color: #fff !important;
    opacity: 1 !important;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2) !important;
}

.diff-btn.btn-easy.active { 
    background: #2eb857 !important; 
    box-shadow: 0 4px 15px rgba(46, 184, 87, 0.4) !important; 
}
.diff-btn.btn-normal.active { 
    background: #f9b115 !important; 
    box-shadow: 0 4px 15px rgba(249, 177, 21, 0.4) !important; 
}
.diff-btn.btn-hard.active { 
    background: #e55353 !important; 
    box-shadow: 0 4px 15px rgba(229, 83, 83, 0.4) !important; 
}

/* Glass effect for quiz tabs */
.quiz-tabs-row {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.quiz-tabs-row::-webkit-scrollbar {
    display: none;
}
</style>
