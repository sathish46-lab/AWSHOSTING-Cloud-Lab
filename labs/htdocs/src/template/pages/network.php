<?php
$resources = Session::get('network_resources', []); 
Session::addCustomJs('/assets/js/network.js');
?>

<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-4">
        <div class="row align-items-center py-3">
            <div class="col">
                <h1 class="fw-bold theme-text m-0 network-header-title">Network</h1>
                <p class="text-secondary opacity-75 mt-2 mb-0 network-header-desc">
                    My Network is a section where you can manage IP Address Reservation for your devices and labs.
                </p>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col">
                <ul class="nav nav-tabs lab-nav-tabs border-0" id="networkTabs">
                    <li class="nav-item">
                        <a class="nav-link active" href="/network">
                            <i class='bx bx-network-chart me-1'></i> IP Addresses
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/network/interfaces">
                            <i class='bx bx-link-alt me-1'></i> WG Interfaces
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
    <div class="row g-4 mb-4">
    <?php foreach ($resources as $res): ?>
    <div class="col-12 col-md-4 col-xl-3 card-entrance" id="ip-card-<?= str_replace('.', '-', $res['ip_addr']) ?>">
        <div class="card rounded-4 p-3 blur h-100">
            <div class="mb-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h5 class="fw-bold m-0 font-monospace ip-card-title"><?= htmlspecialchars($res['ip_addr']) ?></h5>
                    <span class="badge rounded-pill fw-bold text-white" style="font-size:10px; padding:4px 10px; background: rgba(255,255,255,0.1);">
                        <?= htmlspecialchars($res['iface'] ?? 'wg0') ?>
                    </span>
                </div>
                <?php if (!empty($res['tag'])): ?>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge rounded-pill fw-bold <?= htmlspecialchars($res['tag_bg']) ?> border-0 text-white" style="font-size:10px; padding:4px 10px;">
                        <?= htmlspecialchars($res['tag']) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <div class="mt-auto d-flex justify-content-end">
                <button class="btn btn-sm btn-danger fw-bold btn-ip-action rounded-pill"
                    onclick="releaseIp('<?= $res['ip_addr'] ?>', 'vpn', '<?= str_replace('.', '-', $res['ip_addr']) ?>', this)">
                    <i class='bx bx-trash-alt me-1'></i> Delete
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg rounded-4 border-0 blur glass-modal-content">
        <div class="modal-header border-0 pb-2">
            <h4 class="modal-title fw-bold m-0 modal-title-delete">Confirm Delete</h4>
        </div>
        <div class="modal-body py-3 border-top border-bottom border-translucent">
            <p class="mb-0 opacity-75 modal-body-desc">
                You are about to release the IP address: <span id="deleteModalIp" class="text-info fw-bold font-monospace"></span>. 
                It will become available for anyone to use.
            </p>
        </div>
        <div class="modal-footer border-0 pt-3 pb-1 d-flex justify-content-end gap-3">
            <button type="button" class="btn px-4 rounded-pill fw-bold border-0 shadow-sm btn-modal-cancel" data-coreui-dismiss="modal">Cancel</button>
            <button type="button" class="btn text-white px-4 rounded-pill fw-bold border-0 btn-modal-delete" id="confirmDeleteBtn" onclick="confirmReleaseIp()">Delete</button>
        </div>
    </div>
  </div>
</div>
