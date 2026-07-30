<header class="header header-sticky blur rounded-0 mb-0" style="border-bottom: none; height: 4rem; min-height: 4rem;">    <div class="container-fluid px-4 d-flex align-items-center h-100">
        <div class="d-flex align-items-center gap-2">
            <!-- Mobile Sidebar Toggle -->
            <button class="btn btn-link p-0 text-secondary hover-text-white d-flex d-md-none align-items-center justify-content-center rounded-circle border border-white border-opacity-10 text-decoration-none shadow-none" 
                    type="button" 
                    onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()"
                    style="width: 32px; height: 32px; transition: all 0.2s;">
                <i class="bx bx-menu fs-4"></i>
            </button>

            <!-- Back Button -->
            <button class="btn btn-link p-0 text-secondary hover-text-white d-flex align-items-center justify-content-center rounded-circle border border-white border-opacity-10 text-decoration-none shadow-none" 
                    onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '/home'; }" 
                    style="width: 32px; height: 32px; transition: all 0.2s;" title="Go Back">
                <i class="bx bx-left-arrow-alt fs-4"></i>
            </button>

            <nav aria-label="breadcrumb">
                <ol id="main-breadcrumb" class="breadcrumb my-0">
                    <?php include __DIR__ . '/partials/_breadcrumb.php'; ?>
                </ol>
            </nav>
        </div>

        <ul class="header-nav ms-auto mb-0 align-items-center list-unstyled d-flex">
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

                <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-3 p-3"
                    style="min-width: 250px; border-radius: 15px;">
                    <div class="px-2 py-1">
                        <h6 class="fw-bold mb-1 text-lowercase ls-1">
                            <?= Session::getUser()?->getUsername() ?? 'Guest' ?></h6>

                        <div class="d-flex align-items-center mb-2">
                            <span class="small text-secondary me-2">Vpn Status:</span>
                            <span
                                class="badge rounded-pill bg-danger"
                                style="font-size: 0.5rem;">
                                Not Connected
                            </span>
                        </div>
                    </div>

                    <div class="dropdown-divider mx-n3 mb-2"></div>

                    <div class="list-group list-group-flush">
                        <div class="dropdown-item d-flex align-items-center justify-content-between px-2 py-2 rounded" onclick="event.stopPropagation()">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                    <input class="form-check-input m-0 pointer" type="checkbox" role="switch" id="visualBlurToggle" style="float: none; margin-left: 0;"
                                        onchange="TomVisuals.toggleBlur(this.checked); TomVisuals.showRecommendation();">
                                </div>
                                <label class="small fw-semibold pointer mb-0" for="visualBlurToggle">
                                    Visual Blur
                                </label>
                            </div>
                            <i class='bx bx-info-circle text-secondary hover-text-white pointer ms-auto' onclick="TomVisuals.showRecommendation()" style="font-size: 1.1rem;" title="Visuals Recommendation"></i>
                        </div>

                        <a class="dropdown-item d-flex align-items-center px-2 py-2 rounded"
                            href="/<?= Session::getUser()?->getUsername() ?>">
                            <i class="bx bx-shield-alt-2 fs-5 me-2 text-secondary"></i>
                            <span class="small fw-semibold">My Account</span>
                        </a>

                        <a class="dropdown-item d-flex align-items-center px-2 py-2 rounded pointer" 
                            data-coreui-toggle="modal" data-coreui-target="#bgSelectModal">
                            <i class="bx bx-palette fs-5 me-2 text-secondary"></i>
                            <span class="small fw-semibold">Change Background</span>
                        </a>

                        <a class="dropdown-item d-flex align-items-center px-2 py-2 rounded pointer" 
                            data-coreui-toggle="modal" data-coreui-target="#plainColorModal">
                            <i class="bx bx-color-fill fs-5 me-2 text-secondary"></i>
                            <span class="small fw-semibold">Plain Theme Color</span>
                        </a>

                        <?php if (Session::getUser()?->getRole() === 'superuser'): ?>
                        <div class="dropdown-divider mx-n3 my-2"></div>
                        <a class="dropdown-item d-flex align-items-center px-2 py-2 rounded pointer text-warning" href="/admin/users">
                            <i class="bx bx-crown fs-5 me-2"></i>
                            <span class="small fw-bold">Admin Panel</span>
                        </a>
                        <?php endif; ?>

                        <div class="dropdown-divider mx-n3 my-2"></div>

                        <a class="dropdown-item d-flex align-items-center px-2 py-2 rounded text-danger fw-bold"
                            href="?logout=1" hx-boost="false">
                            <i class="bx bx-log-out-circle fs-5 me-2"></i>
                            <span class="small">Logout</span>
                        </a>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</header>