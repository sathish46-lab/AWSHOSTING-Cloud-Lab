<div class="col card-entrance" id="instance-card-<?= htmlspecialchars($slug) ?>" data-deploy-status="<?= htmlspecialchars($instance['deploy']['status'] ?? 'none') ?>" data-instance-hash="<?= htmlspecialchars($instanceHash) ?>">
    <div class="card border-0 shadow-sm instance-template-card" style="overflow: visible;">
        <div style="border-radius: 1rem; overflow: hidden; position: relative; min-height: 200px;">
            <div class="instance-template-card-bg" style="background-image: url('<?= htmlspecialchars($cover) ?>'), linear-gradient(135deg, <?= htmlspecialchars($bgColor) ?> 0%, rgba(0,0,0,0.35) 100%);"></div>
            <div class="instance-template-card-overlay"></div>

            <div class="position-relative d-flex flex-column justify-content-end p-3 h-100" style="z-index: 2;">
                <div class="d-flex align-items-center justify-content-between mb-5">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge instance-badge-tag <?= $visibility === 'public' ? 'badge-vis-public' : 'badge-vis-private' ?>"><?= htmlspecialchars($visibility) ?></span>
                        <span class="badge instance-badge-tag badge-type-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></span>
                        <span class="badge instance-badge-tag badge-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-link text-white p-0 border-0 bg-transparent" data-coreui-toggle="dropdown" aria-expanded="false" style="text-shadow: 0 1px 3px rgba(0,0,0,0.5);">
                            <i class='bx bx-dots-vertical-rounded fs-5'></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end border-0">
                            <li><a class="dropdown-item rounded-3 mb-1 px-2 py-1" href="/instances/<?= htmlspecialchars($instanceHash) ?>/configuration"><i class='bx bx-cog me-2 text-primary'></i>Configure</a></li>
                            <li><hr class="dropdown-divider border-secondary border-opacity-25 my-1"></li>
                            <li><a class="dropdown-item text-danger rounded-3 px-2 py-1" href="javascript:void(0)" onclick="trashInstance('<?= htmlspecialchars($slug) ?>')"><i class='bx bx-trash me-2'></i>Delete</a></li>
                        </ul>
                    </div>
                </div>

                <h6 class="fw-bold theme-text mb-0">
                    <?= htmlspecialchars($name) ?><?= !empty($forked_from) ? ' (fork)' : '' ?>
                </h6>

                <div class="d-flex align-items-center justify-content-between gap-2 small mb-1 mt-3">
                    <div class="d-flex align-items-center gap-2">
                        <span><?= htmlspecialchars($image) ?></span>
                        <span><?= htmlspecialchars($tplKey) ?></span>
                    </div>
                    <span class="text-nowrap">updated <?= htmlspecialchars($updatedLabel) ?></span>
                </div>

                <div class="d-flex gap-2">
                    <a href="/instances/<?= htmlspecialchars($instanceHash) ?>/configuration" class="btn instance-action-btn btn-sm rounded-pill px-2 fw-bold flex-fill text-center">
                        <i class='bx bx-cog'></i> Config
                    </a>
                    <a href="/instances/<?= htmlspecialchars($instanceHash) ?>/files" class="btn instance-action-btn btn-sm rounded-pill px-2 fw-bold flex-fill text-center">
                        <i class='bx bx-folder'></i> Files
                    </a>
                    <a href="/instances/<?= htmlspecialchars($instanceHash) ?>/deployments" class="btn instance-action-btn btn-sm rounded-pill px-2 fw-bold flex-fill text-center">
                        <i class='bx bx-rocket'></i> Deploy
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
