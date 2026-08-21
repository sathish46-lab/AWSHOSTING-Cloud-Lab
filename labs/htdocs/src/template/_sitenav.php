<header class="header header-sticky blur rounded-0 mb-0" style="border-bottom: none; height: 4rem; min-height: 4rem;">
    <div class="container-fluid px-4 h-100">
        <!-- Left: Back + Greeting -->
        <div class="d-flex align-items-center gap-2 flex-shrink-0" style="z-index: 2;">
            <!-- Mobile Sidebar Toggle -->
            <button class="btn btn-link p-0 text-secondary hover-text-white d-flex d-md-none align-items-center justify-content-center rounded-circle border border-white border-opacity-10 text-decoration-none shadow-none" 
                    type="button" 
                    onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()"
                    style="width: 32px; height: 32px; transition: all 0.2s;">
                <i class="bx bx-menu fs-4"></i>
            </button>

            <!-- Back Button -->
            <a class="d-flex align-items-center justify-content-center text-secondary text-decoration-none rounded-circle" 
                    onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '/home'; }" 
                    style="width: 36px; height: 36px; background: rgba(255,255,255,0.06) !important; border: 1px solid rgba(255,255,255,0.1) !important; transition: all 0.2s; cursor: pointer;" title="Go Back">
                <i class="bx bx-left-arrow-alt" style="font-size: 1.2rem;"></i>
            </a>
            <div class="d-none d-lg-flex flex-column ms-2">
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--cui-body-color); line-height: 1.2;">Hi, <?= Session::getUser()?->getUsername() ?? 'Guest' ?>!</span>
                <span style="font-size: 0.7rem; color: var(--cui-secondary-color); line-height: 1.2;">Let's take a look at your activity today</span>
            </div>
            <nav aria-label="breadcrumb" class="d-none">
                <ol id="main-breadcrumb" class="breadcrumb my-0 py-0" style="background: transparent; gap: 4px;">
                    <?php include __DIR__ . '/partials/_breadcrumb.php'; ?>
                </ol>
            </nav>
        </div>

        <!-- Center: Search Bar — flex:1 fills remaining space -->
        <div class="d-flex align-items-center justify-content-center search-bar-wrap mx-2 mx-md-3" style="z-index: 2;">
            <div class="search-bar-wrapper w-100" id="search-wrapper">
                <div class="search-bar">
                    <i class="bx bx-search search-icon"></i>
                    <input type="text" class="search-input" 
                        placeholder="Search for labs, devices, challenges..." 
                        id="globalSearch" autocomplete="off">
                    <span class="search-page-text d-none d-md-inline text-truncate" id="searchPageBadge" data-tip="curr: Dashboard" style="max-width: 180px;">curr: Dashboard</span>
                    <span class="page-title-tooltip" id="pageTitleTooltip">curr: Dashboard</span>
                    <span class="badge bg-success d-none d-sm-inline" style="font-size: 0.6rem; padding: 3px 6px; opacity: 0.85;">Esc</span>
                </div>
                <div id="search-results" class="search-results d-none"></div>
            </div>
        </div>

        <!-- Right: Nav Items -->

        <ul class="header-nav ms-auto mb-0 align-items-center list-unstyled d-flex flex-shrink-0">
            <?php 
                $navUser = Session::getUser();
                $navStats = $navUser ? \TomLabs\Labs\Quiz::getUserStats($navUser->getEmail()) : ['zeal' => 0, 'jolt' => 10];
            ?>
            <li class="nav-item d-none d-md-flex align-items-center me-3">
                <div class="d-flex align-items-center gap-3 rounded-pill px-3 py-1 border border-secondary border-opacity-10 shadow-sm" style="background: rgba(var(--cui-emphasis-color-rgb, 128, 128, 128), 0.05);">
                    <div class="d-flex align-items-center gap-1" title="Total Zeal (Experience Points)">
                        <span class="fw-bold text-body-emphasis small" id="header-zeal" style="font-size: 0.85rem;"><?= number_format($navStats['zeal'] ?? 0) ?></span>
                        <i class="bx bxs-hot text-danger" style="font-size: 0.9rem;"></i>
                    </div>
                    <div class="vr bg-secondary opacity-20" style="height: 12px;"></div>
                    <div class="d-flex align-items-center gap-1" title="Available Jolt (Fuel)">
                        <span class="fw-bold text-body-emphasis small" id="header-jolt" style="font-size: 0.85rem;"><?= number_format($navStats['jolt'] ?? 0) ?></span>
                        <i class="bx bxs-zap text-warning" style="font-size: 0.9rem;"></i>
                    </div>
                </div>
            </li>
        
            <!-- Notifications -->
            <li class="nav-item dropdown px-2">
                <button class="btn btn-link nav-link py-0 d-flex align-items-center justify-content-center position-relative"
                    type="button" data-coreui-toggle="dropdown" style="height: 40px; width: 40px;">
                    <i class="bx bx-bell fs-4 text-secondary hover-text-white"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border-0" 
                        style="font-size: 0.6rem; padding: 2px 5px; display: none;" id="notif-badge">
                        0
                    </span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 p-0" style="min-width: 320px; border-radius: 12px; max-height: 400px; overflow-y: auto;">
                    <div class="px-3 py-2 border-bottom d-flex align-items-center justify-content-between">
                        <h6 class="fw-bold mb-0 small">Notifications</h6>
                        <button class="btn btn-link btn-sm p-0 text-secondary small" onclick="document.getElementById('notif-badge').style.display='none'">Mark all read</button>
                    </div>
                    <div class="px-3 py-3 text-center text-secondary small" id="notif-list">
                        <i class="bx bx-bell-off fs-1 d-block mb-2 opacity-50"></i>
                        No new notifications
                    </div>
                </div>
            </li>

            <!-- Theme & Background Mega Dropdown -->
            <li class="nav-item dropdown px-2">
                <button class="btn btn-link nav-link py-0 d-flex align-items-center justify-content-center theme-selector-btn"
                    type="button" data-coreui-toggle="dropdown" style="height: 40px; width: 40px;">
                    <i class="bx theme-icon-active fs-4" id="currentThemeIcon"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end theme-mega-container border-0 bg-transparent shadow-none p-0">
                    <div class="d-flex align-items-start gap-3 p-2 flex-row-reverse">
                        <!-- Mode Selector Card (Rightmost, under the icon) -->
                        <div class="mode-selector-card shadow-lg">
                            <button class="mode-item" onclick="changeTheme('light')" onmouseenter="TomVisuals.switchBGTheme('light')" data-coreui-value="light">
                                <i class='bx bx-sun'></i> <span>Light</span>
                            </button>
                            <button class="mode-item" onclick="changeTheme('dark')" onmouseenter="TomVisuals.switchBGTheme('dark')" data-coreui-value="dark">
                                <i class='bx bx-moon'></i> <span>Dark</span>
                            </button>
                            <button class="mode-item" onclick="changeTheme('auto')" onmouseenter="TomVisuals.switchBGTheme('auto')" data-coreui-value="auto">
                                <i class='bx bx-circle-half'></i> <span>Auto</span>
                            </button>
                        </div>

                        <!-- Background Selector Card (Reveals to the left) -->
                        <div class="bg-selector-card shadow-lg">
                            <?php require $_SERVER['DOCUMENT_ROOT'] . '/src/config/themes.php'; ?>
                            
                            <!-- Dark Mode Backgrounds -->
                            <div class="theme-bg-grid p-3" id="bg-grid-dark" data-theme="dark">
                                <div class="theme-bg-item" onclick="TomBG.setMode('plain')" data-mode="plain">
                                    <div class="theme-bg-thumb-wrapper"><div style="width:100%; height:100%; background: linear-gradient(45deg, #010d12, #0b1e36);"></div></div>
                                    <span class="theme-bg-label">Plain</span>
                                </div>
                                <?php foreach ($tomThemes as $id => $theme): 
                                    $label = ($id === 'robo') ? 'Lab' : (($id === 'ninja') ? 'War' : (($id === 'robotower') ? 'Tower' : (($id === 'spiderman') ? 'Spidey' : (($id === 'ironman') ? 'Iron Man' : ucfirst($id)))));
                                    $thumb = $theme['assets'][0]; // Use the first layer as thumb
                                    if (strpos($thumb, '.png') !== false && !strpos($thumb, 'robo.jpg') && !strpos($thumb, 'ninja.jpg')) {
                                        // If it's a parallax layer, try to use the jpg thumb if it exists, otherwise just the layer
                                        $jpgThumb = str_replace(['0.png', '1.png', '2.png'], $id.'.jpg', $thumb);
                                        // This is a bit hacky, but for your specific structure:
                                        if ($id === 'robo') $thumb = '/assets/Background_Img/robo/robo.jpg';
                                        if ($id === 'ninja') $thumb = '/assets/Background_Img/ninja/ninja.jpg';
                                        if ($id === 'robotower') $thumb = '/assets/Background_Img/RoboTower/robo_tower.jpg';
                                        if ($id === 'spiderman') $thumb = '/assets/Background_Img/spiderman/spiderman.jpg';
                                    }
                                    if ($id === 'ironman') $thumb = '/assets/Background_Img/IronMan/0.jpg';
                                ?>
                                <div class="theme-bg-item" onclick="TomBG.setMode('<?= $id ?>')" data-mode="<?= $id ?>">
                                    <div class="theme-bg-thumb-wrapper"><img src="<?= $thumb ?>" class="theme-bg-thumb"></div>
                                    <span class="theme-bg-label"><?= $label ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Light Mode Backgrounds (Same list for now as requested) -->
                            <div class="theme-bg-grid p-3 d-none" id="bg-grid-light" data-theme="light">
                                <div class="theme-bg-item" onclick="TomBG.setMode('plain')" data-mode="plain">
                                    <div class="theme-bg-thumb-wrapper"><div style="width:100%; height:100%; background: linear-gradient(45deg, #f8f9fa, #e9ecef);"></div></div>
                                    <span class="theme-bg-label">Plain</span>
                                </div>
                                <?php foreach ($tomThemes as $id => $theme): 
                                    $label = ($id === 'robo') ? 'Lab' : (($id === 'ninja') ? 'War' : (($id === 'robotower') ? 'Tower' : (($id === 'spiderman') ? 'Spidey' : (($id === 'ironman') ? 'Iron Man' : ucfirst($id)))));
                                    $thumb = $theme['assets'][0];
                                    if ($id === 'robo') $thumb = '/assets/Background_Img/robo/robo.jpg';
                                    if ($id === 'ninja') $thumb = '/assets/Background_Img/ninja/ninja.jpg';
                                    if ($id === 'robotower') $thumb = '/assets/Background_Img/RoboTower/robo_tower.jpg';
                                    if ($id === 'spiderman') $thumb = '/assets/Background_Img/spiderman/spiderman.jpg';
                                    if ($id === 'ironman') $thumb = '/assets/Background_Img/IronMan/0.jpg';
                                ?>
                                <div class="theme-bg-item" onclick="TomBG.setMode('<?= $id ?>')" data-mode="<?= $id ?>">
                                    <div class="theme-bg-thumb-wrapper"><img src="<?= $thumb ?>" class="theme-bg-thumb"></div>
                                    <span class="theme-bg-label"><?= $label ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="dropdown-divider border-white border-opacity-10 my-0"></div>
                            <a class="dropdown-item d-flex align-items-center justify-content-center py-2 text-secondary hover-text-white transition-all" 
                               style="font-size: 0.75rem;" data-coreui-toggle="modal" data-coreui-target="#plainColorModal">
                                <i class="bx bx-color-fill me-1"></i> Custom Theme Designer
                            </a>
                        </div>
                    </div>
                </div>
            </li>

            <li class="nav-item dropdown ms-2">
                <a class="nav-link py-0 pe-0 d-flex align-items-center" data-coreui-toggle="dropdown" href="#"
                    role="button">
                    <div class="avatar avatar-md shadow-sm border border-secondary border-opacity-25 rounded-circle overflow-hidden">
                        <img class="avatar-img" 
                            src="<?= Session::getAvatar() ?>" 
                            style="<?= Session::getAvatarStyle() ?>"
                            alt="User">
                    </div>
                </a>

                <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-3 p-2"
                    style="min-width: 200px; border-radius: 12px;">
                    <div class="px-2 py-1">
                        <h6 class="fw-bold mb-1 text-lowercase ls-1" style="font-size: 0.85rem;">
                            <?= Session::getUser()?->getUsername() ?? 'Guest' ?></h6>

                        <div class="d-flex align-items-center mb-1">
                            <span class="text-secondary me-1" style="font-size: 0.7rem;">Vpn Status:</span>
                            <span
                                class="badge rounded-pill bg-danger"
                                style="font-size: 0.5rem;">
                                Not Connected
                            </span>
                        </div>
                    </div>

                    <div class="dropdown-divider mx-n2 mb-1"></div>

                    <div class="list-group list-group-flush">
                        <div class="dropdown-item d-flex align-items-center justify-content-between px-2 py-1 rounded" onclick="event.stopPropagation()">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                    <input class="form-check-input m-0 pointer" type="checkbox" role="switch" id="visualBlurToggle" style="float: none; margin-left: 0;"
                                        onchange="TomVisuals.toggleBlur(this.checked); TomVisuals.showRecommendation();">
                                </div>
                                <label class="pointer mb-0" for="visualBlurToggle" style="font-size: 0.8rem; font-weight: 500;">
                                    Visual Blur
                                </label>
                            </div>
                            <i class='bx bx-info-circle text-secondary hover-text-white pointer ms-auto' onclick="TomVisuals.showRecommendation()" style="font-size: 0.95rem;" title="Visuals Recommendation"></i>
                        </div>

                        <a class="dropdown-item d-flex align-items-center px-2 py-1 rounded"
                            href="/<?= Session::getUser()?->getUsername() ?>">
                            <i class="bx bx-shield-alt-2 text-secondary me-2" style="font-size: 1rem;"></i>
                            <span style="font-size: 0.8rem; font-weight: 500;">My Account</span>
                        </a>

                        <a class="dropdown-item d-flex align-items-center px-2 py-1 rounded pointer" 
                            data-coreui-toggle="modal" data-coreui-target="#bgSelectModal">
                            <i class="bx bx-palette text-secondary me-2" style="font-size: 1rem;"></i>
                            <span style="font-size: 0.8rem; font-weight: 500;">Change Background</span>
                        </a>

                        <a class="dropdown-item d-flex align-items-center px-2 py-1 rounded pointer" 
                            data-coreui-toggle="modal" data-coreui-target="#plainColorModal">
                            <i class="bx bx-color-fill text-secondary me-2" style="font-size: 1rem;"></i>
                            <span style="font-size: 0.8rem; font-weight: 500;">Plain Theme Color</span>
                        </a>

                        <a class="dropdown-item d-flex align-items-center px-2 py-1 rounded pointer"
                            onclick="openAccountSettings()">
                            <i class="bx bx-cog text-secondary me-2" style="font-size: 1rem;"></i>
                            <span style="font-size: 0.8rem; font-weight: 500;">Account Settings</span>
                        </a>

                        <?php if (Session::getUser()?->getRole() === 'superuser'): ?>
                        <div class="dropdown-divider mx-n2 my-1"></div>
                        <a class="dropdown-item d-flex align-items-center px-2 py-1 rounded pointer text-warning" href="/admin/users">
                            <i class="bx bx-crown me-2" style="font-size: 1rem;"></i>
                            <span style="font-size: 0.8rem; font-weight: 600;">Admin Panel</span>
                        </a>
                        <?php endif; ?>

                        <div class="dropdown-divider mx-n2 my-1"></div>

                        <form method="post" action="/" class="m-0 p-0" hx-boost="false">
                            <input type="hidden" name="logout" value="1">
                            <button type="submit" class="dropdown-item d-flex align-items-center px-2 py-1 rounded text-danger border-0 bg-transparent w-100 text-start" style="cursor:pointer;">
                                <i class="bx bx-log-out-circle me-2" style="font-size: 1rem;"></i>
                                <span class="small">Logout</span>
                            </button>
                        </form>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</header>

<?php
$labsSearchItems = [];
if (Session::getAuthStatus() === Constants::STATUS_LOGGEDIN) {
    try {
        $navUser = Session::getUser();
        $navLabTemplates = [
            ['id' => 'essentials',     'name' => 'Essentials Lab'],
            ['id' => 'gui_essentials', 'name' => 'GUI Essentials Lab'],
            ['id' => 'minio',          'name' => 'MinIO S3 Storage'],
            ['id' => 'n8n',            'name' => 'n8n Workflow Lab'],
            ['id' => 'docker_lab',     'name' => 'Docker Lab'],
        ];
        foreach ($navLabTemplates as $tmpl) {
            $hash = $navUser->getLabHash($tmpl['id']);

            $dashUrl = '/labs/dashboard/' . $hash;
            $prefUrl = '/labs/preferences/' . $hash;
            $domUrl  = '/labs/domains/' . $hash;

            $name = $tmpl['name'];

            $labsSearchItems[] = ['title' => $name . ' > Dashboard', 'section' => 'Labs', 'url' => $dashUrl, 'icon' => 'bx-layout', 'cat' => 'Navigation'];
            $labsSearchItems[] = ['title' => $name . ' > Preferences', 'section' => 'Labs', 'url' => $prefUrl, 'icon' => 'bx-slider', 'cat' => 'Navigation'];
            $labsSearchItems[] = ['title' => $name . ' > Domains', 'section' => 'Labs', 'url' => $domUrl, 'icon' => 'bx-globe', 'cat' => 'Navigation'];
            $labsSearchItems[] = ['title' => $name . ' > Activity', 'section' => 'Labs', 'url' => '/labs/activity/' . $hash, 'icon' => 'bx-history', 'cat' => 'Navigation'];
        }
    } catch (\Exception $e) {
        $labsSearchItems = [];
    }
}
?>

<script>
(function() {
    var searchInput = document.getElementById('globalSearch');
    var resultsEl = document.getElementById('search-results');
    var wrapper = document.getElementById('search-wrapper');
    if (!searchInput || !resultsEl || !wrapper) return;

    var activeIndex = -1;
    var currentQuery = '';
    var debounceTimer = null;

    function getPageTitle() {
        var t = document.title.replace(/\s*[-|]\s*Tom Labs.*$/, '').trim();
        return t || 'Dashboard';
    }

    function updatePageBadge() {
        var badge = document.getElementById('searchPageBadge');
        var tooltip = document.getElementById('pageTitleTooltip');
        if (badge) {
            var title = 'curr: ' + getPageTitle();
            badge.textContent = title;
            badge.setAttribute('data-tip', title);
            if (tooltip) tooltip.textContent = title;
        }
    }
    updatePageBadge();

    document.addEventListener('htmx:afterSettle', function() { setTimeout(updatePageBadge, 50); });
    document.addEventListener('htmx:afterRequest', function() { setTimeout(updatePageBadge, 100); });

    if (typeof MutationObserver !== 'undefined') {
        var titleEl = document.querySelector('title');
        if (titleEl) {
            new MutationObserver(function() { setTimeout(updatePageBadge, 30); })
                .observe(titleEl, { childList: true, characterData: true, subtree: true });
        }
    }

    var badgeEl = document.getElementById('searchPageBadge');
    var tipEl = document.getElementById('pageTitleTooltip');
    if (badgeEl && tipEl) {
        badgeEl.onmouseenter = function() {
            if (badgeEl.scrollWidth > badgeEl.offsetWidth) {
                tipEl.textContent = badgeEl.getAttribute('data-tip');
                tipEl.classList.add('visible');
            }
        };
        badgeEl.onmouseleave = function() { tipEl.classList.remove('visible'); };
    }

    function rotatePlaceholder() {
        var page = getPageTitle();
        var items = [
            'Search for labs, challenges, lessons...',
            'Current page: ' + page,
            'Type to search anything...',
            'Find labs, quizzes, roadmaps...',
            'Ctrl+K to search...',
            'Explore challenges & earn Zeal...',
            'Deploy. Learn. Conquer.',
        ];
        var idx = 0;
        setInterval(function() {
            if (document.activeElement === searchInput) return;
            idx = (idx + 1) % items.length;
            searchInput.setAttribute('placeholder', items[idx]);
        }, 2500);
    }
    rotatePlaceholder();

    function highlightMatch(text, query) {
        if (!query) return text;
        var re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return text.replace(re, '<strong style="color: var(--cui-body-color); background: rgba(59,130,246,0.15); border-radius: 3px; padding: 0 2px;">$1</strong>');
    }

    var groupMeta = {
        running:    { label: 'Your Labs',        icon: 'bx-hard-hat',  colour: '#22c55e' },
        catalog:    { label: 'Lab Catalog',       icon: 'bx-diamond',   colour: '#E95420' },
        apps:       { label: 'Pages & Apps',      icon: 'bx-grid-alt',  colour: '#6366f1' },
        challenges: { label: 'Challenges',        icon: 'bx-trophy',    colour: '#ef4444' },
        quiz:       { label: 'Quiz Topics',       icon: 'bx-cert',      colour: '#8b5cf6' },
        learn:      { label: 'Learn AI Lessons',  icon: 'bx-brain',     colour: '#06b6d4' },
        roadmaps:   { label: 'Roadmaps',          icon: 'bx-map',       colour: '#10b981' },
        syllabus:   { label: 'Syllabus',          icon: 'bx-notes',     colour: '#f472b6' },
    };

    function renderIcon(item) {
        if (item.icon && item.icon.kind === 'image' && item.icon.url) {
            return '<img src="' + item.icon.url + '" style="width:22px;height:22px;border-radius:50%;object-fit:cover;" alt="">';
        }
        if (item.icon && item.icon.kind === 'glyph') {
            return '<i class="bx ' + item.icon.glyph + '" style="font-size:1.15rem;width:22px;text-align:center;color:' + (item.icon.colour || '#999') + ';"></i>';
        }
        if (item.glyph) {
            return '<i class="bx ' + item.glyph + '" style="font-size:1.15rem;width:22px;text-align:center;color:' + (item.colour || '#999') + ';"></i>';
        }
        return '<i class="bx bx-hash text-secondary" style="font-size:1.15rem;width:22px;text-align:center;"></i>';
    }

    function renderGroup(key, items, query) {
        var meta = groupMeta[key] || { label: key, icon: 'bx-folder', colour: '#999' };
        var html = '<div class="px-3 py-1 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size:0.65rem;letter-spacing:0.08em;color:' + meta.colour + ';opacity:0.85;">'
            + '<i class="bx ' + meta.icon + '" style="font-size:0.75rem;"></i>' + meta.label + '</div>';

        items.forEach(function(item) {
            var activeClass = globalIdx === activeIndex ? ' active' : '';
            var href = item.href || item.url || '#';
            var isExternal = item.codeserver || false;
            var dataAttr = isExternal ? '' : ' data-full-nav';
            html += '<a href="' + href + '" hx-get="' + href + '" hx-target="#main-content" hx-push-url="true"'
                + ' class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none search-result-item' + activeClass + '"'
                + ' data-index="' + globalIdx + '"' + dataAttr
                + ' style="transition:background 0.1s;color:var(--cui-body-color);font-size:0.88rem;">'
                + renderIcon(item)
                + '<span>' + highlightMatch(item.label || item.title || '', query) + '</span>'
                + '<span class="ms-auto text-secondary" style="font-size:0.7rem;opacity:0.5;">' + (item.sub || '') + '</span>'
                + '<i class="bx bx-right-arrow-alt text-secondary" style="opacity:0;transition:opacity 0.15s;font-size:1rem;"></i>'
                + '</a>';
            globalIdx++;
        });
        return html;
    }

    var globalIdx = 0;

    function renderResults(data) {
        var groups = data.groups || {};
        var query = data.q || '';
        var order = ['running', 'catalog', 'apps', 'challenges', 'quiz', 'learn', 'roadmaps', 'syllabus'];

        var hasResults = order.some(function(k) { return groups[k] && groups[k].length > 0; });

        if (!hasResults) {
            resultsEl.innerHTML = '<div class="px-3 py-3 text-center text-secondary small">No results found for "' + query + '"</div>';
            resultsEl.classList.remove('d-none');
            return;
        }

        var html = '';
        globalIdx = 0;

        order.forEach(function(key) {
            if (groups[key] && groups[key].length > 0) {
                html += renderGroup(key, groups[key], query);
            }
        });

        resultsEl.innerHTML = html;
        resultsEl.classList.remove('d-none');
        activeIndex = -1;

        resultsEl.querySelectorAll('.search-result-item').forEach(function(item) {
            item.addEventListener('mouseenter', function() {
                resultsEl.querySelectorAll('.search-result-item').forEach(function(el) {
                    el.classList.remove('active');
                    el.style.background = '';
                    var arrow = el.querySelector('i:last-child');
                    if (arrow) arrow.style.opacity = '0';
                });
                item.classList.add('active');
                item.style.background = 'rgba(255,255,255,0.06)';
                var arrow = item.querySelector('i:last-child');
                if (arrow) arrow.style.opacity = '1';
                activeIndex = parseInt(item.dataset.index);
            });
            item.addEventListener('mouseleave', function() {
                item.classList.remove('active');
                item.style.background = '';
                var arrow = item.querySelector('i:last-child');
                if (arrow) arrow.style.opacity = '0';
            });
            item.addEventListener('click', function(e) {
                e.preventDefault();
                var url = item.getAttribute('href');
                var isLabPage = item.hasAttribute('data-full-nav');
                searchInput.value = '';
                currentQuery = '';
                resultsEl.classList.add('d-none');
                resultsEl.innerHTML = '';
                activeIndex = -1;
                if (isLabPage) {
                    window.location.href = url;
                } else {
                    htmx.ajax('GET', url, {target: '#main-content', push: url});
                }
            });
        });
    }

    function fetchSearch(query) {
        fetch('/api/search?q=' + encodeURIComponent(query))
            .then(function(r) { return r.json(); })
            .then(function(data) { renderResults(data); })
            .catch(function() {
                resultsEl.innerHTML = '<div class="px-3 py-3 text-center text-secondary small">Search error</div>';
                resultsEl.classList.remove('d-none');
            });
    }

    searchInput.addEventListener('input', function() {
        currentQuery = searchInput.value.trim();
        activeIndex = -1;
        clearTimeout(debounceTimer);
        if (!currentQuery || currentQuery.length < 1) {
            resultsEl.classList.add('d-none');
            resultsEl.innerHTML = '';
            return;
        }
        debounceTimer = setTimeout(function() { fetchSearch(currentQuery); }, 200);
    });

    searchInput.addEventListener('focus', function() {
        if (currentQuery) {
            clearTimeout(debounceTimer);
            fetchSearch(currentQuery);
        }
    });

    searchInput.addEventListener('keydown', function(e) {
        var items = resultsEl.querySelectorAll('.search-result-item');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            items.forEach(function(el, i) {
                el.classList.toggle('active', i === activeIndex);
                el.style.background = i === activeIndex ? 'rgba(255,255,255,0.06)' : '';
                var arrow = el.querySelector('i:last-child');
                if (arrow) arrow.style.opacity = i === activeIndex ? '1' : '0';
            });
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            items.forEach(function(el, i) {
                el.classList.toggle('active', i === activeIndex);
                el.style.background = i === activeIndex ? 'rgba(255,255,255,0.06)' : '';
                var arrow = el.querySelector('i:last-child');
                if (arrow) arrow.style.opacity = i === activeIndex ? '1' : '0';
            });
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            items[activeIndex].click();
        } else if (e.key === 'Escape') {
            searchInput.value = '';
            currentQuery = '';
            resultsEl.classList.add('d-none');
            resultsEl.innerHTML = '';
            activeIndex = -1;
            searchInput.blur();
        }
    });

    document.addEventListener('click', function(e) {
        if (!wrapper.contains(e.target)) {
            resultsEl.classList.add('d-none');
        }
    });
})();
</script>