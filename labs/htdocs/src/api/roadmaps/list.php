<?php
/**
 * Roadmaps - List Roadmaps API
 * Returns raw HTML (like lessons filter) or JSON
 * Supports: filter, level, search, page, render=html|json
 */
require_once __DIR__ . '/../../load.php';

$render = trim($_GET['render'] ?? 'json');

// ── JSON MODE ──
if ($render === 'json') {
    header('Content-Type: application/json');

    if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $user = Session::getUser();
    $userId = (int)$user->getUserId();
    $filter = trim($_GET['filter'] ?? 'all');
    $level = trim($_GET['level'] ?? 'all');
    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $skip = ($page - 1) * $limit;

    $validFilters = ['all', 'mine', 'my_roadmaps', 'public', 'explore', 'continue', 'liked', 'editor', 'interacted', 'my_likes'];
    if (!in_array($filter, $validFilters)) $filter = 'all';
    $validLevels = ['all', 'beginner', 'intermediate', 'advanced'];
    if (!in_array($level, $validLevels)) $level = 'all';

    $db = DatabaseConnection::getDefaultDatabase();

    switch ($filter) {
        case 'mine': case 'my_roadmaps':
            $query = ['user_id' => $userId]; break;
        case 'public': case 'explore':
            $query = ['visibility' => 'public']; break;
        case 'continue':
            $query = ['user_id' => $userId, 'progress' => ['$gt' => 0, '$lt' => 100]]; break;
        case 'liked': case 'my_likes':
        case 'editor': case 'interacted':
            $query = ['$or' => [['user_id' => $userId], ['visibility' => 'public']]]; break;
        default:
            $query = ['$or' => [['user_id' => $userId], ['visibility' => 'public']]];
    }

    if ($level !== 'all') {
        $query['level'] = ['$regex' => '^' . preg_quote($level, '/') . '$', '$options' => 'i'];
    }
    if (!empty($search)) {
        $escaped = preg_quote($search, '/');
        $query['$and'][] = ['$or' => [
            ['title' => ['$regex' => $escaped, '$options' => 'i']],
            ['prompt' => ['$regex' => $escaped, '$options' => 'i']],
            ['tags' => ['$regex' => $escaped, '$options' => 'i']],
        ]];
    }

    $total = $db->ai_roadmaps->countDocuments($query);
    $cursor = $db->ai_roadmaps->find($query, [
        'sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit,
        'projection' => ['sections' => 0],
    ]);

    $roadmaps = [];
    foreach ($cursor as $doc) {
        $roadmaps[] = [
            'id' => (string)$doc['_id'], 'slug' => $doc['slug'], 'title' => $doc['title'],
            'description' => $doc['description'] ?? '', 'prompt' => $doc['prompt'] ?? '',
            'level' => $doc['level'] ?? 'Beginner', 'hours' => $doc['hours'] ?? 0,
            'tags' => (array)($doc['tags'] ?? []), 'visibility' => $doc['visibility'] ?? 'public',
            'author' => $doc['author'] ?? '', 'user_id' => $doc['user_id'],
            'is_owner' => ($doc['user_id'] === $userId), 'progress' => $doc['progress'] ?? 0,
            'likes_count' => $doc['likes_count'] ?? 0,
            'checkpoints_total' => $doc['checkpoints_total'] ?? 0,
            'checkpoints_completed' => $doc['checkpoints_completed'] ?? 0,
            'created_at' => $doc['created_at'] ?? null,
        ];
    }

    echo json_encode([
        'roadmaps' => $roadmaps, 'total' => $total, 'page' => $page,
        'limit' => $limit, 'pages' => (int)ceil($total / $limit),
    ]);
    exit;
}

// ── HTML MODE (raw HTML, like lessons filter) ──
header('Content-Type: text/html; charset=utf-8');

if (Session::getAuthStatus() != Constants::STATUS_LOGGEDIN) {
    echo '<div class="text-center py-5"><p class="text-danger small">Please log in to view roadmaps.</p></div>';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$filter = trim($_GET['filter'] ?? 'all');
$level = trim($_GET['level'] ?? 'all');
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$skip = ($page - 1) * $limit;

$validFilters = ['all', 'mine', 'my_roadmaps', 'public', 'explore', 'continue', 'liked', 'editor', 'interacted', 'my_likes'];
if (!in_array($filter, $validFilters)) $filter = 'all';
$validLevels = ['all', 'beginner', 'intermediate', 'advanced'];
if (!in_array($level, $validLevels)) $level = 'all';

$db = DatabaseConnection::getDefaultDatabase();

switch ($filter) {
    case 'mine': case 'my_roadmaps':
        $query = ['user_id' => $userId]; break;
    case 'public': case 'explore':
        $query = ['visibility' => 'public']; break;
    case 'continue':
        $query = ['user_id' => $userId, 'progress' => ['$gt' => 0, '$lt' => 100]]; break;
    case 'liked': case 'my_likes':
    case 'editor': case 'interacted':
        $query = ['$or' => [['user_id' => $userId], ['visibility' => 'public']]]; break;
    default:
        $query = ['$or' => [['user_id' => $userId], ['visibility' => 'public']]];
}

if ($level !== 'all') {
    $query['level'] = ['$regex' => '^' . preg_quote($level, '/') . '$', '$options' => 'i'];
}
if (!empty($search)) {
    $escaped = preg_quote($search, '/');
    $query['$and'][] = ['$or' => [
        ['title' => ['$regex' => $escaped, '$options' => 'i']],
        ['prompt' => ['$regex' => $escaped, '$options' => 'i']],
        ['tags' => ['$regex' => $escaped, '$options' => 'i']],
    ]];
}

$total = $db->ai_roadmaps->countDocuments($query);
$cursor = $db->ai_roadmaps->find($query, [
    'sort' => ['created_at' => -1], 'skip' => $skip, 'limit' => $limit,
    'projection' => ['sections' => 0],
]);

$roadmaps = [];
foreach ($cursor as $doc) {
    $roadmaps[] = [
        'id' => (string)$doc['_id'], 'slug' => $doc['slug'], 'title' => $doc['title'],
        'description' => $doc['description'] ?? '', 'prompt' => $doc['prompt'] ?? '',
        'level' => $doc['level'] ?? 'Beginner', 'hours' => $doc['hours'] ?? 0,
        'tags' => (array)($doc['tags'] ?? []), 'visibility' => $doc['visibility'] ?? 'public',
        'author' => $doc['author'] ?? '', 'user_id' => $doc['user_id'],
        'is_owner' => ($doc['user_id'] === $userId), 'progress' => $doc['progress'] ?? 0,
        'likes_count' => $doc['likes_count'] ?? 0,
        'checkpoints_total' => $doc['checkpoints_total'] ?? 0,
        'checkpoints_completed' => $doc['checkpoints_completed'] ?? 0,
        'created_at' => $doc['created_at'] ?? null,
    ];
}

// Save filter preference
$db->global_settings->updateOne(
    ['user_id' => $userId, 'key' => 'roadmap_filter'],
    ['$set' => ['value' => $filter, 'level' => $level, 'updated_at' => new MongoDB\BSON\UTCDateTime()]],
    ['upsert' => true]
);

// Output raw HTML cards (like lessons_grid.php)
?>
<div class="roadmaps-grid-container" id="roadmap-masonry" data-masonry-ready="1">
<?php if (empty($roadmaps)): ?>
    <div class="text-center py-5" style="column-span:all;">
        <i class='bx bx-map-pin text-secondary' style="font-size:3rem;"></i>
        <h5 class="text-white mt-3">No roadmaps found!</h5>
        <p class="text-secondary">Try a different filter or create a new roadmap.</p>
    </div>
<?php else: ?>
    <?php foreach ($roadmaps as $rm): ?>
        <div class="col">
            <div class="card h-100 roadmap-card liquid-rim hvr-grow shadow-lg" style="cursor:pointer;" data-roadmap-id="<?= $rm['id'] ?>" data-slug="<?= htmlspecialchars($rm['slug']) ?>">
                <div class="card-body d-flex flex-column p-3">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <?php $lvlColor = strtolower($rm['level']) === 'advanced' ? 'danger' : (strtolower($rm['level']) === 'intermediate' ? 'warning' : 'success'); ?>
                            <span class="badge rounded-pill border border-<?= $lvlColor ?> text-<?= $lvlColor ?> d-inline-flex align-items-center gap-1" style="background: transparent;">
                                <i class="bx bxs-star"></i> <?= strtolower($rm['level']) ?>
                            </span>
                            <span class="badge rounded-pill border border-<?= $rm['visibility'] === 'private' ? 'secondary' : 'info' ?> text-<?= $rm['visibility'] === 'private' ? 'secondary' : 'info' ?> d-inline-flex align-items-center gap-1" style="background: transparent;">
                                <i class="bx <?= $rm['visibility'] === 'private' ? 'bx-lock-alt' : 'bx-globe' ?>"></i> <?= $rm['visibility'] === 'private' ? 'Private' : 'Public' ?>
                            </span>
                        </div>
                        <?php if ($rm['is_owner']): ?>
                        <div class="dropdown ms-1" onclick="event.stopPropagation();">
                            <button class="btn btn-link text-secondary p-0" data-coreui-toggle="dropdown" aria-expanded="false">
                                <i class="bx bx-cog"></i>
                                <i class="bx bx-caret-down small"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end blur shadow-sm border-secondary border-opacity-25">
                                <li><a class="dropdown-item small py-1" href="#" onclick="rmToggleVisibility('<?= $rm['id'] ?>', '<?= $rm['visibility'] ?>');return false;"><i class="bx <?= $rm['visibility'] === 'public' ? 'bx-lock-alt' : 'bx-globe' ?> me-2"></i><?= $rm['visibility'] === 'public' ? 'Make Private' : 'Make Public' ?></a></li>
                                <li><a class="dropdown-item small py-1" href="#" onclick="rmShowPrompt('<?= $rm['id'] ?>');return false;"><i class="bx bx-show me-2"></i>Show Prompt</a></li>
                                <li><hr class="dropdown-divider border-secondary border-opacity-25 my-1"></li>
                                <li><a class="dropdown-item small py-1 text-danger" href="#" onclick="rmDeleteRoadmap('<?= $rm['id'] ?>', '<?= htmlspecialchars(addslashes($rm['title'])) ?>');return false;"><i class="bx bx-trash me-2"></i>Delete</a></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                    <h6 class="card-title fw-bold mb-2 text-white"><?= htmlspecialchars($rm['title']) ?></h6>
                    <p class="card-text text-secondary mb-3 flex-grow-1 small"><?= htmlspecialchars(mb_strimwidth($rm['description'], 0, 120, '...')) ?></p>
                    <div class="d-flex align-items-center gap-3 text-secondary mb-2 small">
                        <span class="d-inline-flex align-items-center gap-1"><i class="bx bx-list-ul"></i> <?= $rm['checkpoints_total'] ?> Topics</span>
                        <span class="d-inline-flex align-items-center gap-1"><i class="bx bx-time"></i> <?= $rm['hours'] ?>h</span>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php $tags = (array)$rm['tags']; ?>
                        <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                            <span class="badge rounded-pill border border-primary text-primary px-2 py-1" style="background: transparent;">#<?= htmlspecialchars(ltrim($tag, '#')) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($tags) > 3): ?>
                            <span class="badge rounded-pill border border-secondary text-secondary px-2 py-1" style="background: transparent;">+<?= count($tags) - 3 ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($rm['checkpoints_total'] > 0): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-end mb-1">
                            <span class="text-secondary small">Progress</span>
                            <span class="fw-bold text-white small"><?= $rm['progress'] ?>%</span>
                        </div>
                        <div class="progress bg-secondary bg-opacity-10 rounded-pill" style="height:4px;">
                            <div class="progress-bar bg-success rounded-pill" style="width:<?= $rm['progress'] ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex align-items-center justify-content-between pt-3 border-top border-secondary border-opacity-10 mt-auto">
                        <div class="d-flex align-items-center gap-1 min-w-0 me-1">
                            <div class="d-flex align-items-center min-w-0">
                                <img src="<?= Session::getAvatarForUsername($rm['author']) ?>" alt="" class="rounded-circle me-1 flex-shrink-0 border border-secondary border-opacity-25" width="18" height="18">
                                <span class="text-secondary text-truncate small" style="max-width:60px;font-size:0.75rem;"><?= htmlspecialchars($rm['author']) ?></span>
                            </div>
                            <button class="btn btn-link text-secondary p-0 d-inline-flex align-items-center gap-1 text-decoration-none toggle-like-btn flex-shrink-0 ms-1" data-roadmap-id="<?= $rm['id'] ?>" title="Like">
                                <i class="bx bx-heart fs-6"></i>
                                <span class="roadmap-like-count" style="font-size:0.75rem;"><?= intval($rm['likes_count']) ?></span>
                            </button>
                            <button class="btn btn-link text-secondary p-0 d-inline-flex align-items-center text-decoration-none flex-shrink-0 ms-1" data-copy="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/roadmaps/' . htmlspecialchars($rm['slug']) ?>" title="Copy link">
                                <i class="bx bx-share-alt fs-6"></i>
                            </button>
                        </div>
                        <a href="/roadmaps/<?= htmlspecialchars($rm['slug']) ?>" class="btn btn-sm btn-success-gradient rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1 fw-medium shadow-sm text-nowrap flex-shrink-0" style="font-size:0.78rem;" hx-boost="false" onclick="event.stopPropagation();">
                            <?= $rm['progress'] > 0 ? 'Continue' : 'Explore' ?> <i class="bx bx-right-arrow-alt fs-6"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
<?php exit;
