<?php
$db = DatabaseConnection::getDefaultDatabase();
$lessonsRaw = $db->ai_lessons->find([], ['sort' => ['_id' => -1]])->toArray();
$user = Session::getUser();
$currentUsername = $user ? $user->getUsername() : '';
$currentEmail = $user ? $user->getEmail() : '';
$currentUserId = $user ? (int)$user->getUserId() : 0;

$uiPrefs = $user ? ($user->getUiPreferences() ?? []) : [];
$prefLessonDiff = $uiPrefs['lesson_difficulty'] ?? 'Beginner';
$prefLessonDiff = ucfirst(strtolower($prefLessonDiff));

$prefFilters = $uiPrefs['learn_ai_filters'] ?? ['tab' => 'all', 'level' => 'All Levels'];
if (is_string($prefFilters)) {
    $dec = json_decode($prefFilters, true);
    if (json_last_error() === JSON_ERROR_NONE && (is_array($dec) || is_object($dec))) {
        $prefFilters = $dec;
    }
}
if (is_object($prefFilters) && method_exists($prefFilters, 'getArrayCopy')) {
    $prefFilters = $prefFilters->getArrayCopy();
}
$prefFilters = (array)$prefFilters;
$prefFilterTab = strtolower(trim($prefFilters['tab'] ?? 'all'));
$prefFilterLevel = trim($prefFilters['level'] ?? 'All Levels');
if (strcasecmp($prefFilterLevel, 'all levels') === 0 || empty($prefFilterLevel)) {
    $prefFilterLevel = 'All Levels';
} else {
    $prefFilterLevel = ucfirst(strtolower($prefFilterLevel));
}

$lessonsAll = [];
$seen = [];
foreach ($lessonsRaw as $l) {
    $title = $l['title'] ?? '';
    if (!isset($seen[$title])) {
        // Determine if current logged-in user is the author
        $isAuthor = false;
        if (!empty($l['author']) && strcasecmp($l['author'], $currentUsername) === 0) {
            $isAuthor = true;
        } elseif (!empty($l['author_email']) && strcasecmp($l['author_email'], $currentEmail) === 0) {
            $isAuthor = true;
        } elseif (!empty($l['user_id']) && (int)$l['user_id'] === $currentUserId && $currentUserId > 0) {
            $isAuthor = true;
        }

        // Check visibility: if Private, only the created author should see it
        $visibility = $l['visibility'] ?? 'Public';
        if (strcasecmp($visibility, 'Private') === 0 && !$isAuthor) {
            continue;
        }

        // Compute likes count and whether current user liked it
        $likesList = $l['likes'] ?? [];
        if (!is_array($likesList)) {
            $likesList = is_object($likesList) && method_exists($likesList, 'getArrayCopy') ? $likesList->getArrayCopy() : (array)$likesList;
        }
        $likesCount = intval($l['likes_count'] ?? count($likesList));
        $likedByCurrent = false;
        if (in_array($currentUsername, $likesList) || ($currentUserId > 0 && in_array((string)$currentUserId, $likesList))) {
            $likedByCurrent = true;
        } else {
            try {
                if (!empty($currentUsername) && $db->ai_lesson_likes->findOne(['lesson_id' => (string)$l['_id'], 'username' => $currentUsername])) {
                    $likedByCurrent = true;
                }
            } catch (Throwable $t) {}
        }
        $l['likes_count'] = $likesCount;
        $l['liked_by_current'] = $likedByCurrent;
        $promptUnlocked = $isAuthor;
        if (!$promptUnlocked && $currentUserId > 0) {
            try {
                if ($db->ai_unlocked_prompts->findOne(['user_id' => $currentUserId, 'lesson_id' => (string)$l['_id']])) {
                    $promptUnlocked = true;
                }
            } catch (Throwable $t) {}
        }
        $l['prompt_unlocked'] = $promptUnlocked;

        $seen[$title] = true;
        $lessonsAll[] = $l;
    }
}

$lessons = $lessonsAll;
if (strcasecmp($prefFilterLevel, 'All Levels') !== 0 && !empty($prefFilterLevel)) {
    $lessons = array_filter($lessons, function($l) use ($prefFilterLevel) {
        $lLevel = $l['level'] ?? 'Beginner';
        return strcasecmp(trim($lLevel), trim($prefFilterLevel)) === 0;
    });
}
if ($prefFilterTab === 'continue') {
    $filtered = array_filter($lessons, fn($l) => !empty($l['progress']) && intval($l['progress']) > 0);
    if (!empty($filtered)) $lessons = array_values($filtered);
} elseif ($prefFilterTab === 'explore') {
    $filtered = [];
    foreach ($lessons as $l) {
        $isAuth = (!empty($l['author']) && strcasecmp($l['author'], $currentUsername) === 0);
        if (strcasecmp($l['visibility'] ?? 'Public', 'Public') === 0 && !$isAuth) {
            $filtered[] = $l;
        }
    }
    if (!empty($filtered)) $lessons = $filtered;
} elseif ($prefFilterTab === 'most_liked') {
    usort($lessons, fn($a, $b) => intval($b['likes_count'] ?? 0) <=> intval($a['likes_count'] ?? 0));
    $withLikes = array_filter($lessons, fn($l) => intval($l['likes_count'] ?? 0) > 0);
    if (!empty($withLikes)) $lessons = array_values($withLikes);
} elseif ($prefFilterTab === 'editor_picks') {
    $filtered = array_filter($lessons, fn($l) => !empty($l['editor_pick']) || strcasecmp($l['level'] ?? '', 'Advanced') === 0 || intval($l['chapters_count'] ?? 0) >= 15);
    if (!empty($filtered)) $lessons = array_values($filtered);
} elseif ($prefFilterTab === 'interacted') {
    $filtered = array_filter($lessons, fn($l) => !empty($l['liked_by_current']) || !empty($l['prompt_unlocked']) || (!empty($l['progress']) && intval($l['progress']) > 0));
    if (!empty($filtered)) $lessons = array_values($filtered);
} elseif ($prefFilterTab === 'my_likes') {
    $lessons = array_filter($lessons, fn($l) => !empty($l['liked_by_current']));
} elseif ($prefFilterTab === 'my_lessons') {
    $lessons = array_filter($lessons, function($l) use ($currentUsername, $currentEmail, $currentUserId) {
        $isAuth = false;
        if (!empty($l['author']) && strcasecmp($l['author'], $currentUsername) === 0) $isAuth = true;
        elseif (!empty($l['author_email']) && strcasecmp($l['author_email'], $currentEmail) === 0) $isAuth = true;
        elseif (!empty($l['user_id']) && (int)$l['user_id'] === $currentUserId && $currentUserId > 0) $isAuth = true;
        return $isAuth;
    });
} elseif ($prefFilterTab === 'my_syllabi') {
    $lessons = array_filter($lessons, fn($l) => !empty($l['is_syllabus']) || strcasecmp($l['type'] ?? '', 'syllabus') === 0);
}
$lessons = array_values($lessons);

$statsLessons = 0;
$statsChapters = 0;
$statsLearners = 0;
$statsUnlocks = 0;
$statsConversations = 0;
$statsMessages = 0;
$statsTokens = 0;
$statsReveals = 0;
try {
    $statsLessons = count($lessonsRaw);
    foreach ($lessonsRaw as $lr) {
        $statsChapters += intval($lr['chapters_count'] ?? 0);
    }
    $statsLearners = $db->users->countDocuments([]);
    $statsUnlocks = $db->ai_unlocked_lessons->countDocuments([]);
    $statsReveals = $db->ai_unlocked_prompts->countDocuments([]);
    
    $allChats = $db->ai_chat_history->find([])->toArray();
    $statsConversations = count($allChats);
    
    foreach ($allChats as $chatDoc) {
        $msgList = $chatDoc['messages'] ?? [];
        if (is_array($msgList) || (is_object($msgList) && method_exists($msgList, 'getArrayCopy'))) {
            $msgArray = is_array($msgList) ? $msgList : $msgList->getArrayCopy();
            $statsMessages += count($msgArray);
            foreach ($msgArray as $m) {
                if (!empty($m['usage']) && !empty($m['usage']['total_tokens'])) {
                    $statsTokens += (int)$m['usage']['total_tokens'];
                }
            }
        }
    }
} catch (Throwable $t) {}
?>

<div class="flex-grow-1 px-3 blur rounded-0 border-0 shadow-none">
    <div class="container learn-section p-3 py-4 py-xl-5">
        <div class="text-center mb-4 mt-2">
            <h2 class="mb-4 fw-bold text-center">Tell us what you'd like to Learn!</h2>
            
            <div class="mx-auto px-2" style="max-width: 800px;">
                <div class="mb-3 d-flex flex-wrap justify-content-center gap-2">
                    <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal cursor-pointer transition-all-lite text-info-hover topic-pill shadow-sm" data-prompt="Teach me n8n Workflow Automation from scratch. Cover webhooks, JSON data transformations, API nodes, and error handling.">n8n Workflow Automation</span>
                    <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal cursor-pointer transition-all-lite text-info-hover topic-pill shadow-sm" data-prompt="Teach me C++ Programming fundamentals. Cover memory management, pointers, OOP concepts, templates, and STL with practical exercises.">C++ Programming</span>
                    <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal cursor-pointer transition-all-lite text-info-hover topic-pill shadow-sm" data-prompt="Teach me low-level C programming. Cover fork, exec, pipes, signal handling, and POSIX system calls with real-world examples.">System Calls in C</span>
                    <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal cursor-pointer transition-all-lite text-info-hover topic-pill shadow-sm" data-prompt="Cover practical Linux Security Hardening. Teach SSH hardening, firewall configuration, SELinux/AppArmor basics, and privilege escalation prevention.">Linux Security Hardening</span>
                    <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal cursor-pointer transition-all-lite text-info-hover topic-pill shadow-sm" data-prompt="Teach me Web Engineering Fundamentals. Cover HTTP/2 vs HTTP/3, CORS, caching headers, WebSocket protocols, and performance optimization.">Web Engineering Fundamentals</span>
                </div>
                
                <div class="card bg-body-tertiary border border-secondary border-opacity-25 rounded-4 p-2 shadow-sm mb-2">
                    <textarea id="aiLessonPrompt" class="form-control bg-transparent border-0 text-body-emphasis shadow-none py-2 px-3 w-100" placeholder="Describe the technology or concept you want to master (e.g., 'Advanced Packet Capture & Analysis using tcpdump and Wireshark')..." rows="3" style="resize: none;" autocomplete="off"></textarea>
                    <input type="hidden" id="aiLessonLevel" value="<?= htmlspecialchars($prefLessonDiff) ?>">
                    <div class="d-flex justify-content-between align-items-center px-2 pb-1 pt-2">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle rounded-pill px-3 py-1 d-flex align-items-center gap-1 border-opacity-50 text-body-secondary" type="button" id="levelDropdownBtn" data-coreui-toggle="dropdown" aria-expanded="false">
                                <span id="selectedLevelText"><?= htmlspecialchars($prefLessonDiff) ?></span>
                            </button>
                            <ul class="dropdown-menu shadow border border-secondary border-opacity-25 rounded-3 py-2 px-1 mb-1" aria-labelledby="levelDropdownBtn" style="min-width: 135px;">
                                <li><a class="level-select-item dropdown-item rounded-2 py-1 px-3 d-flex align-items-center justify-content-between <?= $prefLessonDiff === 'Beginner' ? 'fw-semibold' : '' ?>" href="#" data-level="Beginner"><span>Beginner</span></a></li>
                                <li><a class="level-select-item dropdown-item rounded-2 py-1 px-3 d-flex align-items-center justify-content-between <?= $prefLessonDiff === 'Intermediate' ? 'fw-semibold' : '' ?>" href="#" data-level="Intermediate"><span>Intermediate</span></a></li>
                                <li><a class="level-select-item dropdown-item rounded-2 py-1 px-3 d-flex align-items-center justify-content-between <?= $prefLessonDiff === 'Advanced' ? 'fw-semibold' : '' ?>" href="#" data-level="Advanced"><span>Advanced</span></a></li>
                            </ul>
                        </div>
                        
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-secondary rounded-circle d-inline-flex align-items-center justify-content-center border-opacity-50 text-body-secondary" style="width: 36px; height: 36px; padding: 0;" title="Information">
                                <i class="bx bx-info-circle fs-5"></i>
                            </button>
                            <button id="btnGenerateLesson" type="button" class="btn btn-outline-secondary rounded-circle d-inline-flex align-items-center justify-content-center border-opacity-50 text-body-secondary" style="width: 36px; height: 36px; padding: 0;" title="Generate Lesson">
                                <i class="bx bx-send fs-5"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Bar -->
        <div class="mt-4 mb-4 d-flex flex-wrap align-items-center justify-content-center gap-2 px-2">
            <span class="badge rounded-pill bg-body-secondary border border-primary border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">📚</span> <span class="text-primary fw-bold"><?= number_format($statsLessons) ?></span> <span class="text-primary">Lessons</span>
            </span>
            <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">📖</span> <span class="text-info fw-bold"><?= number_format($statsChapters) ?></span> <span class="text-info">Chapters</span>
            </span>
            <span class="badge rounded-pill bg-body-secondary border border-success border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">👥</span> <span class="text-success fw-bold"><?= number_format($statsLearners) ?></span> <span class="text-success">Learners</span>
            </span>
            
            <span class="text-secondary opacity-50 mx-1 d-none d-md-inline">|</span>
            
            <span class="badge rounded-pill bg-body-secondary border border-warning border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">⚡</span> <span class="text-warning fw-bold"><?= number_format($statsUnlocks) ?></span> <span class="text-warning">Ask AI Unlocks</span>
            </span>
            <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">💬</span> <span class="text-info fw-bold"><?= number_format($statsConversations) ?></span> <span class="text-info">Conversations</span>
            </span>
            <span class="badge rounded-pill bg-body-secondary border border-primary border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">📨</span> <span class="text-primary fw-bold"><?= number_format($statsMessages) ?></span> <span class="text-primary">Messages</span>
            </span>
            <span class="badge rounded-pill bg-body-secondary border border-info border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">🎯</span> <span class="text-info fw-bold"><?= number_format($statsTokens) ?></span> <span class="text-info">Tokens</span>
            </span>
            <span class="badge rounded-pill bg-body-secondary border border-danger border-opacity-50 text-body px-3 py-1 fw-normal d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;">
                <span class="fs-6">💡</span> <span class="text-danger fw-bold"><?= number_format($statsReveals) ?></span> <span class="text-danger">Reveals</span>
            </span>
        </div>

        <div class="container-fluid mt-4">
            <div class="mb-4">
                <h4 class="fw-bold text-white mb-3">Learning Paths</h4>
                <?php
                $getBtnClass = fn($tab) => ($prefFilterTab === $tab ? 'active' : '');
                ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3 lesson-tabs-container">
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('all') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="all"><span class="fs-6">✨</span> For You</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('continue') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="continue"><span class="fs-6">📚</span> Continue</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('explore') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="explore"><span class="fs-6">🌏</span> Explore</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('most_liked') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="most_liked"><span class="fs-6">❤️‍🔥</span> Most Liked</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('editor_picks') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="editor_picks"><span class="fs-6">⭐</span> Editor Picks</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('interacted') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="interacted"><span class="fs-6">🔥</span> Most Interacted</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('my_likes') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="my_likes"><span class="fs-6">❤️</span> My Likes</button>
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('my_lessons') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="my_lessons"><span class="fs-6">👤</span> My Lessons</button>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 <?= $getBtnClass('my_syllabi') ?> d-inline-flex align-items-center gap-1 lesson-filter-btn" data-filter="my_syllabi"><span class="fs-6">👨‍🎓</span> My Syllabi Lessons</button>
                    <span class="text-secondary opacity-50 mx-1">|</span>
                    <div class="dropdown">
                        <button class="btn btn-xs btn-outline-secondary rounded-pill border-opacity-25 px-3 py-1 dropdown-toggle lesson-level-btn" id="lessonLevelDropdownBtn" type="button" data-coreui-toggle="dropdown" aria-expanded="false">
                            <?= htmlspecialchars($prefFilterLevel) ?>
                        </button>
                        <ul class="dropdown-menu blur shadow-sm border-secondary border-opacity-25" style="min-width: 8rem;">
                            <li><a class="dropdown-item small py-1 lesson-level-item" href="#" data-level="All Levels">All Levels</a></li>
                            <li><a class="dropdown-item small py-1 lesson-level-item" href="#" data-level="Beginner">Beginner</a></li>
                            <li><a class="dropdown-item small py-1 lesson-level-item" href="#" data-level="Intermediate">Intermediate</a></li>
                            <li><a class="dropdown-item small py-1 lesson-level-item" href="#" data-level="Advanced">Advanced</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div id="recent-lessons" data-page="1" data-tab="continue" data-lab="">
                <!-- Empty state container -->
                <div class="empty-state-container" style="display: none;"></div>
                <!-- Lessons grid container -->
                <div class="lessons-grid-container">
                    <div class="row gy-4 row-cols-1 row-cols-md-2 row-cols-lg-3 mb-5" id="masonry-area" data-masonry='{"percentPosition": true}'>
                        <?php 
                        $lessons = array_slice($lessons, 0, 8);
                        include __DIR__ . '/../../partials/learnAI/lessons_grid.php'; 
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AI Lesson Generation Progress Modal Card -->
<div class="modal fade" id="lessonGenModal" tabindex="-1" aria-hidden="true" data-coreui-backdrop="static" data-coreui-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-25 shadow-lg rounded-4 overflow-hidden bg-dark text-white">
            <div class="modal-header border-0 p-4 pb-0 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1">
                        <i class="bx bx-bot me-1"></i> Learn AI Generator
                    </span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="ai-icon-wrapper my-3 position-relative d-inline-flex align-items-center justify-content-center">
                    <div class="ai-ring"></div>
                    <i class="bx bx-chip text-primary fs-1 position-relative z-1"></i>
                </div>

                <h5 class="fw-bold mb-1 text-white" id="lessonGenTopicDisplay">Curating AI Learning Path...</h5>
                <p class="text-secondary small mb-4" id="lessonGenLevelDisplay">Professional Interactive Course</p>

                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <span class="text-secondary small" id="lessonGenStatus">Initializing AI engine...</span>
                    <span class="fw-bold text-primary small" id="lessonGenPercent">5%</span>
                </div>
                <div class="progress bg-secondary bg-opacity-25 rounded-pill mb-4" style="height: 6px;">
                    <div id="lessonGenProgress" class="progress-bar progress-bar-striped progress-bar-animated bg-primary rounded-pill" role="progressbar" style="width: 5%"></div>
                </div>

                <!-- Live Job Status Response Card -->
                <div class="card bg-black bg-opacity-50 border-secondary border-opacity-25 rounded-3 text-start p-3 font-monospace small">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary border-opacity-10">
                        <span class="text-secondary" style="font-size: 0.7rem;">JOB STATUS DETAILS</span>
                        <span id="lessonGenStatusBadge" class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25">RUNNING</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                        <span class="text-secondary">request_id:</span>
                        <span id="lessonGenRequestId" class="text-info text-truncate ms-2" style="max-width: 220px;">--</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                        <span class="text-secondary">status:</span>
                        <span id="lessonGenStatusValue" class="text-white">running</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                        <span class="text-secondary">message:</span>
                        <span id="lessonGenMessageValue" class="text-white text-truncate ms-2">running</span>
                    </div>
                    <div class="d-flex justify-content-between" style="font-size: 0.75rem;">
                        <span class="text-secondary">completed:</span>
                        <span id="lessonGenCompletedValue" class="text-white">false</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 justify-content-center">
                <button id="btnCancelLessonGen" type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-4" data-coreui-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="revealPromptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4" id="revealPromptModalContent">
            <!-- Dynamically loaded via HTMX/AJAX -->
        </div>
    </div>
</div>

<script>
    if (typeof window !== 'undefined' && window.LearnApp) {
        window.LearnApp.initDashboard();
    }
</script>
