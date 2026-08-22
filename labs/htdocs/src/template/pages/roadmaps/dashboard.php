<?php
/**
 * Roadmaps Dashboard
 */
$db = DatabaseConnection::getDefaultDatabase();
$user = Session::getUser();
$currentUserId = $user ? (int)$user->getUserId() : 0;

$filter = $_GET['filter'] ?? '';
$levelFilter = $_GET['level'] ?? '';

// Restore saved preference from DB if no GET params
if (empty($filter) || empty($levelFilter)) {
    $savedPref = $db->global_settings->findOne(['user_id' => $currentUserId, 'key' => 'roadmap_filter']);
    if ($savedPref) {
        if (empty($filter)) $filter = $savedPref['value'] ?? 'all';
        if (empty($levelFilter)) $levelFilter = $savedPref['level'] ?? 'all';
    } else {
        if (empty($filter)) $filter = 'all';
        if (empty($levelFilter)) $levelFilter = 'all';
    }
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
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
        <div class="d-flex gap-2 mb-3 flex-nowrap overflow-auto pb-1" id="rm-filter-tabs" style="scrollbar-width:none;-ms-overflow-style:none;">
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('all')">✨ For You</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'continue' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('continue')">▶ Continue</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'public' || $filter === 'explore' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('explore')">🌍 Explore</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'liked' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('liked')">❤️ Most Liked</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'editor' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('editor')">⭐ Editor Picks</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'interacted' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('interacted')">🔥 Most Interacted</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'my_likes' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('my_likes')">💖 My Likes</button>
            <button class="btn btn-sm rounded-pill flex-shrink-0 <?= $filter === 'mine' || $filter === 'my_roadmaps' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="rmFilter('mine')">👤 My Roadmaps</button>
            <div class="vr mx-2 bg-secondary flex-shrink-0"></div>
            <select class="form-select form-select-sm rounded-pill text-white border-secondary flex-shrink-0" id="rm-level-filter" style="width:auto;" onchange="rmFilterLevel(this.value)">
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
            <div class="roadmaps-grid-container" id="roadmap-masonry">
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
                    $rmLikesCount = $rm['likes_count'] ?? 0;
                ?>
                <div class="col">
                    <div class="card h-100 roadmap-card liquid-rim hvr-grow shadow-lg" style="cursor:pointer;" data-roadmap-id="<?= $rmId ?>" data-slug="<?= htmlspecialchars($rmSlug) ?>">
                        <div class="card-body d-flex flex-column p-3">
                            <!-- Badges -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <?php $lvlColor = strtolower($rmLevel) === 'advanced' ? 'danger' : (strtolower($rmLevel) === 'intermediate' ? 'warning' : 'success'); ?>
                                    <span class="badge bg-<?= $lvlColor ?>-gradient d-inline-flex align-items-center gap-1">
                                        <i class="bx bxs-star"></i> <?= strtolower($rmLevel) ?>
                                    </span>
                                    <span class="badge bg-<?= $rmVisibility === 'private' ? 'secondary' : 'info' ?>-gradient d-inline-flex align-items-center gap-1">
                                        <i class="bx <?= $rmVisibility === 'private' ? 'bx-lock-alt' : 'bx-globe' ?>"></i> <?= $rmVisibility === 'private' ? 'Private' : 'Public' ?>
                                    </span>
                                </div>
                                <?php if ($rmIsOwner): ?>
                                <div class="dropdown ms-1" onclick="event.stopPropagation();">
                                    <button class="btn btn-link text-secondary p-0" data-coreui-toggle="dropdown" aria-expanded="false">
                                        <i class="bx bx-cog"></i>
                                        <i class="bx bx-caret-down small"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end blur shadow-sm border-secondary border-opacity-25">
                                        <li><a class="dropdown-item small py-1" href="#" onclick="rmToggleVisibility('<?= $rmId ?>', '<?= $rmVisibility ?>');return false;"><i class="bx <?= $rmVisibility === 'public' ? 'bx-lock-alt' : 'bx-globe' ?> me-2"></i><?= $rmVisibility === 'public' ? 'Make Private' : 'Make Public' ?></a></li>
                                        <li><a class="dropdown-item small py-1" href="#" onclick="rmShowPrompt('<?= $rmId ?>');return false;"><i class="bx bx-show me-2"></i>Show Prompt</a></li>
                                        <li><hr class="dropdown-divider border-secondary border-opacity-25 my-1"></li>
                                        <li><a class="dropdown-item small py-1 text-danger" href="#" onclick="rmDeleteRoadmap('<?= $rmId ?>', '<?= htmlspecialchars(addslashes($rmTitle)) ?>');return false;"><i class="bx bx-trash me-2"></i>Delete</a></li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Title -->
                            <h6 class="card-title fw-bold mb-2 text-white"><?= htmlspecialchars($rmTitle) ?></h6>

                            <!-- Description -->
                            <p class="card-text text-secondary mb-3 flex-grow-1 small"><?= htmlspecialchars(mb_strimwidth($rmDesc, 0, 120, '...')) ?></p>

                            <!-- Stats -->
                            <div class="d-flex align-items-center gap-3 text-secondary mb-2 small">
                                <span class="d-inline-flex align-items-center gap-1"><i class="bx bx-list-ul"></i> <?= $rmCheckpointsTotal ?> Topics</span>
                                <span class="d-inline-flex align-items-center gap-1"><i class="bx bx-time"></i> <?= $rmHours ?>h</span>
                            </div>

                            <!-- Tags -->
                            <div class="d-flex flex-wrap gap-1 mb-3">
                                <?php foreach (array_slice($rmTags, 0, 3) as $tag): ?>
                                    <span class="badge bg-primary-gradient px-2 py-1">#<?= htmlspecialchars(ltrim($tag, '#')) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($rmTags) > 3): ?>
                                    <span class="badge bg-secondary-gradient px-2 py-1">+<?= count($rmTags) - 3 ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Progress -->
                            <?php if ($rmCheckpointsTotal > 0): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-end mb-1">
                                    <span class="text-secondary small">Progress</span>
                                    <span class="fw-bold text-white small"><?= $rmProgress ?>%</span>
                                </div>
                                <div class="progress bg-secondary bg-opacity-10 rounded-pill" style="height:4px;">
                                    <div class="progress-bar bg-success rounded-pill" style="width:<?= $rmProgress ?>%"></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="d-flex align-items-center justify-content-between pt-3 border-top border-secondary border-opacity-10 mt-auto">
                                <div class="d-flex align-items-center gap-1 min-w-0 me-1">
                                    <div class="d-flex align-items-center min-w-0">
                                        <img src="<?= Session::getAvatarForUsername($rmAuthor) ?>" alt="" class="rounded-circle me-1 flex-shrink-0 border border-secondary border-opacity-25" width="18" height="18">
                                        <span class="text-secondary text-truncate small" style="max-width:60px;font-size:0.75rem;"><?= htmlspecialchars($rmAuthor) ?></span>
                                    </div>
                                    <button class="btn btn-link text-secondary p-0 d-inline-flex align-items-center gap-1 text-decoration-none toggle-like-btn flex-shrink-0 ms-1" data-roadmap-id="<?= $rmId ?>" title="Like">
                                        <i class="bx bx-heart fs-6"></i>
                                        <span class="roadmap-like-count" style="font-size:0.75rem;"><?= intval($rmLikesCount) ?></span>
                                    </button>
                                </div>
                                <a href="/roadmaps/<?= htmlspecialchars($rmSlug) ?>" class="btn btn-sm btn-success-gradient rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1 fw-medium shadow-sm text-nowrap flex-shrink-0" style="font-size:0.78rem;" hx-boost="false" onclick="event.stopPropagation();">
                                    <?= $rmProgress > 0 ? 'Continue' : 'Explore' ?> <i class="bx bx-right-arrow-alt fs-6"></i>
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

// Filter tabs — API-based like LearnAI
var rmCurrentFilter = '<?= $filter ?>';
var rmCurrentLevel = '<?= $levelFilter ?>';
var rmPage = 1;
var rmLoading = false;
var rmHasMore = true;

function rmFilter(filter) {
    rmCurrentFilter = filter;
    rmPage = 1;
    rmHasMore = true;
    updateFilterTabs();
    fetchRoadmaps(true);
    rmSavePreference();
}

function rmFilterLevel(level) {
    rmCurrentLevel = level;
    rmPage = 1;
    rmHasMore = true;
    fetchRoadmaps(true);
    rmSavePreference();
}

function rmSavePreference() {
    var fd = new FormData();
    fd.append('preference_id', 'roadmap_filters');
    fd.append('value', JSON.stringify({ filter: rmCurrentFilter, level: rmCurrentLevel }));
    fetch('/api/user/preference_save', { method: 'POST', body: fd }).catch(function() {});
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

async function fetchRoadmaps(reset) {
    if (rmLoading || (!reset && !rmHasMore)) return;
    rmLoading = true;

    var container = document.getElementById('roadmap-masonry');
    if (!container) { rmLoading = false; return; }

    if (reset) {
        container.innerHTML = '<div class="text-center py-5" style="column-span:all;"><div class="spinner-border text-primary" role="status"></div><p class="text-secondary small mt-2">Loading...</p></div>';
    } else {
        var loader = document.createElement('div');
        loader.id = 'rm-loader';
        loader.className = 'text-center py-4';
        loader.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"></div>';
        container.appendChild(loader);
        rmMasonry();
    }

    try {
        var url = '/api/roadmaps/list?render=html&filter=' + encodeURIComponent(rmCurrentFilter) + '&level=' + encodeURIComponent(rmCurrentLevel) + '&page=' + rmPage;
        var res = await fetch(url, { credentials: 'include' });
        if (!res.ok) throw new Error('Network response was not ok');
        var html = await res.text();

        if (reset) {
            container.outerHTML = html;
            rmMasonry();
        } else {
            var loaderEl = document.getElementById('rm-loader');
            if (loaderEl) loaderEl.remove();

            if (!html.trim() || html.indexOf('No roadmaps found') !== -1) {
                rmHasMore = false;
            } else {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var newGrid = tmp.querySelector('.roadmaps-grid-container');
                if (newGrid) {
                    var newCols = newGrid.querySelectorAll('.col');
                    newCols.forEach(function(col) {
                        container.appendChild(col);
                    });
                    rmMasonry();
                }
            }
        }

        rmPage++;
    } catch (err) {
        console.error('Failed to fetch roadmaps:', err);
        var loaderErr = document.getElementById('rm-loader');
        if (loaderErr) loaderErr.remove();
        if (reset) {
            container.innerHTML = '<div class="text-center py-5 text-danger" style="column-span:all;">Failed to load roadmaps</div>';
        }
    } finally {
        rmLoading = false;
    }
}

// Like toggle — like lesson_like
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.toggle-like-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    var roadmapId = btn.getAttribute('data-roadmap-id');
    if (!roadmapId) return;

    var icon = btn.querySelector('i');
    var countEl = btn.querySelector('.roadmap-like-count');
    var wasLiked = icon.classList.contains('bxs-heart');

    // Optimistic update
    if (wasLiked) {
        icon.className = 'bx bx-heart';
        if (countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent || '0') - 1);
    } else {
        icon.className = 'bx bxs-heart text-danger';
        if (countEl) countEl.textContent = parseInt(countEl.textContent || '0') + 1;
    }

    fetch('/api/roadmaps/roadmap_like', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ roadmap_id: roadmapId })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.result === 'success') {
            icon.className = data.liked ? 'bx bxs-heart text-danger' : 'bx bx-heart';
            if (countEl) countEl.textContent = data.like_count;
        }
    })
    .catch(function() {
        // Revert on error
        icon.className = wasLiked ? 'bx bxs-heart text-danger' : 'bx bx-heart';
        if (countEl) countEl.textContent = wasLiked ? parseInt(countEl.textContent || '0') + 1 : Math.max(0, parseInt(countEl.textContent || '0') - 1);
    });
});

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

// Masonry layout — compact grid like SNA
function rmMasonry() {
    var container = document.getElementById('roadmap-masonry');
    if (!container) return;
    var cols = container.querySelectorAll('.col');
    if (!cols.length) return;

    var containerWidth = container.offsetWidth;
    var gap = 20;
    var colCount = containerWidth >= 900 ? 3 : containerWidth >= 600 ? 2 : 1;
    var colWidth = (containerWidth - (colCount - 1) * gap) / colCount;
    var colHeights = new Array(colCount).fill(0);

    cols.forEach(function(col) {
        if (col.style.display === 'none') return;
        col.style.width = colWidth + 'px';
        col.style.position = 'absolute';

        // Find shortest column
        var minIdx = 0;
        for (var i = 1; i < colHeights.length; i++) {
            if (colHeights[i] < colHeights[minIdx]) minIdx = i;
        }

        var left = minIdx * (colWidth + gap);
        var top = colHeights[minIdx];

        col.style.left = left + 'px';
        col.style.top = top + 'px';

        colHeights[minIdx] = top + col.offsetHeight + gap;
    });

    // Set container height
    container.style.height = Math.max.apply(null, colHeights) + 'px';
    container.setAttribute('data-masonry-ready', '1');
}

// Initialize masonry on load and resize
function rmInitMasonry() {
    rmMasonry();
    rmPage = 2; // Server already rendered page 1
}
document.addEventListener('DOMContentLoaded', rmInitMasonry);

// Also re-run after HTMX swaps content
document.addEventListener('htmx:afterSwap', function(e) {
    if (e.detail.target && e.detail.target.querySelector && e.detail.target.querySelector('#roadmap-masonry')) {
        setTimeout(rmMasonry, 10);
    }
});

// Infinite scroll
window.addEventListener('scroll', function() {
    if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 300) {
        fetchRoadmaps(false);
    }
});

window.addEventListener('resize', function() {
    clearTimeout(window._rmMasonryTimer);
    window._rmMasonryTimer = setTimeout(rmMasonry, 150);
});
</script>
