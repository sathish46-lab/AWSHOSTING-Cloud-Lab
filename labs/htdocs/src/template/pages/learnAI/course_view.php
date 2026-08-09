<?php
$db = DatabaseConnection::getDefaultDatabase();
$lesson_id = $_GET['id'] ?? null;
$chapter_id = $_GET['chapter_id'] ?? null;

$lesson = $db->ai_lessons->findOne(['_id' => new MongoDB\BSON\ObjectId($lesson_id)]);
$user = Session::getUser();
$currentUsername = $user ? $user->getUsername() : '';
$currentEmail = $user ? $user->getEmail() : '';
$currentUserId = $user ? (int)$user->getUserId() : 0;

$isAuthor = false;
if ($lesson && !empty($lesson['author']) && strcasecmp($lesson['author'], $currentUsername) === 0) {
    $isAuthor = true;
} elseif ($lesson && !empty($lesson['author_email']) && strcasecmp($lesson['author_email'], $currentEmail) === 0) {
    $isAuthor = true;
} elseif ($lesson && !empty($lesson['user_id']) && (int)$lesson['user_id'] === $currentUserId && $currentUserId > 0) {
    $isAuthor = true;
}

if ($lesson && strcasecmp($lesson['visibility'] ?? 'Public', 'Private') === 0 && !$isAuthor) {
    header('Location: /learn');
    exit;
}

$isAiAssistUnlocked = $isAuthor;
if (!$isAuthor && $user && $lesson) {
    try {
        $unlockRecord = $db->ai_unlocked_lessons->findOne([
            'user_id' => $currentUserId,
            'lesson_id' => (string)$lesson['_id']
        ]);
        if ($unlockRecord) {
            $isAiAssistUnlocked = true;
        }
    } catch (Exception $e) {}
}

$userStats = $user ? \TomLabs\Labs\Quiz::getUserStats($currentEmail) : ['zeal' => 0, 'jolt' => 0];
$availableJolt = (int)($userStats['jolt'] ?? 0);

$likedByCurrent = false;
$likesList = $lesson['likes'] ?? [];
if (!is_array($likesList)) {
    $likesList = is_object($likesList) && method_exists($likesList, 'getArrayCopy') ? $likesList->getArrayCopy() : (array)$likesList;
}
if (in_array($currentUsername, $likesList) || ($currentUserId > 0 && in_array((string)$currentUserId, $likesList))) {
    $likedByCurrent = true;
} else {
    try {
        if (!empty($currentUsername) && $db->ai_lesson_likes->findOne(['lesson_id' => (string)$lesson['_id'], 'username' => $currentUsername])) {
            $likedByCurrent = true;
        }
    } catch (Throwable $t) {}
}


$chapters = $db->ai_chapters->find(['lesson_id' => new MongoDB\BSON\ObjectId($lesson_id)], ['sort' => ['order' => 1]])->toArray();

$chapter = null;
if ($chapter_id) {
    try {
        $chapter = $db->ai_chapters->findOne(['_id' => new MongoDB\BSON\ObjectId($chapter_id)]);
    } catch (Exception $e) {}
}

// Group chapters by module
$modules = [];
foreach ($chapters as $chap) {
    $modules[$chap['module_name']][] = $chap;
}

$userAvatar = Session::getAvatar();
$userAvatarStyle = Session::getAvatarStyle();
$aiAvatar = "/assets/logo/logo.png";

$userObj = Session::getUser();
$userUiPrefs = $userObj ? ($userObj->getUiPreferences() ?? []) : [];
$dbSizesRaw = $userUiPrefs['learnAiThreePanelSizes'] ?? null;
$dbSizesArr = is_string($dbSizesRaw) ? json_decode($dbSizesRaw, true) : $dbSizesRaw;
?>
<link id="hljs-theme" rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.2/marked.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
(function() {
    const updateHljsTheme = () => {
        const isDark = document.documentElement.getAttribute('data-coreui-theme') === 'dark';
        const link = document.getElementById('hljs-theme');
        if (!link) return;
        const targetHref = isDark 
            ? 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css' 
            : 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css';
        if (link.getAttribute('href') !== targetHref) {
            link.setAttribute('href', targetHref);
        }
    };
    updateHljsTheme();
    new MutationObserver(updateHljsTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-coreui-theme'] });
})();
</script>
<?php if (is_array($dbSizesArr) && count($dbSizesArr) === 3): ?>
<style>
:root {
    --outlineSidebar-saved-width: <?= round($dbSizesArr[0], 2) ?>%;
    --courseSidebar-saved-width: <?= round($dbSizesArr[0], 2) ?>%;
    --paneAI-saved-width: <?= round($dbSizesArr[2], 2) ?>%;
}
</style>
<?php endif; ?>

<script>
// Anti-Flicker: Apply saved pane widths and exact viewport height immediately before paint
(function() {
    const prefs = (window.TOM_CONFIG && window.TOM_CONFIG.ui_preferences) || {};

    let threePanelSizes = localStorage.getItem('learnAiThreePanelSizes') || sessionStorage.getItem('learnAiThreePanelSizes') || prefs['learnAiThreePanelSizes'];
    if (threePanelSizes && typeof threePanelSizes !== 'string') threePanelSizes = JSON.stringify(threePanelSizes);
    if (threePanelSizes) {
        localStorage.setItem('learnAiThreePanelSizes', threePanelSizes);
        sessionStorage.setItem('learnAiThreePanelSizes', threePanelSizes);
        try {
            const arr = JSON.parse(threePanelSizes);
            if (Array.isArray(arr) && arr.length === 3) {
                document.documentElement.style.setProperty('--courseSidebar-saved-width', arr[0] + '%');
                document.documentElement.style.setProperty('--outlineSidebar-saved-width', arr[0] + '%');
                document.documentElement.style.setProperty('--paneAI-saved-width', arr[2] + '%');

                // SNA normalizeSizes: ensure sum = 100
                var sum = arr[0] + arr[1] + arr[2];
                if (sizes[0] + sizes[2] > 75) {
                    var excess = (sizes[0] + sizes[2]) - 75;
                    sizes[2] = Math.max(18, sizes[2] - excess);
                }

                const isExp = (sizes[0] / 100) * window.innerWidth > 175;
                const st = document.createElement('style');
                st.id = 'learn-panel-zero-flicker';
                st.innerHTML = `
                    #learn-panel-1 {
                        width: ${sizes[0]}% !important;
                        flex-basis: ${sizes[0]}% !important;
                        flex-grow: 0 !important;
                        flex-shrink: 0 !important;
                    }
                    #learn-panel-2 {
                        width: 0 !important;
                        flex: 1 1 0% !important;
                        min-width: 300px !important;
                    }
                    #learn-panel-3 {
                        width: ${sizes[2]}% !important;
                        flex-basis: ${sizes[2]}% !important;
                        flex-grow: 0 !important;
                        flex-shrink: 0 !important;
                    }
                    ${isExp ? `
                    #learn-panel-1 .outline-compact { display: none !important; }
                    #learn-panel-1 .outline-full { display: flex !important; }
                    ` : `
                    #learn-panel-1 .outline-compact { display: flex !important; }
                    #learn-panel-1 .outline-full { display: none !important; }
                    `}
                `;
                document.head.appendChild(st);
            }
        } catch(e) {}
    }
})();
</script>

<div class="learn-app-wrapper split-panel-view d-flex flex-column overflow-hidden bg-transparent">
    <?php include __DIR__ . '/../../partials/learnAI/top_bar.php'; ?>

    <div class="split-panel-body d-flex flex-row overflow-hidden flex-grow-1 position-relative p-2 gap-0" style="min-height: 0;">
        <?php
        // SNA defaults: [22, 53, 25] for three-panel
        $isPanel1Expanded = false;
        if (is_array($dbSizesArr) && count($dbSizesArr) === 3 && $dbSizesArr[0] > 10) {
            $isPanel1Expanded = true;
        }
        $panel1State = $isPanel1Expanded ? 'expanded' : 'collapsed';
        $panel1Class = $isPanel1Expanded ? '' : 'auto-compact';

        // SNA normalizeSizes: use raw percentages, ensure sum = 100
        $p1Pct = (is_array($dbSizesArr) && count($dbSizesArr) === 3 && $dbSizesArr[0] > 3) ? round($dbSizesArr[0], 2) : 22;
        $p3Pct = (is_array($dbSizesArr) && count($dbSizesArr) === 3 && $dbSizesArr[2] > 5) ? round($dbSizesArr[2], 2) : 25;
        $p2Pct = 100 - $p1Pct - $p3Pct;
        if ($p2Pct < 20) { $p1Pct = 22; $p3Pct = 25; $p2Pct = 53; }
        $p1W_php = $isPanel1Expanded ? "{$p1Pct}%" : "70px";
?>
        <!-- Panel 1: Collapsible Sidebar -->
        <div id="learn-panel-1" class="split-panel h-100 <?= $panel1Class ?>" style="width: <?= $p1W_php ?>; flex-basis: <?= $p1W_php ?>; flex-grow: 0; flex-shrink: 0;" data-state="<?= $panel1State ?>">
            <div class="card h-100 border-secondary border-opacity-10 rounded-4 shadow-sm blur d-flex flex-column overflow-hidden">
                <div class="card-header fs-6 d-flex justify-content-between align-items-center py-2 px-3">
                    <strong class="text-truncate" title="<?= htmlspecialchars($lesson['title']) ?>"><?= htmlspecialchars($lesson['title']) ?></strong>
                    <button class="btn btn-link p-0 text-secondary like-lesson-btn" data-lesson-id="<?= $lesson['_id'] ?>" title="Like this lesson">
                        <i class="bx <?= !empty($likedByCurrent) ? 'bxs-heart text-danger' : 'bx-heart' ?> fs-5"></i>
                    </button>
                </div>
                <div class="card-body p-0 overflow-hidden d-flex flex-column">
                    <!-- Top section: pinned controls (does not scroll) -->
                    <div class="d-flex justify-content-center align-items-center p-2 lesson-controls gap-2 border-bottom border-secondary border-opacity-10" style="flex-shrink: 0;">
                        <!-- Like button (shown in compact mode via sna.css) -->
                        <button class="btn btn-link p-0 text-secondary like-lesson-btn like-lesson-btn-compact" data-lesson-id="<?= $lesson['_id'] ?>" data-tooltip="Like Lesson">
                            <i class="bx <?= !empty($likedByCurrent) ? 'bxs-heart text-danger' : 'bx-heart' ?> fs-5"></i>
                        </button>
                        <!-- Circular progress indicator -->
                        <div class="d-flex align-items-center progress-section gap-2">
                            <div class="circular-progress d-flex align-items-center justify-content-center position-relative" style="width: 36px; height: 36px;">
                                <svg width="36" height="36" viewBox="0 0 45 45">
                                    <circle cx="22.5" cy="22.5" r="18" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="3"></circle>
                                    <circle cx="22.5" cy="22.5" r="18" fill="none" stroke="var(--cui-primary)" stroke-width="3" stroke-dasharray="113" stroke-dashoffset="<?= 113 - (113 * ($lesson['progress'] ?? 35) / 100) ?>" stroke-linecap="round" transform="rotate(-90 22.5 22.5)"></circle>
                                </svg>
                                <span class="position-absolute small fw-bold" style="font-size: 0.6rem;"><?= $lesson['progress'] ?? 35 ?>%</span>
                            </div>
                            <span class="small fw-bold text-light progress-label">Completed</span>
                        </div>
                        <!-- View switcher buttons -->
                        <div class="view-buttons d-flex align-items-center">
                            <a href="/learn/lesson/<?= $lesson['_id'] ?>" class="btn btn-sm rounded-pill d-flex align-items-center learn-switch-btn <?= !isset($_GET['view']) || $_GET['view'] !== 'map' ? 'active' : '' ?>" data-tooltip="Outline">
                                <i class="bx bx-list-ul icon"></i> <span class="btn-label ms-1">Outline</span>
                            </a>
                            <a href="/learn/lesson/<?= $lesson['_id'] ?>/map" class="btn btn-sm rounded-pill d-flex align-items-center learn-switch-btn <?= isset($_GET['view']) && $_GET['view'] === 'map' ? 'active' : '' ?>" data-tooltip="Map">
                                <i class="bx bx-share-alt icon"></i> <span class="btn-label ms-1">Map</span>
                            </a>
                        </div>
                    </div>

                    <!-- Chapters list (scrollable) -->
                    <div class="lesson-chapters-section flex-grow-1 overflow-y-auto hide-scrollbar">
                        <div class="accordion accordion-flush" id="accordionFlushExample">
                            <?php $mod_idx = 1; foreach ($modules as $mod_name => $mod_chapters): ?>
                                <?php
                                $is_mod_active = false;
                                if ($chapter_id) {
                                    foreach ($mod_chapters as $c) {
                                        if ((string)$c['_id'] === $chapter_id) {
                                            $is_mod_active = true;
                                            break;
                                        }
                                    }
                                }
                                $is_expanded = $is_mod_active || (!$chapter_id && $mod_idx === 1);
                                ?>
                                <div class="accordion-item bg-transparent border-bottom border-secondary border-opacity-10">
                                    <h2 class="accordion-header m-0">
                                        <button class="accordion-button bg-transparent text-white py-2 px-3 d-flex align-items-center shadow-none <?= !$is_expanded ? 'collapsed' : '' ?>" type="button" data-coreui-toggle="collapse" data-coreui-target="#flush-collapse<?= $mod_idx ?>" aria-expanded="<?= $is_expanded ? 'true' : 'false' ?>" aria-controls="flush-collapse<?= $mod_idx ?>">
                                            <?php $clean_mod_name = preg_replace('/^\d+[\.\)]\s*/', '', $mod_name); ?>
                                            <span class="accordion-num badge rounded-circle me-2 d-flex align-items-center justify-content-center" style="width: 24px; height: 24px;" data-tooltip="<?= htmlspecialchars($clean_mod_name) ?>"><?= $mod_idx ?></span>
                                            <span class="module-title-text"><?= htmlspecialchars($clean_mod_name) ?></span>
                                        </button>
                                    </h2>
                                    <div id="flush-collapse<?= $mod_idx ?>" class="accordion-collapse collapse <?= $is_expanded ? 'show' : '' ?>" data-coreui-parent="#accordionFlushExample">
                                        <div class="accordion-body p-1">
                                            <?php $chap_local_idx = 1; foreach ($mod_chapters as $chap): ?>
                                                <?php 
                                                $clean_chap_title = preg_replace('/^\d+[\.\)]\s*/', '', $chap['title']); 
                                                $is_active = ($chapter_id && (string)$chap['_id'] === $chapter_id);
                                                $btn_class = $is_active ? 'active bg-primary bg-opacity-25 text-white fw-bold' : 'text-secondary';
                                                $text_class = $is_active ? 'text-white' : 'text-secondary';
                                                $icon_class = $is_active ? 'bxs-check-circle text-primary' : 'bx-check-circle opacity-50';
                                                ?>
                                                <a href="/learn/lesson/<?= $lesson['_id'] ?>/chapter/<?= $chap['_id'] ?>" hx-boost="false" class="btn btn-sm d-flex align-items-center justify-content-between w-100 text-start py-2 px-2 rounded mb-1 learn-accordion-btn <?= $btn_class ?>" data-tooltip="<?= htmlspecialchars($clean_chap_title) ?>" data-chapter-id="<?= $chap['_id'] ?>" data-lesson-id="<?= $lesson['_id'] ?>" data-chapter-title="<?= htmlspecialchars($clean_chap_title) ?>" data-module-name="<?= htmlspecialchars($clean_mod_name) ?>">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="chapter-num-btn badge rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px;"><?= $chap_local_idx++ ?></span>
                                                        <h6 class="m-0 small chapter-title-text <?= $text_class ?>"><?= htmlspecialchars($clean_chap_title) ?></h6>
                                                    </div>
                                                    <i class="bx <?= $icon_class ?> icon"></i>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php $mod_idx++; endforeach; ?>
                        </div>

                        <?php
                        $labsList = Session::get('labs_list', []);
                        $currentLab = null;
                        if (!empty($labsList)) {
                            foreach ($labsList as $lItem) {
                                if (($lItem['status'] ?? '') === 'running' && (stripos($lItem['name'] ?? '', 'Essential') !== false || ($lItem['id'] ?? '') === 'essentials')) {
                                    $currentLab = $lItem;
                                    break;
                                }
                            }
                            if (!$currentLab) {
                                foreach ($labsList as $lItem) {
                                    if (($lItem['status'] ?? '') === 'running') {
                                        $currentLab = $lItem;
                                        break;
                                    }
                                }
                            }
                            if (!$currentLab) $currentLab = $labsList[0];
                        }
                        if (!$currentLab) {
                            $currentLab = [
                                'name' => 'Essentials',
                                'ip' => '172.30.0.28',
                                'status' => 'running',
                                'is_public' => 'public',
                                'hash' => 'essentials-lab-id',
                                'icon' => 'tux',
                                'badges' => ['beta']
                            ];
                        }
                        $labName = $currentLab['name'] ?? 'Essentials';
                        $labIp = $currentLab['ip'] ?? '172.30.0.28';
                        $labStatus = $currentLab['status'] ?? 'running';
                        $labPublic = $currentLab['is_public'] ?? 'public';
                        $labHash = $currentLab['hash'] ?? 'essentials-lab-id';
                        $labBadges = $currentLab['badges'] ?? ['beta'];

                        $iconMap = [
                            'tux'    => 'bxl-tux',
                            'docker' => 'bxl-docker',
                            'git-repo-forked' => 'bx-git-repo-forked'
                        ];
                        $bxClass = $iconMap[$currentLab['icon'] ?? ''] ?? 'bxl-tux';
                        ?>

                        <!-- Bottom Lab Section scrolling naturally with chapters -->
                        <div class="lesson-lab-section mt-auto pt-3 border-top border-secondary border-opacity-10 w-100 px-2 pb-3">
                            <!-- EXPANDED FULL LAB CARD (Screenshot 1) -->
                            <div class="outline-full flex-column w-100">
                                <h6 class="small fw-bold text-white mb-2 px-1">Required Lab</h6>
                                <div class="lab-card-box rounded-4 overflow-hidden w-100 position-relative shadow-sm" style="background: rgba(45, 25, 30, 0.45); border: 1px solid rgba(255, 255, 255, 0.1);">
                                    <i class="bx <?= $bxClass ?> position-absolute end-0 top-50 translate-middle-y text-white opacity-10" style="font-size: 5.5rem; pointer-events: none;"></i>
                                    <div class="p-3 position-relative">
                                        <div class="d-flex align-items-center mb-1">
                                            <span class="fw-bold text-white fs-6"><?= htmlspecialchars($labName) ?> Lab</span>
                                            <i class="bx bx-info-circle small text-secondary ms-1"></i>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="font-monospace text-white fs-6"><?= htmlspecialchars($labIp) ?></span>
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" class="btn btn-link p-1 text-secondary hover-text-white" data-copy="<?= htmlspecialchars($labIp) ?>" title="Copy IP"><i class="bx bx-copy fs-5"></i></button>
                                                <a href="/labs/dashboard/<?= htmlspecialchars($labHash) ?>" class="btn btn-link p-1 text-secondary hover-text-white" title="Open Lab"><i class="bx bx-share fs-5"></i></a>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-center gap-1">
                                            <?php foreach ($labBadges as $badge): ?>
                                                <span class="badge rounded-pill px-2 py-1 small fw-bold" style="background-color: #6366f1 !important; color: #fff;"><?= htmlspecialchars($badge) ?></span>
                                            <?php endforeach; ?>
                                            <span class="badge rounded-pill bg-warning text-dark px-2 py-1 small fw-bold"><?= htmlspecialchars($labPublic) ?></span>
                                            <span class="badge rounded-pill bg-success text-white px-2 py-1 small fw-bold"><?= htmlspecialchars($labStatus) ?></span>
                                        </div>
                                    </div>
                                    <!-- Action Buttons Bar matching Screenshot 1 -->
                                    <div class="d-flex border-top border-secondary border-opacity-25" style="background: rgba(15, 23, 42, 0.85);">
                                        <button type="button" class="btn btn-link flex-fill py-2 text-white border-end border-secondary border-opacity-25 d-flex align-items-center justify-content-center" style="background-color: #6366f1;" onclick="openCodeModal('<?= $labHash ?>', '<?= addslashes($labName) ?> Lab', '<?= $labStatus ?>')" title="Terminal"><i class="bx bx-terminal fs-5"></i></button>
                                        <a href="/labs/dashboard/<?= htmlspecialchars($labHash) ?>" class="btn btn-link flex-fill py-2 text-white border-end border-secondary border-opacity-25 d-flex align-items-center justify-content-center" style="background-color: #10b981;" title="Ports & Services"><i class="bx bx-grid-alt fs-5"></i></a>
                                        <button type="button" class="btn btn-link flex-fill py-2 text-white d-flex align-items-center justify-content-center" style="background-color: #06b6d4;" onclick="openConnectionModal('<?= $labHash ?>', '<?= addslashes($labName) ?> Lab', '<?= $labStatus ?>')" title="Lab Info"><i class="bx bx-info-circle fs-5"></i></button>
                                    </div>
                                </div>
                            </div>

                            <!-- COMPACT LAB ICONS ONLY (Screenshot 2) -->
                            <div class="outline-compact flex-column align-items-center gap-2 w-100">
                                <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px; background: rgba(234, 88, 12, 0.2); border: 2px solid #ea580c;" data-tooltip="<?= htmlspecialchars($labName) ?> Lab (<?= htmlspecialchars($labIp) ?>)">
                                    <i class="bx <?= $bxClass ?> fs-4 text-white"></i>
                                </div>
                                <div class="d-flex flex-column rounded-pill p-1 gap-1 border border-secondary border-opacity-25 shadow-sm" style="background: rgba(15, 23, 42, 0.85);">
                                    <button type="button" class="btn btn-sm rounded-pill d-flex align-items-center justify-content-center p-0 text-white" style="background-color: #6366f1; width: 34px; height: 26px;" onclick="openCodeModal('<?= $labHash ?>', '<?= addslashes($labName) ?> Lab', '<?= $labStatus ?>')" data-tooltip="Terminal"><i class="bx bx-terminal fs-6"></i></button>
                                    <a href="/labs/dashboard/<?= htmlspecialchars($labHash) ?>" class="btn btn-sm rounded-pill d-flex align-items-center justify-content-center p-0 text-white" style="background-color: #10b981; width: 34px; height: 26px;" data-tooltip="Ports & Services"><i class="bx bx-grid-alt fs-6"></i></a>
                                    <button type="button" class="btn btn-sm rounded-pill d-flex align-items-center justify-content-center p-0 text-white" style="background-color: #06b6d4; width: 34px; height: 26px;" onclick="openConnectionModal('<?= $labHash ?>', '<?= addslashes($labName) ?> Lab', '<?= $labStatus ?>')" data-tooltip="Lab Info"><i class="bx bx-info-circle fs-6"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resizer 1 (Gutter) -->
        <div class="gutter gutter-horizontal pane-resizer h-100" style="width: 4px;" data-target="learn-panel-1"></div>

        <!-- Panel 2: Center Course Overview Area -->
        <div id="learn-panel-2" class="split-panel h-100 overflow-hidden" style="width: 0 !important; flex: 1 1 0% !important; min-width: 300px !important;">
            <div class="card h-100 border-secondary border-opacity-10 rounded-4 shadow-sm blur d-flex flex-column overflow-hidden">
                <?php
                if ($chapter_id) {
                    include __DIR__ . '/../../partials/learnAI/chapter_content.php';
                } elseif (isset($_GET['view']) && $_GET['view'] === 'map') {
                    include __DIR__ . '/../../partials/learnAI/course_map.php';
                } else {
                    include __DIR__ . '/../../partials/learnAI/course_overview.php';
                }
                ?>
            </div>
        </div>

        <!-- Resizer 2 (Gutter) - Hidden on Course Overview until chapter continue -->
        <div class="gutter gutter-horizontal h-100 <?= $chapter_id ? '' : 'd-none' ?>" style="width: 4px;" data-target="learn-panel-3" data-direction="right"></div>

        <!-- Panel 3: Right AI Assistant - Hidden on Course Overview until chapter continue -->
        <div id="learn-panel-3" class="split-panel pane-ai flex-column h-100 <?= $chapter_id ? 'd-flex' : 'd-none' ?>" style="width: <?= $p3Pct ?>%; flex-basis: <?= $p3Pct ?>%; flex-grow: 0; flex-shrink: 0;" data-state="closed">
            <div class="card h-100 border-secondary border-opacity-10 rounded-4 shadow-sm blur d-flex flex-column overflow-hidden">
                <div class="card-header bg-dark bg-opacity-25 border-secondary border-opacity-10 py-2 px-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bx bx-bot text-primary"></i>
                        <h6 class="fw-bold m-0 small ls-1 text-uppercase">AI Assist</h6>
                        <span class="badge bg-warning text-dark" style="font-size: 0.55rem; padding: 0.2em 0.5em;">PRO</span>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                         <select id="aiModelSelect" class="form-select form-select-sm bg-transparent text-secondary border-0 p-0 me-1" style="font-size: 0.7rem; width: auto; cursor: pointer;">
                             <option value="gemini">Gemini</option>
                             <option value="lm_studio">LM Studio</option>
                         </select>
                         <i class="bx bx-refresh text-secondary cursor-pointer fs-6 opacity-50 hover-opacity-100" title="Clear Chat" onclick="if(confirm('Clear?')) document.getElementById('aiChatHistory').innerHTML=''"></i>
                    </div>
                </div>
                <div class="card-body p-0 d-flex flex-column flex-grow-1 overflow-hidden position-relative">
                    <input type="hidden" id="currentLessonId" value="<?= $lesson['_id'] ?? '' ?>">
                    <input type="hidden" id="currentChapterId" value="<?= $chapter['_id'] ?? '' ?>">
                    <input type="hidden" id="userAvatarUrl" value="<?= $userAvatar ?>">
                    <input type="hidden" id="userAvatarStyle" value="<?= $userAvatarStyle ?>">
                    <input type="hidden" id="aiAvatarUrl" value="<?= $aiAvatar ?>">
                    <input type="hidden" id="isAiAssistUnlocked" value="<?= $isAiAssistUnlocked ? '1' : '0' ?>">

                    <?php if (!$isAiAssistUnlocked): ?>
                    <!-- AI Assist Locked Screen matching user design -->
                    <div id="aiAssistLockedScreen" class="d-flex flex-column align-items-center justify-content-center h-100 p-4 text-center my-auto">
                        <div class="mb-3 d-flex align-items-center justify-content-center border border-secondary border-opacity-25 rounded-circle" style="width: 76px; height: 76px; background: rgba(255, 255, 255, 0.03); box-shadow: 0 8px 32px rgba(0,0,0,0.3);">
                            <i class="bx bx-lock-alt fs-1 text-secondary"></i>
                        </div>
                        <h5 class="fw-bold text-white mb-2">AI Assist is Locked</h5>
                        <p class="text-secondary small mb-4" style="max-width: 280px; line-height: 1.5; font-size: 0.82rem;">
                            Unlock AI Assist to get help with this lesson's chapters. Ask questions, get explanations, and learn faster with AI.
                        </p>
                        <div class="mb-3">
                            <span class="badge rounded-pill bg-warning text-dark px-3 py-2 fw-bold d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.85rem;">
                                25 <i class="bx bxs-zap fs-6"></i> Jolt
                            </span>
                        </div>
                        <button id="unlockAiAssistBtn" class="btn rounded-pill px-4 py-2 fw-medium shadow-sm d-inline-flex align-items-center gap-2 mb-3 text-white hvr-grow" style="background: linear-gradient(135deg, #8b5cf6, #6366f1); border: none; font-size: 0.9rem;" data-coreui-toggle="modal" data-coreui-target="#unlockAiAssistModal">
                            <i class="bx bx-lock-open fs-5"></i> Unlock AI Assist
                        </button>
                        <p class="text-secondary small m-0 d-flex align-items-center gap-1" style="font-size: 0.75rem;">
                            <span class="text-warning fs-6">💡</span> Lesson owners get free access
                        </p>
                        <div class="mt-2 text-secondary small" style="font-size: 0.75rem;">
                            Your Fuel: <strong class="text-warning"><?= number_format($availableJolt) ?> <i class="bx bxs-zap"></i> Jolt</strong>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="aiChatHistory" class="chat-history flex-grow-1 overflow-auto hide-scrollbar p-3 flex-column gap-3 <?= $isAiAssistUnlocked ? 'd-flex' : 'd-none' ?>">
                        <div class="text-center py-4 my-auto text-secondary small d-flex flex-column align-items-center gap-2"><i class="bx bx-loader-circle bx-spin fs-4 text-primary"></i><span>Loading conversation...</span></div>
                    </div>

                    <!-- Floating Scroll to Bottom / Auto-Scroll Wait Button -->
                    <button id="aiChatScrollToBottom" class="d-none" type="button" title="Scroll to bottom">
                        <i class="bx bx-down-arrow-alt fs-4"></i>
                    </button>

                    <!-- Bottom Input Bar (from sna.css) -->
                    <div id="aiChatInputBar" class="input-container p-2 pb-3 <?= $isAiAssistUnlocked ? '' : 'd-none' ?>">
                        <div class="unified-input-box simple-blur">
                            <textarea class="user-text-input" type="text" id="aiChatInput" placeholder="Ask AI ✨" rows="1" style="height: auto; resize: none;"></textarea>
                            
                            <div class="token-ribbon">
                                <div class="token-metrics" id="token-metrics">
                                    <div class="context-progress" data-coreui-toggle="tooltip" data-coreui-original-title="Context window">
                                        <svg class="progress-ring" width="24" height="24">
                                            <circle class="progress-ring-bg" cx="12" cy="12" r="10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="2"></circle>
                                            <circle id="aiContextProgressRing" class="progress-ring-circle" cx="12" cy="12" r="10" fill="none" stroke="#0d6efd" stroke-width="2" stroke-dasharray="63" stroke-dashoffset="63" transform="rotate(-90 12 12)" style="transition: stroke-dashoffset 0.3s;"></circle>
                                        </svg>
                                        <span id="aiCachePercBadge" class="progress-percentage">0%</span>
                                    </div>
                                    <span id="aiTokensDisplay" class="context-value" data-coreui-toggle="tooltip" data-coreui-original-title="Context: 0 / 1M tokens">0/1M</span>
                                    <span class="token-separator">|</span>
                                    <span class="token-metric" data-coreui-toggle="tooltip" data-coreui-original-title="Output tokens">
                                        ↑ <span id="aiOutputTokens" class="output-tokens">0</span>
                                    </span>
                                    <span id="aiCachedTokensWrap" class="token-metric cached-metric text-success" data-coreui-toggle="tooltip" data-coreui-original-title="Cached tokens">
                                        💾 <span id="aiCachedTokens" class="cached-tokens">0</span>
                                    </span>
                                    <button class="token-history-btn" id="token-history-btn" data-coreui-toggle="tooltip" aria-label="Token history" data-coreui-original-title="Token history">
                                        <svg class="icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-chart-line"></use></svg>
                                    </button>
                                </div>
                                <button id="aiChatSend" class="send-button">
                                    <svg class="nav-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-paper-plane"></use></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Token History Modal -->
<div class="modal fade" id="tokenHistoryModal" tabindex="-1" aria-labelledby="tokenHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg" style="max-width: 820px;">
        <div class="modal-content shadow-lg rounded-4 border" id="tokenHistoryModalContent">
            <div class="p-5 text-center">
                <i class="bx bx-loader-circle bx-spin fs-2 text-primary"></i>
                <p class="mt-2 text-secondary small">Loading token history...</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="unlockAiAssistModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h5 class="modal-title fw-bold">Unlock AI Assist</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pt-2">
                <div class="mb-3">
                    🏷 You are about to unlock AI Assist for this lesson
                </div>
                
                <hr class="text-secondary opacity-25">
                
                <div class="row text-center g-2 my-3">
                    <div class="col-4">
                        <div class="small fw-bold mb-1">AI Assist Cost</div>
                        <div>25 ⚡️</div>
                    </div>
                    <div class="col-4">
                        <div class="small fw-bold mb-1">Available Jolt</div>
                        <div><?= number_format($availableJolt) ?> ⚡️</div>
                    </div>
                    <div class="col-4">
                        <div class="small fw-bold mb-1">Jolt Remaining</div>
                        <div><?= number_format($availableJolt - 25) ?> ⚡️</div>
                    </div>
                </div>
                
                <hr class="text-secondary opacity-25">
                
                <div class="mb-4 mt-3">
                    <h6 class="mb-2">Important Notes</h6>
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-2 small" style="color: #d97706;">
                        <li>• This will unlock AI Assist for all chapters in this lesson</li>
                        <li>• 25 ⚡️ Jolts will be deducted and this action cannot be undone</li>
                        <li>• Lesson owners get free access without spending Jolt</li>
                    </ul>
                </div>
                
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-coreui-dismiss="modal">Cancel</button>
                    <button type="button" class="btn rounded-pill px-4 text-white" style="background: linear-gradient(135deg, #8b5cf6, #6366f1); border: none;" onclick="unlockAiAssistAction('<?= $lesson['_id'] ?>')" data-coreui-dismiss="modal">Unlock AI Assist</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reveal Prompt Modal Container (Dynamically loaded via HTMX/AJAX) -->
<div class="modal fade" id="revealPromptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4" id="revealPromptModalContent">
            <!-- Content loaded dynamically from reveal_prompt.php -->
        </div>
    </div>
</div>

<!-- Code + Connection modals (API-driven, shared partial) -->
<?php include __DIR__ . '/../labs/partials/lab_modals.php'; ?>

<style>
.learn-app-wrapper { background: transparent; }
.transition-all { transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); }

.pane-sidebar, .pane-main { 
    min-width: 0 !important; 
    flex-shrink: 0;
}
.pane-resizer {
    width: 16px;
    margin: 0 -8px;
    background: transparent;
    cursor: col-resize;
    z-index: 1000;
    transition: background 0.2s;
    position: relative;
    flex-shrink: 0;
}
.pane-resizer:hover, .pane-resizer.is-dragging {
    background: rgba(var(--cui-primary-rgb, 50, 31, 219), 0.1);
}
.pane-resizer::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 4px;
    height: 48px;
    background: rgba(255,255,255,0.08);
    border-radius: 10px;
    transition: all 0.2s;
}
.pane-resizer:hover::after, .pane-resizer.is-dragging::after {
    background: var(--cui-primary, #321fdb);
}


/* Hidden Scrollbar */
.hide-scrollbar { scrollbar-width: none; -ms-overflow-style: none; }
.hide-scrollbar::-webkit-scrollbar { display: none; }

/* Guaranteed Light Mode Syntax Colors (GitHub Light) */
html[data-coreui-theme="light"] pre code.hljs { color: #24292e !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-doctag,
html[data-coreui-theme="light"] pre code.hljs .hljs-keyword,
html[data-coreui-theme="light"] pre code.hljs .hljs-meta .hljs-keyword,
html[data-coreui-theme="light"] pre code.hljs .hljs-template-tag,
html[data-coreui-theme="light"] pre code.hljs .hljs-template-variable,
html[data-coreui-theme="light"] pre code.hljs .hljs-type,
html[data-coreui-theme="light"] pre code.hljs .hljs-variable.language_ { color: #d73a49 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-title,
html[data-coreui-theme="light"] pre code.hljs .hljs-title.class_,
html[data-coreui-theme="light"] pre code.hljs .hljs-title.class_.inherited__,
html[data-coreui-theme="light"] pre code.hljs .hljs-title.function_ { color: #6f42c1 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-attr,
html[data-coreui-theme="light"] pre code.hljs .hljs-attribute,
html[data-coreui-theme="light"] pre code.hljs .hljs-literal,
html[data-coreui-theme="light"] pre code.hljs .hljs-meta,
html[data-coreui-theme="light"] pre code.hljs .hljs-number,
html[data-coreui-theme="light"] pre code.hljs .hljs-operator,
html[data-coreui-theme="light"] pre code.hljs .hljs-selector-attr,
html[data-coreui-theme="light"] pre code.hljs .hljs-selector-class,
html[data-coreui-theme="light"] pre code.hljs .hljs-selector-id,
html[data-coreui-theme="light"] pre code.hljs .hljs-variable { color: #005cc5 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-meta .hljs-string,
html[data-coreui-theme="light"] pre code.hljs .hljs-regexp,
html[data-coreui-theme="light"] pre code.hljs .hljs-string { color: #032f62 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-built_in,
html[data-coreui-theme="light"] pre code.hljs .hljs-symbol { color: #e36209 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-code,
html[data-coreui-theme="light"] pre code.hljs .hljs-comment,
html[data-coreui-theme="light"] pre code.hljs .hljs-formula { color: #6a737d !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-name,
html[data-coreui-theme="light"] pre code.hljs .hljs-quote,
html[data-coreui-theme="light"] pre code.hljs .hljs-selector-pseudo,
html[data-coreui-theme="light"] pre code.hljs .hljs-selector-tag { color: #22863a !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-subst { color: #24292e !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-section { color: #005cc5 !important; font-weight: 700 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-bullet { color: #735c0f !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-emphasis { color: #24292e !important; font-style: italic !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-strong { color: #24292e !important; font-weight: 700 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-addition { color: #22863a !important; background-color: #f0fff4 !important; }
html[data-coreui-theme="light"] pre code.hljs .hljs-deletion { color: #b31d28 !important; background-color: #ffeef0 !important; }
.hide-scrollbar::-webkit-scrollbar { display: none; }

/* Responsive Adjustments */
@media (max-width: 991.98px) {
    .learn-app-wrapper { height: auto !important; min-height: 100vh; overflow: auto !important; }
    body { overflow: auto !important; }
    .flex-row { flex-direction: column !important; }
    .pane-sidebar, .pane-main { width: 100% !important; height: auto !important; min-width: 0 !important; }
    .pane-resizer { display: none !important; }
    .pane-main { min-height: 75vh !important; }
}
</style>
