<?php if (empty($roadmaps)): ?>
<div class="text-center py-5">
    <i class='bx bx-map-pin text-secondary' style="font-size:3rem;"></i>
    <h5 class="text-white mt-3">No roadmaps found!</h5>
    <p class="text-secondary">Try different keywords or create a new roadmap.</p>
</div>
<?php else: ?>
<div class="d-flex align-items-center gap-2 mb-3">
    <i class="bx bx-search text-primary"></i>
    <span class="text-secondary" style="font-size:0.85rem;"><?= count($roadmaps) ?> result<?= count($roadmaps) !== 1 ? 's' : '' ?> for "<strong class="text-white"><?= htmlspecialchars($query) ?></strong>"</span>
    <button class="btn btn-sm btn-outline-secondary rounded-pill ms-auto" onclick="document.getElementById('roadmap-search-input').value=''; document.getElementById('roadmap-search-input').dispatchEvent(new Event('input'));">Clear</button>
</div>
<div class="row gy-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
<?php foreach ($roadmaps as $rm):
    $rmId = (string)($rm['_id'] ?? '');
    $rmSlug = $rm['slug'] ?? '';
    $rmTitle = $rm['title'] ?? 'Untitled';
    $rmDesc = $rm['description'] ?? '';
    $rmLevel = $rm['level'] ?? 'Beginner';
    $rmHours = $rm['hours'] ?? 0;
    $rmTags = ($rm['tags'] instanceof MongoDB\Model\BSONArray) ? iterator_to_array($rm['tags'], false) : (array)($rm['tags'] ?? []);
    $rmProgress = $rm['progress'] ?? 0;
    $rmCheckpointsTotal = $rm['checkpoints_total'] ?? 0;
    $rmAuthor = $rm['author'] ?? '';
    $rmVisibility = $rm['visibility'] ?? 'private';
    $rmPrompt = $rm['prompt'] ?? '';
    $rmIsOwner = ($rm['user_id'] === $currentUserId);

    $levelClass = match(strtolower($rmLevel)) {
        'beginner' => 'bg-success',
        'intermediate' => 'bg-warning text-dark',
        'advanced' => 'bg-danger',
        default => 'bg-secondary'
    };

    // Highlight matched text
    $highlightedTitle = htmlspecialchars($rmTitle);
    $highlightedDesc = htmlspecialchars(mb_strimwidth($rmDesc, 0, 120, '...'));
    if (!empty($query)) {
        $escaped = preg_quote(htmlspecialchars($query), '/');
        $highlightedTitle = preg_replace('/(' . $escaped . ')/i',
            '<strong style="background:rgba(99,102,241,0.25);border-radius:3px;padding:0 2px;">$1</strong>',
            $highlightedTitle);
        $highlightedDesc = preg_replace('/(' . $escaped . ')/i',
            '<strong style="background:rgba(99,102,241,0.25);border-radius:3px;padding:0 2px;">$1</strong>',
            $highlightedDesc);
    }
?>
    <div class="col" data-title="<?= htmlspecialchars(strtolower($rmTitle)) ?>" data-level="<?= htmlspecialchars(strtolower($rmLevel)) ?>">
        <div class="card h-100 blur border-0" style="border-radius:14px;cursor:pointer;" onclick="window.location.href='/roadmaps/<?= htmlspecialchars($rmSlug) ?>'">
            <div class="card-body d-flex flex-column p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge <?= $levelClass ?> rounded-pill px-2 py-1" style="font-size:0.7rem;">
                            <i class='bx bx-star me-1'></i><?= htmlspecialchars($rmLevel) ?>
                        </span>
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-1" style="font-size:0.65rem;">AI</span>
                    </div>
                </div>
                <h5 class="card-title text-white mb-1" style="font-size:0.95rem;"><?= $highlightedTitle ?></h5>
                <p class="card-text text-secondary mb-2" style="font-size:0.8rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                    <?= $highlightedDesc ?>
                </p>
                <div class="d-flex gap-3 mb-2 text-secondary" style="font-size:0.78rem;">
                    <span><i class="bx bx-list-ul me-1"></i><?= $rmCheckpointsTotal ?> Topics</span>
                    <span><i class="bx bx-time me-1"></i><?= $rmHours ?>h</span>
                </div>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php foreach (array_slice($rmTags, 0, 3) as $t): ?>
                        <span class="badge bg-primary bg-opacity-15 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-1" style="font-size:0.68rem;">#<?= htmlspecialchars(ltrim($t, '#')) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($rmTags) > 3): ?>
                        <span class="badge bg-secondary bg-opacity-25 text-secondary rounded-pill px-2 py-1" style="font-size:0.68rem;">+<?= count($rmTags) - 3 ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($rmProgress > 0): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-secondary" style="font-size:0.72rem;">Progress</span>
                        <span class="text-white" style="font-size:0.72rem;"><?= $rmProgress ?>%</span>
                    </div>
                    <div class="progress" style="height:4px;background:rgba(255,255,255,0.08);">
                        <div class="progress-bar bg-success rounded-pill" style="width:<?= $rmProgress ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="d-flex align-items-center justify-content-between pt-2 mt-auto border-top border-secondary border-opacity-10">
                    <div class="d-flex align-items-center gap-1 text-secondary" style="font-size:0.75rem;">
                        <i class="bx bx-user"></i>
                        <span><?= htmlspecialchars($rmAuthor ?: 'Anonymous') ?></span>
                    </div>
                    <a href="/roadmaps/<?= htmlspecialchars($rmSlug) ?>" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-medium" style="font-size:0.78rem;">
                        <?= $rmProgress > 0 ? 'Continue' : 'Start' ?> <i class="bx bx-right-arrow-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
