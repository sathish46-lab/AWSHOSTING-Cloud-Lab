<?php
require_once __DIR__ . '/../../../../src/load.php';
$db = DatabaseConnection::getDefaultDatabase();

$globalDoc = $db->global_settings->findOne(['_id' => 'lab_features']);
$globalSettings = ($globalDoc && is_object($globalDoc) && method_exists($globalDoc, 'getArrayCopy')) ? $globalDoc->getArrayCopy() : ((array)$globalDoc ?: []);

$masterDoc = $db->global_settings->findOne(['_id' => 'master_switches']);
$masterSwitches = ($masterDoc && is_object($masterDoc) && method_exists($masterDoc, 'getArrayCopy')) ? $masterDoc->getArrayCopy() : ((array)$masterDoc ?: []);

$matrixDoc = $db->global_settings->findOne(['_id' => 'lab_feature_matrix']);
$labMatrix = ($matrixDoc && is_object($matrixDoc) && method_exists($matrixDoc, 'getArrayCopy')) ? $matrixDoc->getArrayCopy() : ((array)$matrixDoc ?: []);

$knownLabs = [
    'essentials' => 'Essentials',
    'minio' => 'MinIO',
    'n8n' => 'n8n',
    'docker_lab' => 'Docker Lab'
];
$featuresList = [
    'always_on' => 'Always On',
    'http_proxies' => 'HTTP Proxies',
    'startup_script' => 'Startup Script',
    'expose_web' => 'Expose Web',
    'mcp' => 'MCP Inspector'
];

// MCP-specific settings
$mcpDoc = $db->global_settings->findOne(['_id' => 'mcp_settings']);
$mcpSettings = ($mcpDoc && is_object($mcpDoc) && method_exists($mcpDoc, 'getArrayCopy')) ? $mcpDoc->getArrayCopy() : ((array)$mcpDoc ?: []);
?>
<style>
.admin-card { transition: all 0.2s; }
.admin-card:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(0,0,0,0.28); }
.lab-matrix-card { transition: all 0.2s; cursor: pointer; }
.lab-matrix-card:hover { transform: translateY(-3px); box-shadow: 0 8px 22px rgba(0,0,0,0.28); }
</style>

<div class="blur banner mb-3 rounded-0 border-bottom border-secondary border-opacity-10">
    <div class="card-body p-0" style="margin-left: 1rem; margin-right: 1rem;">
        <div class="container-fluid pt-3 pb-1">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="position-relative flex-shrink-0">
                        <div class="avatar lab-header-avatar">
                            <div class="avatar-img d-flex align-items-center justify-content-center bg-dark bg-opacity-25 rounded-circle p-2">
                                <i class='bx bx-crown'></i>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <h3 class="fw-bold mb-0 ls-tight lab-header-title">Superuser Admin Panel</h3>
                        <div class="d-flex flex-wrap align-items-center gap-2 small">
                            <div class="d-flex align-items-center text-secondary">
                                <span class="me-1 opacity-75">Manage users and global feature flags</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        
            <?php include __DIR__ . '/admin_nav.php'; ?>
        </div>
    </div>
</div>

<div class="container-fluid px-4 pt-3">

    <div class="row">
        <!-- Master Kill Switches Control -->
        <div class="col-xl-6 mb-4">
            <div class="card border-0 rounded-4 blur shadow-sm admin-card h-100">
                <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3">
                    <h5 class="mb-0 fw-bold text-danger"><i class='bx bx-power-off me-2'></i>Master Kill Switches</h5>
                    <small class="text-body-secondary">Disabling overrides everything else.</small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($featuresList as $key => $label): ?>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 rounded-4 h-100" style="background: rgba(var(--cui-body-bg-rgb, 11,30,54), 0.4); border: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.06);">
                                <div>
                                    <h6 class="mb-1 fw-semibold"><?= $label ?></h6>
                                </div>
                                <div class="form-check form-switch fs-4 mb-0">
                                    <input class="form-check-input pointer master-feature-toggle" type="checkbox" role="switch" data-feature="<?= $key ?>" <?= (!isset($masterSwitches[$key]) || $masterSwitches[$key] !== false) ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Global Features Control -->
        <div class="col-xl-6 mb-4">
            <div class="card border-0 rounded-4 blur shadow-sm admin-card h-100">
                <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3">
                    <h5 class="mb-0 fw-bold"><i class='bx bx-globe text-info me-2'></i>Global Force-Enable</h5>
                    <small class="text-body-secondary">Forces the feature ON for all labs and users.</small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($featuresList as $key => $label): ?>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 rounded-4 h-100" style="background: rgba(var(--cui-body-bg-rgb, 11,30,54), 0.4); border: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.06);">
                                <div>
                                    <h6 class="mb-1 fw-semibold"><?= $label ?></h6>
                                </div>
                                <div class="form-check form-switch fs-4 mb-0">
                                    <input class="form-check-input pointer global-feature-toggle" type="checkbox" role="switch" data-feature="<?= $key ?>" <?= !empty($globalSettings[$key]) ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MCP Settings -->
    <div class="row mb-4">
        <div class="col-xl-6">
            <div class="card border-0 rounded-4 blur shadow-sm admin-card h-100">
                <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3">
                    <h5 class="mb-0 fw-bold text-warning"><i class='bx bx-bot me-2'></i>MCP Access Control</h5>
                    <small class="text-body-secondary">Configure who can use MCP tools.</small>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center p-3 rounded-4" style="background: rgba(var(--cui-body-bg-rgb, 11,30,54), 0.4); border: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.06);">
                        <div>
                            <h6 class="mb-1 fw-semibold">Admin-Only Mode</h6>
                            <small class="text-body-secondary">When enabled, only superusers can connect to MCP tools. Regular users are blocked.</small>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0 ms-3">
                            <input class="form-check-input pointer mcp-admin-only-toggle" type="checkbox" role="switch" <?= !empty($mcpSettings['admin_only']) ? 'checked' : '' ?>>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Per-Lab Feature Matrix Cards -->
    <div class="card border-0 rounded-4 blur shadow-sm mb-4">
        <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3">
            <h5 class="mb-0 fw-bold"><i class='bx bx-grid-alt text-primary me-2'></i>Per-Lab Default Features</h5>
            <small class="text-body-secondary">Click a lab to configure its default available features.</small>
        </div>
        <div class="card-body p-4">
            <div class="row g-4">
                <?php foreach ($knownLabs as $labKey => $labName): 
                    $adminIconMap = [
                        'essentials' => 'bxl-tux',
                        'docker_lab' => 'bxl-docker',
                        'n8n' => 'bx-git-repo-forked'
                    ];
                    $bxClass = $adminIconMap[$labKey] ?? 'bx-cube';
                ?>
                <div class="col-md-3">
                    <div class="card border-0 rounded-4 blur shadow-sm pointer h-100 lab-matrix-card" data-coreui-toggle="modal" data-coreui-target="#matrixModal-<?= $labKey ?>">
                        <div class="card-body text-center p-4 d-flex flex-column justify-content-center align-items-center">
                            <div class="mb-3 rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10" style="width: 60px; height: 60px;">
                                <?php if ($labKey === 'minio'): ?>
                                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" height="32" width="32" class="text-primary">
                                        <path d="M13.2072 0.006c-0.6216 -0.0478 -1.2 0.1943 -1.6211 0.582a2.15 2.15 0 0 0 -0.0938 3.0352l3.4082 3.5507a3.042 3.042 0 0 1 -0.664 4.6875l-0.463 0.2383V7.2853a15.4198 15.4198 0 0 0 -8.0174 10.4862v0.0176l6.5487 -3.3281v7.621L13.7794 24V13.6817l0.8965 -0.4629a4.4432 4.4432 0 0 0 1.2207 -7.0292l-3.371 -3.5254a0.7489 0.7489 0 0 1 0.037 -1.0547 0.7522 0.7522 0 0 1 1.0567 0.0371l0.4668 0.4863 -0.006 0.0059 4.0704 4.2441a0.0566 0.0566 0 0 0 0.082 0 0.06 0.06 0 0 0 0 -0.0703l-3.1406 -5.1425 -0.1484 0.1425 0.1484 -0.1445C14.4945 0.3926 13.8287 0.0538 13.2072 0.006Zm-0.9024 9.8652v2.9941l-4.1523 2.1484a13.9787 13.9787 0 0 1 2.7676 -3.9277 14.1784 14.1784 0 0 1 1.3847 -1.2148z" fill="currentColor"></path>
                                    </svg>
                                <?php else: ?>
                                    <i class='bx <?= $bxClass ?> text-primary' style="font-size: 2rem;"></i>
                                <?php endif; ?>
                            </div>
                            <h5 class="fw-bold mb-1"><?= $labName ?></h5>
                            <small class="text-body-secondary font-monospace"><?= $labKey ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Per-Lab Feature Matrix Modals -->
<?php foreach ($knownLabs as $labKey => $labName): ?>
<div class="modal fade" id="matrixModal-<?= $labKey ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 rounded-4 shadow-lg blur" style="border: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.1) !important;">
            <div class="modal-header border-0 pt-4 px-4 pb-0">
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php
                    $labDescriptions = [
                        'essentials' => 'The essentials lab provides basic Linux tools and utilities for standard tasks.',
                        'docker_lab' => 'A complete Docker environment for building, running, and managing containers.',
                        'n8n'        => 'Workflow automation lab with n8n to connect your apps and automate tasks.',
                        'minio'      => 'S3 compatible object storage server for storing unstructured data.'
                    ];
                    $lDesc = $labDescriptions[$labKey] ?? 'Configure default features for this lab environment.';
                    
                    $adminIconMap = [
                        'essentials' => 'bxl-tux',
                        'docker_lab' => 'bxl-docker',
                        'n8n' => 'bx-git-repo-forked'
                    ];
                    $bxClass = $adminIconMap[$labKey] ?? 'bx-cube';
                ?>
                <div class="d-flex align-items-center mb-4 p-4 rounded-4" style="background: rgba(var(--cui-body-bg-rgb, 11,30,54), 0.4); border: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.06);">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-4 bg-primary bg-opacity-10" style="width: 72px; height: 72px; flex-shrink: 0;">
                        <?php if ($labKey === 'minio'): ?>
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" height="40" width="40" class="text-primary">
                                <path d="M13.2072 0.006c-0.6216 -0.0478 -1.2 0.1943 -1.6211 0.582a2.15 2.15 0 0 0 -0.0938 3.0352l3.4082 3.5507a3.042 3.042 0 0 1 -0.664 4.6875l-0.463 0.2383V7.2853a15.4198 15.4198 0 0 0 -8.0174 10.4862v0.0176l6.5487 -3.3281v7.621L13.7794 24V13.6817l0.8965 -0.4629a4.4432 4.4432 0 0 0 1.2207 -7.0292l-3.371 -3.5254a0.7489 0.7489 0 0 1 0.037 -1.0547 0.7522 0.7522 0 0 1 1.0567 0.0371l0.4668 0.4863 -0.006 0.0059 4.0704 4.2441a0.0566 0.0566 0 0 0 0.082 0 0.06 0.06 0 0 0 0 -0.0703l-3.1406 -5.1425 -0.1484 0.1425 0.1484 -0.1445C14.4945 0.3926 13.8287 0.0538 13.2072 0.006Zm-0.9024 9.8652v2.9941l-4.1523 2.1484a13.9787 13.9787 0 0 1 2.7676 -3.9277 14.1784 14.1784 0 0 1 1.3847 -1.2148z" fill="currentColor"></path>
                            </svg>
                        <?php else: ?>
                            <i class='bx <?= $bxClass ?> text-primary' style="font-size: 2.5rem;"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1"><?= $labName ?> Features</h4>
                        <p class="text-body-secondary small mb-0"><?= $lDesc ?></p>
                    </div>
                </div>
                
                <div class="row g-3">
                <?php 
                    $applicableFeatures = [
                        'essentials' => ['always_on', 'http_proxies', 'startup_script', 'expose_web'],
                        'docker_lab' => ['always_on', 'http_proxies', 'startup_script', 'expose_web'],
                        'minio'      => ['always_on', 'startup_script'],
                        'n8n'        => ['always_on', 'startup_script']
                    ];
                    
                    foreach ($featuresList as $featKey => $featLabel): 
                        $currentLabSupported = \TomLabs\Labs\LabFeatures::getSupportedFeatures($labKey);
                        $isChecked = in_array($featKey, $currentLabSupported, true);
                        $isApplicable = in_array($featKey, $applicableFeatures[$labKey] ?? []);
                        
                        $featureDetails = [
                            'always_on' => ['icon' => 'bx-power-off', 'desc' => 'Keep the lab running indefinitely without automatic shutdown.'],
                            'http_proxies' => ['icon' => 'bx-globe', 'desc' => 'Allow custom HTTP port proxying for web services.'],
                            'startup_script' => ['icon' => 'bx-code-block', 'desc' => 'Execute custom bash scripts on lab initialization.'],
                            'expose_web' => ['icon' => 'bx-server', 'desc' => 'Make standard web ports publicly accessible over the internet.']
                        ];
                        $fIcon = $featureDetails[$featKey]['icon'] ?? 'bx-cog';
                        $fDesc = $featureDetails[$featKey]['desc'] ?? 'Configure this feature for the lab.';
                ?>
                
                <?php if ($isApplicable): ?>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center p-3 h-100 rounded-4" style="background: rgba(var(--cui-body-bg-rgb, 11,30,54), 0.4); border: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.06);">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width: 42px; height: 42px; background: rgba(var(--cui-body-color-rgb, 255,255,255), 0.05);">
                                <i class='bx <?= $fIcon ?> fs-4 text-primary'></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-semibold" style="font-size: 0.9rem;"><?= $featLabel ?></h6>
                                <div class="text-body-secondary opacity-75 small" style="font-size: 0.7rem; line-height: 1.2;"><?= $fDesc ?></div>
                            </div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0 ms-2 flex-shrink-0">
                            <input class="form-check-input pointer matrix-feature-toggle" type="checkbox" role="switch" 
                                   data-lab="<?= $labKey ?>" data-feature="<?= $featKey ?>" <?= $isChecked ? 'checked' : '' ?>>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center p-3 h-100 rounded-4 opacity-50" style="background: rgba(var(--cui-body-bg-rgb, 11,30,54), 0.2); border: 1px dashed rgba(var(--cui-body-color-rgb, 255,255,255), 0.06);">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width: 42px; height: 42px; background: rgba(var(--cui-body-color-rgb, 255,255,255), 0.02);">
                                <i class='bx <?= $fIcon ?> fs-4 text-body-secondary opacity-50'></i>
                            </div>
                            <div>
                                <h6 class="mb-1 text-body-secondary" style="font-size: 0.9rem;"><del><?= $featLabel ?></del></h6>
                                <div class="text-body-secondary opacity-50 small" style="font-size: 0.7rem; line-height: 1.2;"><?= $fDesc ?></div>
                            </div>
                        </div>
                        <div class="ms-2 flex-shrink-0">
                            <span class="badge rounded-pill" style="font-size: 0.65rem; font-weight: normal; background: rgba(var(--cui-body-color-rgb, 255,255,255), 0.1);">N/A</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-coreui-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
async function toggleFeatureAPI(formData, toggleElement, successMsg) {
    const originalState = !toggleElement.checked;
    try {
        const response = await fetch('/api/admin/toggle_feature', {
            method: 'POST',
            body: formData
        });
        const res = await response.json();
        if (res.status === 'success') {
            TomNotify.show(successMsg, 'Saved', 'success', 3000);
        } else {
            TomNotify.show(res.error || 'Failed to update', 'Error', 'error', 4000);
            toggleElement.checked = originalState;
        }
    } catch(e) {
        TomNotify.show('Network error', 'Error', 'error', 4000);
        toggleElement.checked = originalState;
    }
}

document.querySelectorAll('.global-feature-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const feature = this.getAttribute('data-feature');
        const state = this.checked;
        const formData = new FormData();
        formData.append('scope', 'global');
        formData.append('feature', feature);
        formData.append('state', state);
        toggleFeatureAPI(formData, this, `Global Override for ${feature} is now ${state ? 'ENABLED' : 'DISABLED'}`);
    });
});

document.querySelectorAll('.master-feature-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const feature = this.getAttribute('data-feature');
        const state = this.checked;
        const formData = new FormData();
        formData.append('scope', 'master');
        formData.append('feature', feature);
        formData.append('state', state);
        toggleFeatureAPI(formData, this, `Master Switch for ${feature} is now ${state ? 'ON' : 'KILLED'}`);
    });
});

document.querySelectorAll('.matrix-feature-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const lab = this.getAttribute('data-lab');
        const feature = this.getAttribute('data-feature');
        const state = this.checked;
        const formData = new FormData();
        formData.append('scope', 'matrix');
        formData.append('lab', lab);
        formData.append('feature', feature);
        formData.append('state', state);
        toggleFeatureAPI(formData, this, `${feature} for ${lab} is now ${state ? 'ENABLED' : 'DISABLED'}`);
    });
});

document.querySelectorAll('.mcp-admin-only-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const state = this.checked;
        const formData = new FormData();
        formData.append('scope', 'mcp_settings');
        formData.append('setting', 'admin_only');
        formData.append('state', state);
        toggleFeatureAPI(formData, this, `MCP Admin-Only Mode is now ${state ? 'ENABLED (superusers only)' : 'DISABLED (all users)'}`);
    });
});
</script>
