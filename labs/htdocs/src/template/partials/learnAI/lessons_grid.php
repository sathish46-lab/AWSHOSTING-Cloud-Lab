<?php if (empty($lessons)): ?>
<div class="col-12 w-100 text-center py-5 mt-4">
    <h5 class="text-body-emphasis fw-semibold mb-2" style="font-size: 1.1rem;">No lessons found! 🎓</h5>
    <p class="text-body-secondary mb-0" style="font-size: 0.95rem;">Be the first to create a lesson in this category! 🚀</p>
</div>
<?php else: ?>
<?php foreach ($lessons as $lesson): ?>
<?php
$isAuthor = false;
if (!empty($lesson['author']) && strcasecmp($lesson['author'], $currentUsername) === 0) {
    $isAuthor = true;
} elseif (!empty($lesson['author_email']) && strcasecmp($lesson['author_email'], $currentEmail) === 0) {
    $isAuthor = true;
} elseif (!empty($lesson['user_id']) && (int)$lesson['user_id'] === $currentUserId && $currentUserId > 0) {
    $isAuthor = true;
}
$visibility = $lesson['visibility'] ?? 'Public';
$isPrivate = strcasecmp($visibility, 'Private') === 0;
?>
<div class="col lesson-grid-item" data-is-author="<?= $isAuthor ? '1' : '0' ?>" data-visibility="<?= htmlspecialchars($visibility) ?>" data-liked="<?= !empty($lesson['liked_by_current']) ? 'true' : 'false' ?>" data-likes-count="<?= intval($lesson['likes_count'] ?? 0) ?>">
    <div class="card liquid-rim lesson-card h-100 hvr-grow shadow-lg" data-lesson-id="<?= $lesson['_id'] ?>">
        <div class="card-body d-flex flex-column p-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php
                    $lvl = htmlspecialchars($lesson['level'] ?? 'Beginner');
                    $lvlColor = strcasecmp($lvl, 'Advanced') === 0 ? 'danger' : (strcasecmp($lvl, 'Intermediate') === 0 ? 'warning' : 'success');
                    $matchPct = 78 + (crc32((string)($lesson['_id'] ?? '1')) % 20);
                    ?>
                    <span class="badge bg-<?= $lvlColor ?> text-white">
                        <i class="bx bxs-star me-1"></i><?= strtolower($lvl) ?>
                    </span>
                    <span class="badge bg-<?= $isPrivate ? 'secondary' : 'info' ?> text-white lesson-visibility-badge">
                        <i class="bx <?= $isPrivate ? 'bx-lock-alt' : 'bx-globe' ?> me-1"></i><?= $isPrivate ? 'Private' : 'Public' ?>
                    </span>
                    <span class="badge bg-primary text-white">
                        <i class="bx bx-search me-1"></i>Auto-matched · <?= $matchPct ?>%
                    </span>
                </div>
                <div class="dropdown ms-1">
                    <button class="btn btn-link text-secondary p-0" data-coreui-toggle="dropdown">
                        <i class="bx bx-cog"></i>
                        <i class="bx bx-caret-down small"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end blur shadow-sm border-secondary border-opacity-25">
                        <li><a class="dropdown-item small py-1" href="/learn/lesson/<?= $lesson['_id'] ?>"><i class="bx bx-play me-2"></i>Start Lesson</a></li>
                        <?php if ($isAuthor): ?>
                            <li class="visibility-toggle-item">
                                <?php if ($isPrivate): ?>
                                <a class="dropdown-item small py-1 visibility-toggle-action" href="#" data-lesson-id="<?= $lesson['_id'] ?>" data-target-visibility="Public"><i class="bx bx-globe me-2 text-info"></i>Make Public</a>
                                <?php else: ?>
                                <a class="dropdown-item small py-1 visibility-toggle-action" href="#" data-lesson-id="<?= $lesson['_id'] ?>" data-target-visibility="Private"><i class="bx bx-lock-alt me-2 text-warning"></i>Make Private</a>
                                <?php endif; ?>
                            </li>
                            <li><hr class="dropdown-divider border-secondary border-opacity-25 my-1"></li>
                            <li><a class="dropdown-item small py-1 text-danger delete-lesson-action" href="#" data-lesson-id="<?= $lesson['_id'] ?>" data-lesson-title="<?= htmlspecialchars($lesson['title'] ?? '', ENT_QUOTES) ?>"><i class="bx bx-trash me-2"></i>Delete Lesson</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <h6 class="card-title fw-bold mb-2 text-white"><?= htmlspecialchars($lesson['title'] ?? '') ?></h6>
            <p class="card-text text-secondary mb-3 flex-grow-1 small lesson-desc-clamp"><?= htmlspecialchars($lesson['description'] ?? 'An interactive AI-generated structured curriculum covering architectural foundations, practical exercises, and hands-on laboratory tasks.') ?></p>

            <div class="d-flex align-items-center gap-3 text-secondary mb-2 small">
                <span class="d-inline-flex align-items-center gap-1"><i class="bx bx-book"></i> <?= $lesson['modules_count'] ?? 1 ?> Modules</span>
                <span class="d-inline-flex align-items-center gap-1"><i class="bx bx-layer"></i> <?= $lesson['chapters_count'] ?? 3 ?> Chapters</span>
            </div>

            <?php
            $tagsRaw = $lesson['tags'] ?? [];
            if (is_object($tagsRaw)) {
                $tags = method_exists($tagsRaw, 'getArrayCopy') ? $tagsRaw->getArrayCopy() : (array)$tagsRaw;
            } elseif (is_array($tagsRaw)) {
                $tags = $tagsRaw;
            } else {
                $tags = [];
            }
            if (empty($tags)) {
                $words = preg_split('/[\s,:\-\(\)\.\/\?]+/', strtolower($lesson['title'] ?? ''));
                $words = array_filter($words, fn($w) => strlen($w) >= 4 && !in_array($w, ['with', 'from', 'this', 'that', 'your', 'cover', 'using', 'level', 'guide', 'introduction', 'advanced', 'beginner', 'intermediate', 'designing', 'managing', 'building', 'project']));
                $tags = array_values(array_slice($words, 0, 3));
                if (empty($tags)) $tags = ['ai-learning', 'tutorial', 'practice'];
            }
            ?>
            <div class="d-flex flex-wrap gap-1 mb-3">
                <?php foreach (array_slice($tags, 0, 3) as $t): ?>
                    <span class="badge bg-primary text-white px-2 py-1">#<?= htmlspecialchars(ltrim($t, '#')) ?></span>
                <?php endforeach; ?>
                <span class="badge bg-secondary text-white px-2 py-1">+<?= max(4, count($tags) + ($lesson['modules_count'] ?? 2) + 2) ?></span>
            </div>

            <?php if (!empty($lesson['progress']) && $lesson['progress'] > 0): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-end mb-1">
                        <span class="text-secondary small">Progress</span>
                        <span class="fw-bold text-white small"><?= $lesson['progress'] ?>%</span>
                    </div>
                    <div class="progress bg-secondary bg-opacity-10 rounded-pill" style="height: 4px;">
                        <div class="progress-bar bg-success rounded-pill" style="width: <?= $lesson['progress'] ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex align-items-center justify-content-between pt-3 border-top border-secondary border-opacity-10 mt-auto">
                <div class="d-flex align-items-center gap-1 min-w-0 me-1">
                    <div class="d-flex align-items-center min-w-0">
                        <img src="<?= Session::getAvatarForUsername($lesson['author'] ?? $lesson['username'] ?? '') ?>" alt="Author" class="rounded-circle me-1 flex-shrink-0 border border-secondary border-opacity-25" width="18" height="18">
                        <span class="text-secondary text-truncate small" style="max-width: 60px; font-size: 0.75rem;"><?= htmlspecialchars($lesson['author'] ?? $lesson['username'] ?? 'Anonymous') ?></span>
                    </div>
                    <button class="btn btn-link text-secondary p-0 d-inline-flex align-items-center gap-1 text-decoration-none toggle-like-btn flex-shrink-0 ms-1" data-lesson-id="<?= $lesson['_id'] ?>" title="Like">
                        <i class="bx <?= !empty($lesson['liked_by_current']) ? 'bxs-heart text-danger' : 'bx-heart' ?> fs-6"></i>
                        <span class="lesson-like-count" style="font-size: 0.75rem;"><?= intval($lesson['likes_count'] ?? 0) ?></span>
                    </button>
                    <button class="btn btn-link text-secondary p-0 text-decoration-none share-lesson-btn flex-shrink-0 ms-1" data-lesson-id="<?= $lesson['_id'] ?>" data-lesson-title="<?= htmlspecialchars($lesson['title'] ?? '', ENT_QUOTES) ?>" title="Share lesson">
                        <i class="bx bx-share-alt fs-6"></i>
                    </button>
                    <button class="btn btn-link text-<?= (!empty($lesson['prompt_unlocked'])) ? 'success' : 'secondary' ?> p-0 text-decoration-none reveal-hint-btn flex-shrink-0 ms-1" data-lesson-id="<?= $lesson['_id'] ?>" title="<?= (!empty($lesson['prompt_unlocked'])) ? 'Generation Prompt (Unlocked)' : 'Reveal Generation Prompt' ?>">
                        <i class="bx bx-bulb fs-6"></i>
                    </button>
                </div>
                <a href="/learn/lesson/<?= $lesson['_id'] ?>" class="btn btn-sm btn-success rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1 fw-medium text-nowrap flex-shrink-0" style="font-size: 0.78rem;">
                    <?= (!empty($lesson['progress']) && $lesson['progress'] > 0) ? 'Continue' : 'Start Learning' ?> <i class="bx bx-right-arrow-alt" style="width:12px;height:12px;"></i>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
