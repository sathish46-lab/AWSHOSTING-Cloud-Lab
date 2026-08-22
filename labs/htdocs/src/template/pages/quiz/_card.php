<?php
/**
 * Quiz Card Partial (Flat solid badges matching SNA reference)
 */

$qTitle = $q['title'] ?? "Quiz Title";
$qHash = $q['hash'] ?? "";
$viewCount = $q['view_count'] ?? 0;
$questions = $q['questions'] ?? $q['content'] ?? [];
$qCount = count($questions);

$basePoints = 25;
if ($qDiff === 'easy') $basePoints = 15;
elseif ($qDiff === 'hard') $basePoints = 50;

$zealReward = ($qCount * ($q['points_per_correct'] ?? $basePoints));
$joltReward = $qJolt;
$tags = (isset($q['tags'])) ? (array)$q['tags'] : ['tech'];
if (empty($tags)) $tags = ['tech'];

$isNew = (isset($q['created_at']) && time() - (int)$q['created_at'] < 86400 * 30);

$createdAt = $q['created_at'] ?? time();
$timeAgo = "recent";
if (is_numeric($createdAt)) {
    $diff = time() - (int)$createdAt;
    if ($diff < 60) $timeAgo = "now";
    elseif ($diff < 3600) $timeAgo = floor($diff/60) . "m ago";
    elseif ($diff < 86400) $timeAgo = floor($diff/3600) . "h ago";
    else $timeAgo = floor($diff/86400) . "y ago";
}

$remainingTagsCount = count($tags) - 3;
$remainingTagsJson = htmlspecialchars(json_encode(array_slice($tags, 3)));
?>

<div class="col quiz-card-item">
    <div class="card liquid-rim h-100 hvr-grow shadow-lg" data-quiz-hash="<?= htmlspecialchars($qHash) ?>">
        <div class="card-body">
            <div class="row">
                <div class="col-auto pb-0">
                    <h5 class="card-title fw-bold theme-text" style="font-size: 0.95rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7rem;">
                        <?= htmlspecialchars($qTitle) ?>
                    </h5>

                    <?php if ($isNew): ?>
                        <span class="badge rounded-pill px-2 py-1" style="background: #2eb857; color: #fff; font-size: 0.6rem;">new 🥳</span>
                    <?php endif; ?>

                    <span class="badge rounded-pill px-2 py-1" style="background: rgba(255,255,255,0.08); border: 1.5px solid rgba(255,255,255,0.2); color: #fff; font-size: 0.6rem;">
                        <i class="bx bxs-star me-1" style="font-size: 0.55rem;"></i><?= $qDiff ?>
                    </span>

                    <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                        <span class="badge rounded-pill px-2 py-1" style="background: #5a57cb; color: #fff; font-size: 0.6rem;"><?= htmlspecialchars(ltrim($tag, '#')) ?></span>
                    <?php endforeach; ?>

                    <?php if ($remainingTagsCount > 0): ?>
                        <span class="badge rounded-pill px-2 py-1 quiz-tag-more" style="cursor: pointer; background: rgba(var(--cui-emphasis-color-rgb), 0.1); border: 1px solid rgba(var(--cui-emphasis-color-rgb), 0.1); color: var(--cui-emphasis-color); font-size: 0.6rem;" data-remaining-tags='<?= $remainingTagsJson ?>'>+<?= $remainingTagsCount ?></span>
                    <?php endif; ?>

                    <span class="badge rounded-pill px-2 py-1" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.1); color: rgba(var(--cui-body-color-rgb), 0.6); font-size: 0.6rem;">
                        ⌚ <?= $timeAgo ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex align-items-center justify-content-between" style="border-top: 1px solid var(--cui-border-color); background: transparent;">
            <div class="d-flex align-items-center gap-1">
                <span class="reward zeal-reward" style="font-size: 0.8rem;" data-coreui-toggle="tooltip" data-coreui-placement="top" title="Will be rewarded upon completing all questions successfully."><?= $zealReward ?> 🔥</span>
                <span class="reward jolt-reward" style="font-size: 0.8rem;" data-coreui-toggle="tooltip" data-coreui-placement="top" title="Will be rewarded upon completing all questions successfully."><?= $joltReward ?> ⚡️</span>
                <span class="reward" style="font-size: 0.8rem;" data-coreui-toggle="tooltip" data-coreui-placement="top" title="Views"><?= $viewCount ?> 👁️</span>
                <button class="btn btn-link btn-sm clipboard p-0 ms-1" data-clipboard-text="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/quiz/v/" . $qHash ?>" style="text-decoration: none;" data-coreui-toggle="tooltip" data-coreui-placement="right" title="Click to copy shareable URL">
                    <i class="bx bx-share-alt" style="font-size: 0.85rem;"></i>
                </button>
            </div>
            <a href="/quiz/v/<?= htmlspecialchars($qHash) ?>" data-quizid="<?= htmlspecialchars($qHash) ?>" class="btn btn-success btn-sm rounded-pill text-nowrap" style="font-size: 0.7rem;">Answer Quiz</a>
        </div>
    </div>
</div>
