<?php
require_once '../../load.php';

// Only allow authenticated users
if (!Session::getUser()) {
    http_response_code(401);
    die("Unauthorized");
}

$uiPrefs = Session::getUser()->getUiPreferences() ?? [];
$activeBgTab = $uiPrefs['bg_dialog_tab'] ?? 'swatches';

$swatches = [
    ['name' => 'Dark Green', 'bg' => '#064e3b', 'light' => '#ecfdf5', 'accent' => '#10b981'],
    ['name' => 'Pine',       'bg' => '#0a1914', 'light' => '#f0fdf4', 'accent' => '#22c55e'],
    ['name' => 'Emerald',    'bg' => '#022c22', 'light' => '#e6fcf5', 'accent' => '#20c997'],
    ['name' => 'Sunset',   'bg' => '#2a2a2a', 'light' => '#faf7f4', 'accent' => '#FF6B1A'],
    ['name' => 'Ocean',    'bg' => '#1a2238', 'light' => '#f0f4ff', 'accent' => '#4A90D9'],
    ['name' => 'Forest',   'bg' => '#1a2a1a', 'light' => '#f0fff4', 'accent' => '#4CAF50'],
    ['name' => 'Amethyst', 'bg' => '#261a38', 'light' => '#f6f0ff', 'accent' => '#9C27B0'],
    ['name' => 'Slate',    'bg' => '#252525', 'light' => '#f5f5f5', 'accent' => '#78909C'],
    ['name' => 'Teal',     'bg' => '#14282a', 'light' => '#f0fffd', 'accent' => '#009688'],
    ['name' => 'Rose',     'bg' => '#2a1a22', 'light' => '#fff0f4', 'accent' => '#E91E63'],
    ['name' => 'Espresso', 'bg' => '#2a2018', 'light' => '#fdf6f0', 'accent' => '#795548'],
    ['name' => 'Midnight', 'bg' => '#0f1628', 'light' => '#eef2ff', 'accent' => '#3F51B5'],
    ['name' => 'Olive',    'bg' => '#222a1a', 'light' => '#f8fff0', 'accent' => '#8BC34A'],
    ['name' => 'Coral',    'bg' => '#1e1e1e', 'light' => '#faf5f4', 'accent' => '#FF5722'],
    ['name' => 'Gold',     'bg' => '#2a2510', 'light' => '#fffdf0', 'accent' => '#FFC107'],
    ['name' => 'Deep Sea', 'bg' => '#00384d', 'light' => '#f1f7f9', 'accent' => '#FF6B1A'],
    ['name' => 'Arctic',   'bg' => '#0d1630', 'light' => '#f0f2f8', 'accent' => '#D4A017'],
    ['name' => 'Volcano',  'bg' => '#2e0d18', 'light' => '#faf0f3', 'accent' => '#00BCD4'],
    ['name' => 'Carbon',   'bg' => '#1f2226', 'light' => '#f4f5f6', 'accent' => '#2979FF'],
    ['name' => 'Dusk',     'bg' => '#191028', 'light' => '#f4f0fa', 'accent' => '#FFB300'],
    ['name' => 'Moss',     'bg' => '#112a1a', 'light' => '#f0f8f2', 'accent' => '#FF6B6B'],
    ['name' => 'Sapphire', 'bg' => '#0e1830', 'light' => '#f0f3fa', 'accent' => '#F06292'],
    ['name' => 'Ember',    'bg' => '#261a12', 'light' => '#faf6f2', 'accent' => '#00E676'],
];

$templates = [
    ['mode' => 'robo', 'name' => 'Robot Mode', 'img' => '/assets/Background_Img/robo/robo.jpg'],
    ['mode' => 'ninja', 'name' => 'Ninja Mode', 'img' => '/assets/Background_Img/ninja/ninja.jpg'],
    ['mode' => 'robotower', 'name' => 'Robo Tower', 'img' => '/assets/Background_Img/RoboTower/robo_tower.jpg'],
    ['mode' => 'spiderman', 'name' => 'Spiderman Mode', 'img' => '/assets/Background_Img/spiderman/spiderman.jpg'],
    ['mode' => 'ironman', 'name' => 'Iron Man Mode', 'img' => '/assets/Background_Img/IronMan/0.jpg'],
];
?>
<!-- Tab Navigation -->
<div class="px-4 pt-3">
    <ul class="nav nav-pills gap-2" id="bgModalTabs" role="tablist" style="border: none;">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeBgTab === 'my-themes' ? 'active' : '' ?> px-3 py-2 rounded-pill fw-semibold" id="my-themes-tab" data-coreui-toggle="tab" data-coreui-target="#my-themes-pane" type="button" role="tab" aria-selected="<?= $activeBgTab === 'my-themes' ? 'true' : 'false' ?>"
                onclick="saveBgModalTab('my-themes')"
                style="font-size: 0.82rem; color: var(--cui-body-color); opacity: 0.65; border: 1px solid rgba(var(--cui-emphasis-color-rgb, 255, 255, 255), 0.2);">
                My Themes <span class="badge bg-success ms-1" id="my-themes-count-badge" style="font-size: 0.65rem;">0/10</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeBgTab === 'community' ? 'active' : '' ?> px-3 py-2 rounded-pill fw-semibold" id="community-tab" data-coreui-toggle="tab" data-coreui-target="#community-pane" type="button" role="tab" aria-selected="<?= $activeBgTab === 'community' ? 'true' : 'false' ?>"
                onclick="saveBgModalTab('community')"
                style="font-size: 0.82rem; color: var(--cui-body-color); opacity: 0.65; border: 1px solid rgba(var(--cui-emphasis-color-rgb, 255, 255, 255), 0.2);">
                Community <span class="badge bg-success ms-1" id="community-count-badge" style="font-size: 0.65rem;">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeBgTab === 'templates' ? 'active' : '' ?> px-3 py-2 rounded-pill fw-semibold" id="templates-tab" data-coreui-toggle="tab" data-coreui-target="#templates-pane" type="button" role="tab" aria-selected="<?= $activeBgTab === 'templates' ? 'true' : 'false' ?>"
                onclick="saveBgModalTab('templates')"
                style="font-size: 0.82rem; color: var(--cui-body-color); opacity: 0.65; border: 1px solid rgba(var(--cui-emphasis-color-rgb, 255, 255, 255), 0.2);">
                Templates <span class="badge bg-success ms-1" id="templates-count-badge" style="font-size: 0.65rem;"><?= count($templates) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeBgTab === 'swatches' ? 'active' : '' ?> px-3 py-2 rounded-pill fw-semibold" id="swatches-tab" data-coreui-toggle="tab" data-coreui-target="#swatches-pane" type="button" role="tab" aria-selected="<?= $activeBgTab === 'swatches' ? 'true' : 'false' ?>"
                onclick="saveBgModalTab('swatches')"
                style="font-size: 0.82rem; <?= $activeBgTab === 'swatches' ? 'background: rgba(var(--cui-primary-rgb), 0.15); color: var(--cui-primary); border: 1px solid rgba(var(--cui-primary-rgb), 0.25);' : 'color: var(--cui-body-color); opacity: 0.65; border: 1px solid rgba(var(--cui-emphasis-color-rgb, 255, 255, 255), 0.2);' ?>">
                Swatches <span class="badge bg-success text-white ms-1" id="swatches-count-badge" style="font-size: 0.65rem;"><?= count($swatches) ?></span>
            </button>
        </li>
    </ul>
</div>
<div class="modal-body px-4 pb-4 pt-3">
    <div class="tab-content" id="bgModalTabContent">
        <!-- ============ SWATCHES TAB ============ -->
        <div class="tab-pane fade <?= $activeBgTab === 'swatches' ? 'show active' : '' ?>" id="swatches-pane" role="tabpanel">
            <p class="mb-4" style="font-size: 0.9rem; opacity: 0.85;">Pick a color palette for plain backgrounds</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <?php
                foreach ($swatches as $index => $swatch): 
                    $uid = "swatch_" . md5($swatch['name'] . $index);
                ?>
                    <div class="text-center pointer swatch-sphere-wrap swatch-item" 
                         onclick="TomBG.applySwatchPreset('<?= $swatch['bg'] ?>', '<?= $swatch['accent'] ?>')"
                         data-bg="<?= $swatch['bg'] ?>" data-accent="<?= $swatch['accent'] ?>"
                         style="width: 72px; position: relative;">
                         
                        <button class="swatch-circle" title="<?= $swatch['name'] ?>" style="background: transparent; border: none; padding: 0; cursor: pointer; outline: none; position: relative;">
                            <svg width="52" height="52" viewBox="0 0 48 48" style="filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));">
                                <clipPath id="top-<?= $uid ?>">
                                    <path d="M24 4 A20 20 0 0 1 44 24 L4 24 A20 20 0 0 1 24 4Z"></path>
                                </clipPath>
                                <clipPath id="bot-<?= $uid ?>">
                                    <path d="M4 24 L44 24 A20 20 0 0 1 24 44 A20 20 0 0 1 4 24Z"></path>
                                </clipPath>
                                <circle cx="24" cy="24" r="22" fill="<?= $swatch['bg'] ?>" clip-path="url(#top-<?= $uid ?>)"></circle>
                                <circle cx="24" cy="24" r="22" fill="<?= $swatch['light'] ?>" clip-path="url(#bot-<?= $uid ?>)"></circle>
                                <circle cx="24" cy="24" r="22" fill="none" stroke="<?= $swatch['accent'] ?>" stroke-width="2"></circle>
                                <circle cx="24" cy="24" r="6" fill="<?= $swatch['accent'] ?>"></circle>
                            </svg>
                            
                            <!-- Active Checkmark overlay -->
                            <div class="active-badge position-absolute shadow-sm" style="top: -2px; right: -2px; width: 16px; height: 16px; background: #22c55e; border-radius: 50%; color: white; display: none; align-items: center; justify-content: center; font-size: 11px; border: 2px solid var(--cui-body-bg); z-index: 2;">
                                <i class='bx bx-check fw-bold'></i>
                            </div>
                        </button>
                        
                        <span class="d-block text-body-emphasis mt-1" style="font-size: 0.7rem; font-weight: 500; opacity: 0.85; line-height: 1.2;"><?= $swatch['name'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Your Custom Colors Section -->
            <div class="mt-4 pt-3" style="border-top: 1px solid rgba(var(--cui-emphasis-color-rgb, 255, 255, 255), 0.08);">
                <h6 class="fw-semibold text-body-emphasis mb-3" style="font-size: 0.82rem; opacity: 0.7;">
                    <i class='bx bxs-brush me-1'></i>Your Custom Colors
                </h6>
                <div id="dynamic-custom-slots" class="d-flex justify-content-center gap-3 align-items-start flex-wrap">
                    <!-- Dynamic custom slots will be injected here by JS -->
                    <!-- Add new custom theme -->
                    <div class="text-center pointer swatch-sphere-wrap create-new-slot" 
                         onclick="TomBG.setMode('plain'); var m = coreui.Modal.getInstance(document.getElementById('bgSelectModal')); if(m)m.hide(); var dm = new coreui.Modal(document.getElementById('plainColorModal')); dm.show();"
                         style="width: 72px;">
                        <div class="rounded-circle mx-auto mb-2 swatch-sphere d-flex align-items-center justify-content-center"
                             style="width: 52px; height: 52px; background: radial-gradient(circle at 35% 30%, rgba(255,255,255,0.1), rgba(255,255,255,0.02) 70%); box-shadow: 0 6px 16px rgba(0,0,0,0.15); border: 2px dashed rgba(var(--cui-emphasis-color-rgb, 255, 255, 255), 0.2); transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);">
                            <i class='bx bx-plus text-body-emphasis' style="font-size: 1.3rem; opacity: 0.4;"></i>
                        </div>
                        <span class="d-block text-body-secondary" style="font-size: 0.65rem; font-weight: 500; line-height: 1.2;">Create New</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============ MY THEMES TAB ============ -->
        <div class="tab-pane fade <?= $activeBgTab === 'my-themes' ? 'show active' : '' ?>" id="my-themes-pane" role="tabpanel">
            <div class="p-5 text-center opacity-50">
                <i class='bx bx-image-add mb-3' style="font-size: 3rem;"></i>
                <h6>No custom themes yet</h6>
                <p class="small">Upload your own background images to see them here.</p>
            </div>
        </div>

        <!-- ============ COMMUNITY TAB ============ -->
        <div class="tab-pane fade <?= $activeBgTab === 'community' ? 'show active' : '' ?>" id="community-pane" role="tabpanel">
            <div class="p-5 text-center opacity-50">
                <i class='bx bx-globe mb-3' style="font-size: 3rem;"></i>
                <h6>Explore Community Themes</h6>
                <p class="small">Discover themes created by other users.</p>
            </div>
        </div>

        <!-- ============ TEMPLATES TAB ============ -->
        <div class="tab-pane fade <?= $activeBgTab === 'templates' ? 'show active' : '' ?>" id="templates-pane" role="tabpanel">
            <div class="row g-3">
                <?php foreach ($templates as $t): ?>
                    <div class="col-md-4">
                        <div class="bg-preview rounded-3 p-4 pointer transition-all hover-scale theme-template-card" 
                            data-mode="<?= $t['mode'] ?>"
                            data-bg-src="<?= $t['img'] ?>"
                            onclick="TomBG.setMode('<?= $t['mode'] ?>')" 
                            style="background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)); background-size: cover; background-position: center; min-height: 90px; display: flex; align-items: flex-end; justify-content: center; border: 2px solid transparent;">
                            <h6 class="fw-bold m-0 pb-1" style="font-size: 0.85rem; color: #ffffff !important;"><?= $t['name'] ?></h6>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
