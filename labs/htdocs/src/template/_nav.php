<?php
// Returns full relative paths like 'dashboard' or 'labs/dashboard'
$current = Session::getCurrentFile(); 
?>
<div class="sidebar sidebar-fixed border-end-0" id="sidebar">
    <!-- ANTI-FLICKER SCRIPT: Instantly apply narrow state before browser paints -->
    <script>
        (function() {
            const sb = document.getElementById('sidebar');
            // Unconditionally block transitions during initial render
            sb.classList.add('no-transition-on-load');
            
            if (document.documentElement.classList.contains('sidebar-init-narrow')) {
                sb.classList.add('sidebar-narrow-unfoldable');
            }
            if (document.documentElement.classList.contains('sidebar-init-hidden')) {
                sb.classList.add('hide');
            }
            
            // Remove the transition lock after the page has fully loaded and painted
            window.addEventListener('load', () => {
                setTimeout(() => sb.classList.remove('no-transition-on-load'), 50);
            });
        })();
    </script>
    <div class="sidebar-inner-layer d-flex flex-column">
    <div class="sidebar-header border-opacity-10 py-3">
        <div class="sidebar-brand text-decoration-none">
            <div class="sidebar-brand-full">
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <img src="/assets/logo/logo.png" width="44" height="44" alt="Tom Labs Icon" style="border-radius: 8px;">
                    <span class="fs-4 fw-bold mb-0" style="color: var(--cui-sidebar-brand-color, inherit); letter-spacing: 0.5px; line-height: 1;">Tom Labs</span>
                </div>
            </div>
            <img class="sidebar-brand-narrow" src="/assets/logo/logo.png" width="44" height="44" alt="TL" style="border-radius: 8px;">
        </div>
    </div>

    <ul class="sidebar-nav" data-coreui="navigation" data-simplebar>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'dashboard' ? 'active' : '' ?>" href="<?= Session::url('dashboard') ?>">
                <svg class="nav-icon">
                    <use xlink:href="/assets/icons/sprites/free.svg#cil-speedometer"></use>
                </svg>
                Dashboard
            </a>
        </li>
        <li class="nav-group <?= (str_contains($current, 'learn') || str_contains($current, 'roadmap')) ? 'show' : '' ?>">
            <a class="nav-link nav-group-toggle" href="javascript:void(0);">
                <i class="nav-icon bx bxs-graduation"></i> Learn
            </a>
            <ul class="nav-group-items">
                <li class="nav-item">
                    <a class="nav-link <?= (str_contains($current, 'learn')) ? 'active' : '' ?>" href="/learn">
                        <i class="nav-icon bx bx-bot text-info"></i> Learn AI
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (str_contains($current, 'roadmap')) ? 'active' : '' ?>" href="/roadmaps">
                        <i class="nav-icon bx bx-map text-success"></i> Roadmaps
                    </a>
                </li>
            </ul>
        </li>
        <li class="nav-group <?= $current == 'quiz' ? 'show' : '' ?>">
            <a class="nav-link nav-group-toggle" href="javascript:void(0);">
                <i class="nav-icon bx bx-spreadsheet"></i> Evaluate
            </a>
            <ul class="nav-group-items">
                <li class="nav-item">
                    <a class="nav-link <?= $current == 'quiz' ? 'active' : '' ?>" href="/quiz">
                        <i class="nav-icon bx bxs-zap text-warning"></i> Spot Quiz  ⚡
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="javascript:void(0);">
                        <i class="nav-icon bx bx-code-alt"></i> Code Arena 👨🏽‍💻
                    </a>
                </li>
            </ul>
        </li>
        <li class="nav-title">Tom Labs</li>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'devices' ? 'active' : '' ?>" href="/devices">
                <svg class="nav-icon">
                    <use xlink:href="/assets/icons/sprites/free.svg#cil-devices"></use>
                </svg> Devices
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'network' ? 'active' : '' ?>" href="/network">
                <svg class="nav-icon">
                    <use xlink:href="/assets/icons/sprites/free.svg#cil-sitemap"></use>
                </svg> Network
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'domains' ? 'active' : '' ?>" href="/domains">
                <i class="nav-icon bx bx-globe"></i> Domains
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= (str_contains($current, 'instances')) ? 'active' : '' ?>" href="/instances">
                <i class="nav-icon bx bx-cube-alt"></i> Instances
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= (str_contains($current, 'labs')) ? 'active' : '' ?>" href="/labs">
                <svg class="nav-icon">
                    <use xlink:href="/assets/icons/sprites/free.svg#cil-memory"></use>
                </svg> Labs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= (str_contains($current, 'services')) ? 'active' : '' ?>" href="/services">
                <i class="nav-icon bx bxs-data"></i> Services
            </a>
        </li>
        <?php if (\TomLabs\Labs\LabFeatures::canAccessMcp()): ?>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'mcp' ? 'active' : '' ?>" href="/mcp">
                <i class="nav-icon bx bx-terminal"></i> MCP Inspector
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'challenges' ? 'active' : '' ?>" href="<?= Session::url('challenges') ?>">
                <i class="nav-icon bx bxs-flag-alt"></i> Challenges
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current == 'test' ? 'active' : '' ?>" href="/test">
                <svg class="nav-icon">
                    <use xlink:href="/assets/icons/sprites/free.svg#cil-bowling"></use>
                </svg> test
            </a>
        </li>
    </ul>

    <div id="sidebar-stats-container" class="sidebar-stats p-3 border-top border-light border-opacity-10">
        <div class="stat-group mb-2">
            <div class="small mb-1 cpuinfotext">
                <span class="stat-label fw-bold">CPU USAGE: <span id="sidebar-cpu-val">0.00%</span></span>
            </div>
            <div class="progress rounded-0 stats-progress-bg cpuinfo" style="height: 6px; background: rgba(0,0,0,0.3);">
                <?php
                $style = ['danger', 'warning', 'primary', 'success', 'info', 'secondary'];
                $ncpu = Session::getProcessorCount();
                for ($i = 0; $i < $ncpu; $i++) {
                    $colorClass = $style[$i % count($style)];
                    // Use transition: width for smooth animation without jQuery
                    echo '<div class="progress-bar bg-' . $colorClass . '-gradient cpu-' . $i . '" 
                                role="progressbar" style="width: 0%; transition: width 0.4s ease;" 
                                aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>';
                }
                ?>
            </div>
            <div id="sidebar-load-val" class="stat-subtext mt-1">Loading...</div>
        </div>

        <div class="stat-group mb-2">
            <div class="small mb-1"><span class="stat-label fw-bold">MEMORY USAGE</span></div>
            <div class="progress rounded-0 stats-progress-bg" style="height: 6px; background: rgba(0,0,0,0.3);">
                <div id="sidebar-mem-bar" class="progress-bar bg-warning"
                    style="width: 0%; transition: width 0.4s ease;"></div>
            </div>
            <div id="sidebar-mem-details" class="stat-subtext mt-1">Loading...</div>
        </div>

        <div class="stat-group mb-2">
            <div class="small mb-1"><span class="stat-label fw-bold">SWAP USAGE</span></div>
            <div class="progress rounded-0 stats-progress-bg" style="height: 6px; background: rgba(0,0,0,0.3);">
                <div id="sidebar-swap-bar" class="progress-bar bg-danger"
                    style="width: 0%; transition: width 0.4s ease;"></div>
            </div>
            <div id="sidebar-swap-details" class="stat-subtext mt-1">Loading...</div>
        </div>
    </div>
    </div>

    <div class="sidebar-footer border-top border-light border-opacity-10 d-flex align-items-center" style="height: 46px; padding: 0 8px;">
        <button class="header-toggler border-0 d-flex align-items-center justify-content-center" type="button"
            onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()"
            style="width: 46px; height: 46px; background: transparent;">
            <i class="bx bx-menu fs-4 text-secondary"></i>
        </button>
        <button class="sidebar-toggler ms-auto me-2" type="button" data-coreui-toggle="unfoldable"
            data-coreui-target="#sidebar"></button>
    </div>
</div>

<!-- Fast Sidebar Logo Toggler script to prevent JS overhead -->
<script>
(() => {
  const sidebar   = document.getElementById('sidebar');
  if (!sidebar) return;

  const fullImgs  = sidebar.querySelectorAll('.sidebar-brand-full');
  const narrowImgs= sidebar.querySelectorAll('.sidebar-brand-narrow');

  const showSet = (showFull) => {
    fullImgs.forEach(img   => img.style.display   = showFull ? 'block' : 'none');
    narrowImgs.forEach(img => img.style.display   = showFull ? 'none'  : 'block');

    if (showFull) {
      fullImgs.forEach(img => {
        if (!img.dataset.src || img.dataset.loaded) return;
        img.src = img.dataset.src;
        img.dataset.loaded = '1';
      });
    }
  };

  const isCollapsed = () => sidebar.classList.contains('sidebar-narrow') || sidebar.classList.contains('sidebar-narrow-unfoldable');

  showSet(!isCollapsed());

  sidebar.addEventListener('transitionend', (e) => {
    if (e.propertyName !== 'width') return;
    showSet(!isCollapsed());
  });
})();

// Sidebar Active State Sync & Cleanup
function syncSidebarActiveState() {
    const path = window.location.pathname;
    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
        link.classList.remove('active');
        const href = link.getAttribute('href');
        if (href && !href.startsWith('javascript:') && href !== '#' && href !== '/') {
            if (path === href || path.startsWith(href + '/')) {
                link.classList.add('active');
                const navGroup = link.closest('.nav-group');
                if (navGroup) {
                    navGroup.classList.add('show');
                }
            }
        } else if (href === '/' && path === '/') {
            link.classList.add('active');
        }
    });

    document.querySelectorAll('.sidebar-nav .nav-group').forEach(group => {
        const hasActiveChild = group.querySelector('.nav-group-items .nav-link.active');
        const toggle = group.querySelector('.nav-group-toggle');
        if (!hasActiveChild) {
            if (toggle) toggle.classList.remove('active');
            if (window.location.hash === '#' || path.includes('/dashboard')) {
                group.classList.remove('show');
            }
        }
    });
}
document.addEventListener('DOMContentLoaded', syncSidebarActiveState);
document.addEventListener('htmx:afterSettle', syncSidebarActiveState);
</script>