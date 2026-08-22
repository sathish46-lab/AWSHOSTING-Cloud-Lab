<?php
$topic = Session::get('current_topic');
$subtopics = $topic['subtopics'] ?? [];
?>

<div class="flex-grow-1 px-3 blur rounded-0 border-0 shadow-none d-flex flex-column">
<div class="quiz-topic-view fade-in pb-4 quiz-container flex-grow-1 d-flex flex-column">
    <div class="container-fluid px-4 pt-2">
        <!-- 1. Header Section -->
        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <h1 class="fs-4 fw-bold theme-text mb-0"><?= $topic['title'] ?></h1>
                <p class="text-body-secondary small mb-2 opacity-75"><?= $topic['desc'] ?></p>
                
                <p class="x-small text-body-tertiary lh-base mb-0 opacity-50" style="max-width: 750px;">
                    Choose from the list of topics below, or choose "Surprise Me" to get a random topic for 20% higher 
                    <span class="text-warning fw-bold">Zeal 🔥</span> Explore the Recent, Trending to find what others are playing.
                </p>
            </div>
            <!-- <div class="col-lg-6 text-lg-end d-flex flex-column justify-content-center align-items-lg-end">
                <div class="d-inline-flex flex-wrap align-items-center gap-2">
                    <div class="performance-modes d-flex rounded-pill overflow-hidden shadow-sm border border-white border-opacity-10">
                        <button class="mode-btn btn-surprise"><i class="bx bxs-bot me-1"></i>Surprise</button>
                        <button class="mode-btn btn-sprint"><i class="bx bxs-zap me-1"></i>Sprint</button>
                        <button class="mode-btn btn-rapid"><i class="bx bxs-flame me-1"></i>Rapid</button>
                        <button class="mode-btn btn-blitz"><i class="bx bxs-bolt me-1"></i>Blitz</button>
                        <button class="mode-btn btn-marathon"><i class="bx bxs-trophy me-1"></i>Marathon</button>
                    </div>
                </div>
            </div> -->
        </div>

        <!-- 2. Navigation Tabs -->
        <?php 
            $activeTab = Session::get('active_tab', 'topics'); 
            $topicId = $topic['hash'] ?? $topic['_id'];
        ?>
        <div class="d-flex align-items-center gap-2 mb-4 overflow-auto pb-2 flex-nowrap quiz-tabs-row">
            <a href="/quiz/<?= $topicId ?>" class="btn <?= ($activeTab === 'topics' || $activeTab === 'recent') ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Topics</a>
            <div class="dropdown">
                <button class="btn btn-pill-outline dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    Recent 📂 in <?= $topic['title'] ?>
                </button>
                <ul class="dropdown-menu quiz-dropdown-menu shadow-lg border-0 mt-2">
                    <li><a class="dropdown-item" href="#">Recent in All</a></li>
                    <li><hr class="dropdown-divider opacity-10"></li>
                    <?php foreach ($subtopics as $sub): ?>
                        <li><a class="dropdown-item" href="/quiz/<?= $topic['hash'] ?? $topic['_id'] ?>/Recent/<?= $sub['hash'] ?? $sub['_id'] ?>">Recent in <?= $sub['title'] ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <a href="/quiz/<?= $topicId ?>/trending" class="btn <?= $activeTab === 'trending' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Trending</a>
            <a href="/quiz/<?= $topicId ?>/completed" class="btn <?= $activeTab === 'completed' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Completed ✅</a>
            <a href="/quiz/<?= $topicId ?>/leaderboard" class="btn <?= $activeTab === 'leaderboard' ? 'btn-pill-active shadow-sm' : 'btn-pill-outline' ?> topic-tab-btn">Leaderboard <span class="badge-new">New 🆕 ✨</span></a>
        </div>

        <hr class="border-secondary opacity-10 mb-5">

        <!-- 3. Content Area -->
        <div id="topic-content-container">
            <!-- Default: Sub-Topics Grid -->
            <?php $activeTab = Session::get('active_tab', 'topics'); ?>
            <div id="topics-grid" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 animate__animated animate__fadeIn <?= ($activeTab !== 'topics' && $activeTab !== 'recent') ? 'd-none' : '' ?>">
                <?php if (empty($subtopics)): ?>
                    <div class="col-12 text-center py-5">
                        <div class="empty-state-card">
                            <i class="bx bx-folder-open display-1 text-body-tertiary mb-3 opacity-25"></i>
                            <h4 class="text-body-secondary">No sub-topics available for this track.</h4>
                            <a href="/quiz" class="btn btn-outline-info rounded-pill px-4 mt-3">Back to Hub</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($subtopics as $sub): ?>
                    <div class="col">
                        <a href="/quiz/<?= $topic['hash'] ?? $topic['_id'] ?>/Recent/<?= $sub['hash'] ?? $sub['_id'] ?>" class="card p-4 liquid-rim d-flex align-items-stretch hvr-grow shadow-lg text-decoration-none text-reset" style="height: 100%; cursor: pointer;">
                            <div class="card-body p-0 align-self-start justify-self-start">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="bx <?= $sub['icon'] ?? 'bx-book-open' ?> fs-3 me-2" style="color: var(--cui-primary);"></i>
                                    <h5 class="card-title fw-bold mb-0"><?= $sub['title'] ?></h5>
                                </div>
                                <p class="card-text text-body-secondary small mb-0 opacity-75"><?= $sub['desc'] ?></p>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- AJAX Content Container -->
            <div id="ajax-tab-content" class="<?= ($activeTab !== 'topics' && $activeTab !== 'recent') ? '' : 'd-none' ?>">
                <?php 
                if ($activeTab === 'trending') {
                    $quizzes = \TomLabs\Labs\Quiz::getTrendingForTopic($topic['_id']);
                    if (empty($quizzes)) {
                        echo '<div class="col-12 text-center py-5 animate__animated animate__fadeIn"><div class="empty-state-card opacity-50"><i class="bx bx-trending-up display-1 mb-3"></i><h5 class="text-body-secondary">No trending quizzes yet. Be the first to play!</h5></div></div>';
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
                    $quizzes = \TomLabs\Labs\Quiz::getCompletedForUser($userEmail, $topic['_id']);
                    if (empty($quizzes)) {
                        echo '<div class="col-12 text-center py-5 animate__animated animate__fadeIn"><div class="empty-state-card opacity-50"><i class="bx bx-check-circle display-1 mb-3"></i><h5 class="text-body-secondary">You haven\'t completed any quizzes in this category yet.</h5></div></div>';
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
                    $leaderboard = \TomLabs\Labs\Quiz::getLeaderboardForTopic($topic['_id']);
                    include __DIR__ . '/_leaderboard.php';
                }
                ?>
            </div>
        </div>
    </div>
</div>
</div>

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

    // Watch for CoreUI theme changes (Light/Dark toggle) and re-layout to fix vertical gaps
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === 'data-coreui-theme') {
                // Add a small delay to allow CSS transitions to complete before calculating heights
                setTimeout(() => {
                    grids.forEach(grid => {
                        if (grid.msnry) grid.msnry.layout();
                    });
                }, 150);
            }
        });
    });
    observer.observe(document.documentElement, { attributes: true });
}



// Handle initial tab from server-side session
document.addEventListener('DOMContentLoaded', () => {
    initQuizMasonry();
    const initialTab = '<?= Session::get('active_tab', 'topics') ?>';
});
</script>

