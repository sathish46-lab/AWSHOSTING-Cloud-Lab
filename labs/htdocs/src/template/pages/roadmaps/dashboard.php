<?php
/**
 * Roadmaps Dashboard
 */
$db = DatabaseConnection::getDefaultDatabase();
$user = Session::getUser();
$currentUserId = $user ? (int)$user->getUserId() : 0;

$filter = $_GET['filter'] ?? 'my_roadmaps';
$levelFilter = $_GET['level'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$skip = ($page - 1) * $limit;

switch ($filter) {
    case 'mine': case 'my_roadmaps':
        $query = ['user_id' => $currentUserId];
        break;
    case 'public': case 'explore':
        $query = ['visibility' => 'public'];
        break;
    default:
        $query = ['$or' => [['user_id' => $currentUserId], ['visibility' => 'public']]];
}

$total = $db->ai_roadmaps->countDocuments($query);
$roadmapsRaw = $db->ai_roadmaps->find($query, [
    'sort' => ['created_at' => -1],
    'skip' => $skip,
    'limit' => $limit,
    'projection' => ['sections' => 0]
])->toArray();

$myCount = $db->ai_roadmaps->countDocuments(['user_id' => $currentUserId]);
$publicCount = $db->ai_roadmaps->countDocuments(['visibility' => 'public']);
$completedCount = $db->ai_roadmaps->countDocuments(['user_id' => $currentUserId, 'progress' => 100]);
$learners = count($db->ai_roadmaps->distinct('user_id'));
?>

<div class="flex-grow-1 px-3 blur rounded-0 border-0 shadow-none">
    <div class="container roadmaps-page p-4 p-lg-5 py-4 py-xl-5">
        <h2 class="mb-4 fw-bold text-center">Design your learning path</h2>

        <div class="d-flex justify-content-center gap-2 flex-wrap mb-3" style="max-width:600px;margin:0 auto;">
            <div class="flex-grow-1 position-relative">
                <i class="bx bx-search position-absolute text-secondary" style="left:14px;top:50%;transform:translateY(-50%);font-size:1rem;"></i>
                <input type="text" id="roadmap-search-input" class="form-control ps-5 pe-3 py-2 rounded-pill text-white border-secondary" placeholder="Generate Roadmap" autocomplete="off" style="font-size:0.9rem;">
            </div>
            <button class="btn btn-primary rounded-pill px-4 fw-semibold d-flex align-items-center gap-1" onclick="startGenerateFromSearch()">
                <i class="bx bx-send"></i> Generate
            </button>
        </div>

        <!-- Stats -->
        <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap mt-3 mb-2">
            <span class="badge rounded-pill bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 px-3 py-2">
                <i class='bx bxs-map me-1'></i> <?= $total ?> Roadmaps
            </span>
            <span class="badge rounded-pill bg-info bg-opacity-25 text-info border border-info border-opacity-25 px-3 py-2">
                <i class='bx bx-group me-1'></i> <?= $learners ?> Learners
            </span>
            <span class="badge rounded-pill bg-success bg-opacity-25 text-success border border-success border-opacity-25 px-3 py-2">
                <i class='bx bx-check-circle me-1'></i> <?= $completedCount ?> Completed
            </span>
            <span class="badge rounded-pill bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25 px-3 py-2">
                <i class='bx bx-globe me-1'></i> <?= $publicCount ?> Public
            </span>
        </div>

        <hr class="border-secondary border-opacity-25 my-3">

        <!-- Filter Tabs -->
        <div class="d-flex gap-2 mb-3 flex-wrap" id="rm-filter-tabs">
            <button class="btn btn-sm rounded-pill <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('all')">✨ For You</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'continue' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('continue')">▶ Continue</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'public' || $filter === 'explore' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('explore')">🌍 Explore</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'liked' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('liked')">❤️ Most Liked</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'editor' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('editor')">⭐ Editor Picks</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'interacted' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('interacted')">🔥 Most Interacted</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'my_likes' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('my_likes')">💖 My Likes</button>
            <button class="btn btn-sm rounded-pill <?= $filter === 'mine' || $filter === 'my_roadmaps' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('mine')">👤 My Roadmaps</button>
            <div class="vr mx-2 bg-secondary"></div>
            <select class="form-select form-select-sm rounded-pill text-white border-secondary" id="rm-level-filter" style="width:auto;" onchange="rmFilterLevel(this.value)">
                <option value="all" <?= $levelFilter === 'all' ? 'selected' : '' ?>>All Levels</option>
                <option value="beginner" <?= $levelFilter === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                <option value="intermediate" <?= $levelFilter === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                <option value="advanced" <?= $levelFilter === 'advanced' ? 'selected' : '' ?>>Advanced</option>
            </select>
        </div>

        <hr class="border-secondary border-opacity-25 mb-4">

        <!-- Search Results (hidden by default) -->
        <div id="rm-search-results" class="d-none">
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-secondary small mt-2">Searching...</p>
            </div>
        </div>

        <!-- Normal Cards -->
        <div id="rm-normal-cards">
            <?php if (empty($roadmapsRaw)): ?>
            <div class="text-center py-5">
                <i class='bx bx-map-pin text-secondary' style="font-size:3rem;"></i>
                <h5 class="text-white mt-3">No roadmaps found! 🗺️</h5>
                <p class="text-secondary">Be the first to create a roadmap in this category! 🚀</p>
            </div>
            <?php else: ?>
            <div class="row gy-4 row-cols-1 row-cols-md-2 row-cols-xl-3" id="roadmap-masonry">
                <?php foreach ($roadmapsRaw as $rm):
                    $rmId = (string)$rm['_id'];
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
                ?>
                <div class="col" data-title="<?= htmlspecialchars(strtolower($rmTitle)) ?>" data-level="<?= htmlspecialchars(strtolower($rmLevel)) ?>" data-tags="<?= htmlspecialchars(implode(',', array_map('strtolower', $rmTags))) ?>" data-is-owner="<?= $rmIsOwner ? '1' : '0' ?>" data-visibility="<?= htmlspecialchars($rmVisibility) ?>">
                    <div class="card h-100 blur border-0" style="border-radius:14px;cursor:pointer;" data-roadmap-id="<?= $rmId ?>" data-slug="<?= htmlspecialchars($rmSlug) ?>">
                        <div class="card-body d-flex flex-column p-3">
                            <!-- Badges -->
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge <?= $levelClass ?> rounded-pill px-2 py-1" style="font-size:0.7rem;">
                                        <i class='bx bx-star me-1'></i><?= htmlspecialchars($rmLevel) ?>
                                    </span>
                                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-1" style="font-size:0.65rem;">AI</span>
                                </div>
                                <?php if ($rmIsOwner): ?>
                                <div class="dropdown" onclick="event.stopPropagation();">
                                    <button class="btn btn-sm btn-link text-secondary p-0 border-0" data-coreui-toggle="dropdown" aria-expanded="false">
                                        <i class="bx bx-dots-vertical-rounded" style="font-size:1.1rem;"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" style="min-width:180px;border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:6px;">
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 rounded" href="#" onclick="rmToggleVisibility('<?= $rmId ?>', '<?= $rmVisibility ?>');return false;" style="font-size:0.82rem;padding:8px 12px;color:var(--cui-body-color);">
                                                <i class="bx <?= $rmVisibility === 'public' ? 'bx-lock' : 'bx-globe' ?>"></i>
                                                <?= $rmVisibility === 'public' ? 'Make Private' : 'Make Public' ?>
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 rounded" href="#" onclick="rmShowPrompt('<?= $rmId ?>');return false;" style="font-size:0.82rem;padding:8px 12px;color:var(--cui-body-color);">
                                                <i class="bx bx-show"></i> Show Generation Prompt
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider border-secondary border-opacity-25 my-1"></li>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 rounded text-danger" href="#" onclick="rmDeleteRoadmap('<?= $rmId ?>', '<?= htmlspecialchars(addslashes($rmTitle)) ?>');return false;" style="font-size:0.82rem;padding:8px 12px;">
                                                <i class="bx bx-trash"></i> Delete Roadmap
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Title -->
                            <h5 class="card-title text-white mb-1" style="font-size:0.95rem;"><?= htmlspecialchars($rmTitle) ?></h5>

                            <!-- Description -->
                            <p class="card-text text-secondary mb-2" style="font-size:0.8rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                <?= htmlspecialchars(mb_strimwidth($rmDesc, 0, 120, '...')) ?>
                            </p>

                            <!-- Meta -->
                            <div class="d-flex gap-3 mb-2 text-secondary" style="font-size:0.78rem;">
                                <span><i class='bx bx-list-ul me-1'></i><?= $rmCheckpointsTotal ?> Topics</span>
                                <span><i class='bx bx-time me-1'></i><?= $rmHours ?>h</span>
                            </div>

                            <!-- Tags -->
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <?php foreach (array_slice($rmTags, 0, 3) as $tag): ?>
                                    <span class="badge bg-primary bg-opacity-15 text-primary border-0 rounded-pill px-2 py-0" style="font-size:0.68rem;">#<?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($rmTags) > 3): ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary border-0 rounded-pill px-2 py-0" style="font-size:0.65rem;">+<?= count($rmTags) - 3 ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Progress -->
                            <?php if ($rmCheckpointsTotal > 0): ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-secondary" style="font-size:0.7rem;">Progress</small>
                                    <small class="text-secondary" style="font-size:0.7rem;"><?= $rmProgress ?>%</small>
                                </div>
                                <div class="progress" style="height:4px;background:rgba(255,255,255,0.08);border-radius:4px;">
                                    <div class="progress-bar bg-success" style="width:<?= $rmProgress ?>%;border-radius:4px;"></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="mt-auto d-flex justify-content-between align-items-center pt-2" style="border-top:1px solid rgba(255,255,255,0.06);">
                                <span class="text-secondary" style="font-size:0.72rem;">
                                    <i class='bx bx-user me-1'></i><?= htmlspecialchars($rmAuthor) ?>
                                </span>
                                <a href="/roadmaps/<?= htmlspecialchars($rmSlug) ?>" class="btn btn-sm btn-success rounded-pill px-3" style="font-size:0.75rem;" hx-boost="false" onclick="event.stopPropagation();">
                                    <?= $rmProgress > 0 ? 'Continue' : 'Start' ?> <i class='bx bx-right-arrow-alt ms-1'></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Generate Modal -->
<div class="modal fade" id="rm-generate-modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-white fw-bold"><i class='bx bx-bot me-2 text-info'></i>Generate Roadmap</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea id="rm-gen-prompt" class="form-control text-white border-secondary mb-3" rows="3" placeholder="What do you want to learn? Be specific..." style="border-radius:12px;"></textarea>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label text-muted small">Level</label>
                        <select id="rm-gen-level" class="form-select text-white border-secondary rounded-pill">
                            <option value="Beginner">Beginner</option>
                            <option value="Intermediate">Intermediate</option>
                            <option value="Advanced">Advanced</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small">Visibility</label>
                        <select id="rm-gen-visibility" class="form-select text-white border-secondary rounded-pill">
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                        </select>
                    </div>
                </div>
                <div id="rm-gen-status" class="text-center py-3 d-none">
                    <div class="spinner-border text-primary mb-2" role="status"></div>
                    <p class="text-muted small mb-0" id="rm-gen-msg">Generating roadmap...</p>
                    <div class="progress mt-2" style="height:4px;background:rgba(255,255,255,0.08);">
                        <div class="progress-bar bg-primary" id="rm-gen-bar" style="width:15%;transition:width 0.5s;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary rounded-pill px-3" data-coreui-dismiss="modal">Cancel</button>
                <button class="btn btn-primary rounded-pill px-4 fw-semibold" id="rm-gen-btn" onclick="startGenerate()">
                    <i class='bx bx-send me-1'></i> Generate
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Generation Prompt Modal -->
<div class="modal fade" id="rm-prompt-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-secondary" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-white fw-bold" id="rm-prompt-title">Generation Prompt / Output</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="rm-prompt-body" style="font-size:0.9rem;"></div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-primary rounded-pill px-4" data-coreui-dismiss="modal">Okay</button>
            </div>
        </div>
    </div>
</div>

<script>
function openGenerateModal() {
    var modal = new coreui.Modal(document.getElementById('rm-generate-modal'));
    var searchVal = document.getElementById('roadmap-search-input').value.trim();
    document.getElementById('rm-gen-prompt').value = searchVal;
    document.getElementById('rm-gen-status').classList.add('d-none');
    document.getElementById('rm-gen-btn').classList.remove('d-none');
    modal.show();
}

function startGenerateFromSearch() {
    var val = document.getElementById('roadmap-search-input').value.trim();
    openGenerateModal();
}

// Enter key on search input triggers generation
document.getElementById('roadmap-search-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        startGenerateFromSearch();
    }
});

// Search suggestions (show full cards like Learn AI)
var rmSearchTimer = null;
var rmSearchInput = document.getElementById('roadmap-search-input');
var rmSearchResults = document.getElementById('rm-search-results');
var rmNormalCards = document.getElementById('rm-normal-cards');

if (rmSearchInput) {
    rmSearchInput.addEventListener('input', function() {
        var q = this.value.trim();
        if (q.length < 2) {
            // Show normal cards, hide search results
            rmSearchResults.classList.add('d-none');
            rmNormalCards.classList.remove('d-none');
            return;
        }

        clearTimeout(rmSearchTimer);
        rmSearchTimer = setTimeout(function() {
            // Hide normal cards, show search results
            rmNormalCards.classList.add('d-none');
            rmSearchResults.classList.remove('d-none');
            rmSearchResults.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-secondary small mt-2">Searching...</p></div>';

            fetch('/api/roadmaps/suggest', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'include',
                body: 'query=' + encodeURIComponent(q)
            })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                rmSearchResults.innerHTML = html;
            })
            .catch(function() {
                rmSearchResults.innerHTML = '<div class="text-center py-4 text-danger">Search failed</div>';
            });
        }, 400);
    });
}

// Filter tabs
var rmCurrentFilter = '<?= $filter ?>';
var rmCurrentLevel = '<?= $levelFilter ?>';

function rmFilter(filter) {
    rmCurrentFilter = filter;
    updateFilterTabs();
    filterCards();
}

function rmFilterLevel(level) {
    rmCurrentLevel = level;
    filterCards();
}

function updateFilterTabs() {
    document.querySelectorAll('#rm-filter-tabs .btn').forEach(function(btn) {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline-secondary');
    });
    var filterMap = {
        'all': 0, 'continue': 1, 'explore': 2, 'public': 2,
        'liked': 3, 'editor': 4, 'interacted': 5, 'my_likes': 6,
        'mine': 7, 'my_roadmaps': 7
    };
    var idx = filterMap[rmCurrentFilter];
    if (idx !== undefined) {
        var btns = document.querySelectorAll('#rm-filter-tabs .btn');
        if (btns[idx]) {
            btns[idx].classList.remove('btn-outline-secondary');
            btns[idx].classList.add('btn-primary');
        }
    }
}

function filterCards() {
    var cards = document.querySelectorAll('#roadmap-masonry .col');
    var hasProgress = function(card) {
        var bar = card.querySelector('.progress-bar');
        return bar && parseInt(bar.style.width) > 0;
    };

    cards.forEach(function(card) {
        var show = true;
        var isOwner = card.getAttribute('data-is-owner') === '1';
        var isPublic = card.getAttribute('data-visibility') === 'public';

        // Filter by category
        switch (rmCurrentFilter) {
            case 'continue':
                show = hasProgress(card);
                break;
            case 'explore':
            case 'public':
                show = isPublic;
                break;
            case 'mine':
            case 'my_roadmaps':
                show = isOwner;
                break;
            case 'all':
            default:
                show = true;
                break;
        }

        // Filter by level
        if (show && rmCurrentLevel !== 'all') {
            var cardLevel = card.getAttribute('data-level') || '';
            show = cardLevel === rmCurrentLevel;
        }

        card.style.display = show ? '' : 'none';
    });
}

function rmPickSuggestion(el) {
    var prompt = el.getAttribute('data-prompt');
    document.getElementById('roadmap-search-input').value = prompt;
    document.getElementById('rm-suggestions').classList.add('d-none');
}

function rmHighlightMatch(text, query) {
    if (!query) return escHtml(text);
    var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return escHtml(text).replace(new RegExp('(' + escaped + ')', 'gi'),
        '<strong style="color:var(--cui-body-color);background:rgba(99,102,241,0.2);border-radius:3px;padding:0 2px;">$1</strong>');
}

function rmRenderSuggestions(data) {
    var box = document.getElementById('rm-suggestions');
    var roadmaps = data.roadmaps || [];
    var query = document.getElementById('roadmap-search-input').value.trim();

    if (roadmaps.length === 0) {
        box.innerHTML = '<div class="px-3 py-2 text-center" style="color:rgba(255,255,255,0.4);font-size:0.8rem;">No roadmaps found</div>';
        box.classList.remove('d-none');
        return;
    }

    var html = '<div class="px-3 py-1 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size:0.65rem;letter-spacing:0.08em;color:#10b981;opacity:0.85;">'
        + '<i class="bx bx-map" style="font-size:0.75rem;"></i>Your Roadmaps</div>';

    roadmaps.forEach(function(rm) {
        var pct = rm.progress || 0;
        html += '<div class="rm-suggest-item" onclick="rmPickSuggestion(this)" data-prompt="' + escAttr(rm.prompt || rm.title) + '"'
            + ' style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.05);transition:background 0.1s;color:var(--cui-body-color);font-size:0.88rem;">'
            + '<i class="bx bx-map" style="font-size:1.15rem;width:22px;text-align:center;color:#10b981;"></i>'
            + '<div class="flex-grow-1" style="min-width:0;">'
            + '<div class="fw-semibold" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + rmHighlightMatch(rm.title, query) + '</div>'
            + (rm.prompt ? '<div class="text-secondary" style="font-size:0.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(rm.prompt) + '</div>' : '')
            + '</div>'
            + '<div class="d-flex flex-column align-items-end" style="flex-shrink:0;">'
            + '<span class="badge rounded-pill bg-primary bg-opacity-25 text-primary" style="font-size:0.6rem;">' + escHtml(rm.level || '') + '</span>'
            + '<span class="text-secondary" style="font-size:0.65rem;">' + pct + '%</span>'
            + '</div>'
            + '<i class="bx bx-right-arrow-alt text-secondary" style="opacity:0;transition:opacity 0.15s;font-size:1rem;flex-shrink:0;"></i>'
            + '</div>';
    });

    box.innerHTML = html;
    box.classList.remove('d-none');

    box.querySelectorAll('.rm-suggest-item').forEach(function(item) {
        item.addEventListener('mouseenter', function() {
            this.style.background = 'rgba(255,255,255,0.05)';
            var arrow = this.querySelector('.bx-right-arrow-alt');
            if (arrow) arrow.style.opacity = '1';
        });
        item.addEventListener('mouseleave', function() {
            this.style.background = '';
            var arrow = this.querySelector('.bx-right-arrow-alt');
            if (arrow) arrow.style.opacity = '0';
        });
    });
}

let genPollTimer = null;

async function startGenerate() {
    const prompt = document.getElementById('rm-gen-prompt').value.trim();
    if (prompt.length < 10) { alert('Prompt must be at least 10 characters'); return; }
    const level = document.getElementById('rm-gen-level').value;
    
    // Navigate to the live generation page
    const params = new URLSearchParams({ topic: prompt, level: level });
    window.location.href = '/roadmaps/new?' + params.toString();
}

document.getElementById('roadmap-search-input')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.rm-card').forEach(c => {
        const t = c.dataset.title || '';
        const g = c.dataset.tags || '';
        c.style.display = (t.includes(q) || g.includes(q)) ? '' : 'none';
    });
});

async function rmToggleVisibility(roadmapId, currentVisibility) {
    try {
        const res = await fetch('/api/roadmaps/visibility', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ roadmap_id: roadmapId })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Failed to update visibility');
        }
    } catch (e) {
        alert('Network error');
    }
}

function rmShowPrompt(prompt) {
    if (!prompt) { alert('No generation prompt saved for this roadmap.'); return; }
    alert('Generation Prompt:\n\n' + prompt);
}

async function rmDeleteRoadmap(roadmapId, title) {
    if (!confirm('Delete "' + title + '"?\n\nThis action cannot be undone.')) return;
    try {
        const res = await fetch('/api/roadmaps/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ roadmap_id: roadmapId })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Failed to delete');
        }
    } catch (e) {
        alert('Network error');
    }
}

function rmShowPrompt(roadmapId) {
    var body = document.getElementById('rm-prompt-body');
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-muted small mt-2">Loading...</p></div>';
    var modal = new coreui.Modal(document.getElementById('rm-prompt-modal'));
    modal.show();

    fetch('/api/roadmaps/details?roadmap_id=' + roadmapId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.error) { body.innerHTML = '<p class="text-danger">' + escHtml(d.error) + '</p>'; return; }
            var html = '';
            if (d.prompt) html += '<div class="mb-3"><span class="fw-bold text-primary">User Prompt:</span><div class="mt-1 text-body-secondary" style="font-size:0.88rem;">' + escHtml(d.prompt) + '</div></div>';
            html += '<div class="d-flex flex-wrap gap-3 mb-3">';
            if (d.title) html += '<div><span class="fw-bold">Title:</span> <span class="text-body-secondary">' + escHtml(d.title) + '</span></div>';
            if (d.tags && d.tags.length) html += '<div><span class="fw-bold">Tags:</span> <span class="text-body-secondary">' + escHtml(d.tags.join(', ')) + '</span></div>';
            if (d.level) html += '<div><span class="fw-bold">Difficulty:</span> <span class="text-body-secondary">' + escHtml(d.level) + '</span></div>';
            if (d.model) html += '<div><span class="fw-bold">Model:</span> <span class="text-body-secondary">' + escHtml(d.model) + '</span></div>';
            html += '</div>';
            html += '<hr class="border-secondary border-opacity-25 my-3">';
            if (d.markdown) {
                html += '<div class="fw-bold mb-2">Raw Markdown:</div>';
                html += '<pre class="p-3 rounded" style="background:rgba(0,0,0,0.3);font-size:0.8rem;line-height:1.6;overflow-x:auto;max-height:400px;overflow-y:auto;white-space:pre-wrap;font-family:monospace;color:var(--cui-body-color);">' + escHtml(d.markdown) + '</pre>';
            }
            body.innerHTML = html || '<p class="text-muted">No prompt data available.</p>';
        })
        .catch(function() { body.innerHTML = '<p class="text-danger">Failed to load.</p>'; });
}

function escHtml(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function escAttr(s) {
    if (!s) return '';
    return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
</script>
