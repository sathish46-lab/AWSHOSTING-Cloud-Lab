<?php
$user = Session::getUser();
$userId = (int)$user->getUserId();

$dbInstances = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instances = iterator_to_array($dbInstances->instances->find(['user_id' => $userId]));
$trashedInstances = iterator_to_array($dbInstances->instance_trash->find(['user_id' => $userId]));

// Also include base lab templates as forkable sources
$baseLabs = [
    ['instance_hash' => 'base:essentials', 'name' => 'Essentials Lab', 'template' => 'essentials', 'icon' => 'bxl-tux', 'color' => '#e95420'],
    ['instance_hash' => 'base:minio',      'name' => 'MinIO S3 Storage', 'template' => 'minio',      'icon' => 'bx-cube', 'color' => '#00a6e0'],
    ['instance_hash' => 'base:n8n',        'name' => 'n8n Workflow',     'template' => 'n8n',        'icon' => 'bx-git-repo-forked', 'color' => '#ea4b71'],
    ['instance_hash' => 'base:docker_lab', 'name' => 'Docker Lab',       'template' => 'docker_lab', 'icon' => 'bxl-docker', 'color' => '#2496ed'],
];
$forkableLabs = array_merge($baseLabs, $instances);

$activeTab = 'templates';
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/instances/trash') !== false) {
    $activeTab = 'trash';
}
?>
<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-3 py-3">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-body-secondary theme-text p-2 rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class='bx bx-cube-alt fs-3'></i>
                </div>
                <div>
                    <h2 class="fw-bold theme-text m-0 d-flex align-items-center gap-2">
                        Instances <span class="text-secondary opacity-50 fw-light">—— Developer Area</span>
                    </h2>
                    <p class="text-secondary opacity-75 mt-1 mb-0 fs-6">
                        Author labs, build them into images, deploy running copies, share with your team.
                    </p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <button class="btn instance-action-btn instance-action-primary d-flex align-items-center gap-2 rounded-pill px-4 fw-bold">
                    <i class='bx bx-plus-circle'></i> Create template
                </button>
                <button class="btn instance-action-btn d-flex align-items-center gap-2 rounded-pill px-4" data-coreui-toggle="modal" data-coreui-target="#forkLabModal">
                    <i class='bx bx-git-repo-forked text-danger'></i> Fork a lab
                </button>
                <button class="btn instance-action-btn d-flex align-items-center gap-2 rounded-pill px-4">
                    <i class='bx bx-import text-danger'></i> Import
                </button>
            </div>
        </div>

        <ul class="nav nav-tabs lab-nav-tabs border-0 m-0" role="tablist">
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center gap-2 instance-dashboard-tab <?= $activeTab === 'templates' ? 'active' : '' ?>" data-tab="templates" type="button">
                    <i class='bx bx-cube'></i> Your templates <span class="badge bg-success rounded-pill px-2 py-1 fw-bold ms-1" id="templatesCount"><?= count($instances) ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center gap-2 instance-dashboard-tab <?= $activeTab === 'trash' ? 'active' : '' ?>" data-tab="trash" type="button">
                    <i class='bx bx-trash'></i> Trash <span class="badge bg-danger rounded-pill px-2 py-1 fw-bold ms-1" id="trashCount"><?= count($trashedInstances) ?></span>
                </button>
            </li>
        </ul>
    </div>
</div>

<div class="container-fluid px-3 pb-3">
    <!-- Dynamic Tab Content Container -->
    <div id="instanceDashboardContent">
        <div class="text-center py-5">
            <div class="spinner-border text-secondary" role="status"></div>
        </div>
    </div>

    <!-- <div class="row g-4 mt-4">
        <div class="col-12 col-md-6">
            <div class="card blur border-0 shadow-sm rounded-4 p-4 d-flex flex-row align-items-center justify-content-between cursor-pointer">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-secondary bg-opacity-10 p-2 rounded-circle">
                        <i class='bx bx-compass theme-text fs-4'></i>
                    </div>
                    <div>
                        <h6 class="theme-text fw-bold m-0">Explore catalog</h6>
                        <span class="text-secondary small">Public + team-shared templates</span>
                    </div>
                </div>
                <i class='bx bx-right-arrow-alt text-secondary fs-4'></i>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card blur border-0 shadow-sm rounded-4 p-4 d-flex flex-row align-items-center justify-content-between cursor-pointer">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-secondary bg-opacity-10 p-2 rounded-circle">
                        <i class='bx bx-group theme-text fs-4'></i>
                    </div>
                    <div>
                        <h6 class="theme-text fw-bold m-0">Workgroups</h6>
                        <span class="text-secondary small">Teams you share to</span>
                    </div>
                </div>
                <i class='bx bx-right-arrow-alt text-secondary fs-4'></i>
            </div>
        </div>
    </div> -->
</div>

<!-- Fork Lab Modal -->
<div class="modal fade" id="forkLabModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 overflow-hidden shadow-lg glass-modal-content" id="forkLabForm">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h4 class="modal-title fw-bold text-white">Fork an existing lab</h4>
            </div>
            <div class="modal-body px-4 py-4">
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold mb-2">Lab to fork</label>
                    <select name="source_id" class="form-select border-secondary border-opacity-50 text-white rounded-pill px-3 py-2 shadow-none focus-border-primary" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\'%3e%3cpath fill=\'none\' stroke=\'%236c757d\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2 5l6 6 6-6\'/%3e%3c/svg%3e');" required>
                        <?php if(!empty($forkableLabs)): foreach($forkableLabs as $lab): ?>
                            <option value="<?= htmlspecialchars($lab['instance_hash']) ?>"><?= htmlspecialchars(ucfirst($lab['name'] ?? $lab['template'] ?? 'lab')) ?> (<?= htmlspecialchars(substr($lab['instance_hash'], 0, 16)) ?>)</option>
                        <?php endforeach; else: ?>
                            <option value="">No labs available to fork</option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text text-secondary opacity-75 mt-2" style="font-size: 0.75rem;">A new editable template is created from this lab — you become its owner.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold mb-2">Name (optional)</label>
                    <input type="text" name="name" class="form-control border-secondary border-opacity-50 text-white rounded-pill px-3 py-2 shadow-none focus-border-primary" placeholder="Defaults to '<source> (fork)'">
                </div>
                <div class="mb-2">
                    <label class="form-label text-secondary small fw-bold mb-2">Visibility</label>
                    <select name="visibility" class="form-select border-secondary border-opacity-50 text-white rounded-pill px-3 py-2 shadow-none focus-border-primary" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\'%3e%3cpath fill=\'none\' stroke=\'%236c757d\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2 5l6 6 6-6\'/%3e%3c/svg%3e');">
                        <option value="private">Private</option>
                        <option value="public">Public</option>
                    </select>
                    <div class="form-text text-secondary opacity-75 mt-2" style="font-size: 0.75rem;">Visibility of the NEW forked template.</div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" style="background-color: #ff4b2b; border-color: #ff4b2b;" onclick="submitFork()">Fork</button>
                <button type="button" class="btn btn-dark border border-secondary border-opacity-50 text-white rounded-pill px-4 fw-bold hover-bg-secondary" data-coreui-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Lab Modal -->
<div class="modal fade" id="createLabModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 overflow-hidden shadow-lg glass-modal-content" id="createLabForm">
            <div style="height: 4px; background: linear-gradient(90deg, #8b5cf6 0%, #a78bfa 100%); width: 100%;"></div>
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h4 class="modal-title fw-bold text-white">Create new template</h4>
            </div>
            <div class="modal-body px-4 py-4">
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold mb-2">Template Name *</label>
                    <input type="text" name="name" class="form-control border-secondary border-opacity-50 text-white rounded-pill px-3 py-2 shadow-none focus-border-primary" placeholder="e.g., Python Data Science" required>
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold mb-2">Type</label>
                    <select name="type" class="form-select border-secondary border-opacity-50 text-white rounded-pill px-3 py-2 shadow-none focus-border-primary" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\'%3e%3cpath fill=\'none\' stroke=\'%236c757d\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2 5l6 6 6-6\'/%3e%3c/svg%3e');">
                        <option value="machine">Machine</option>
                        <option value="challenge">Challenge</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label text-secondary small fw-bold mb-2">Visibility</label>
                    <select name="visibility" class="form-select border-secondary border-opacity-50 text-white rounded-pill px-3 py-2 shadow-none focus-border-primary" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\'%3e%3cpath fill=\'none\' stroke=\'%236c757d\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2 5l6 6 6-6\'/%3e%3c/svg%3e');">
                        <option value="private">Private</option>
                        <option value="public">Public</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" style="background-color: #8b5cf6; border-color: #8b5cf6;">Create</button>
                <button type="button" class="btn btn-dark border border-secondary border-opacity-50 text-white rounded-pill px-4 fw-bold hover-bg-secondary" data-coreui-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Trash Confirmation Modal -->
<div class="modal fade" id="trashConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 overflow-hidden shadow-lg glass-modal-content">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                    <i class='bx bx-trash text-danger'></i> <span id="trashModalTitle">Move to Trash?</span>
                </h5>
            </div>
            <div class="modal-body px-4 py-3">
                <p class="text-secondary mb-3" id="trashModalDesc">Are you sure you want to move this template to trash?</p>
                <div id="trashWarningBox" class="d-none rounded-3 p-3 mb-2" style="background-color: rgba(255,107,107,0.08); border: 1px solid rgba(255,107,107,0.2);">
                    <div class="d-flex align-items-start gap-2">
                        <i class='bx bx-error-circle text-danger fs-5 mt-1'></i>
                        <div>
                            <div class="text-danger fw-bold small mb-1">Instance is running</div>
                            <div class="text-secondary small">The container will be <strong>stopped</strong> and all active connections (SSH, Code Server, VPN) will be <strong>terminated</strong> before moving to trash.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end gap-2">
                <button type="button" class="btn rounded-pill px-4 fw-bold" id="trashConfirmBtn"
                    style="background-color: #ff4b2b; border-color: #ff4b2b; color: white;">
                    <i class='bx bx-trash me-1'></i> Move to Trash
                </button>
                <button type="button" class="btn btn-dark border border-secondary border-opacity-50 text-white rounded-pill px-4 fw-bold" data-coreui-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<style>
.hover-border-primary:hover {
    border-color: var(--cui-primary) !important;
}
.hover-bg-secondary:hover {
    background-color: rgba(255,255,255,0.05) !important;
}
.focus-border-primary:focus {
    border-color: var(--cui-primary) !important;
    box-shadow: 0 0 0 0.25rem rgba(var(--cui-primary-rgb), 0.25) !important;
}
</style>
