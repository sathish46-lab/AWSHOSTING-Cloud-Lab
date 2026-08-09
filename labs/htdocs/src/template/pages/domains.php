<?php
// Auto-refresh verification for custom domains on page load
$domainManager = new DomainManager();
$user = Session::getUser();
if ($user) {
    $domainManager->refreshUserDomains($user->getUserId());
}
$certs = Session::get('ssl_certificates') ?: [];
$serverIP = $domainManager->getServerIP();

// Calculate domain limits
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$tomDomainCount = $db->domains->countDocuments([
    'user_id' => $user->getUserId(),
    'type' => ['$ne' => 'custom']
]);
$tomDomainLimit = 20;

// Helper function to show time ago
function timeAgo($timestamp) {
    if (empty($timestamp)) return 'Never';
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $timestamp);
}
?>
<div id="domains-banner" class="blur banner mb-3 rounded-0">
    <div class="container-fluid px-4">
        <div class="row align-items-center py-3">
            <div class="col-lg-8 col-auto me-auto">
                <div class="p-3">
                    <h3 class="domains-header-title"><strong>Domains</strong></h3>
                    <p class="domains-header-desc">
                        My Domains is a section where you can reserve stylish Tom Lab Domains or register 3rd party domains to access your lab over Internet.
                        In case of 3rd party domains, you will have to manually modify the DNS records of your domain to point to your lab. Domains are used to
                        show your work to the WWW over SSL seemlessly. Your online presence makes you powerful 🔥
                    </p>
                </div>
            </div>
            <div class="col-auto m-auto text-center">
                <div class="btn-group">
                    <a class="btn btn-add-domain" data-coreui-toggle="modal" data-coreui-target="#addDomainModal">Add New Domain</a>
                    <button class="btn btn-info btn-help-domain rounded-end-pill px-3" data-coreui-toggle="tooltip" data-coreui-placement="top" title="How to use domains?">
                        <i class='bx bx-info-circle'></i>
                    </button>
                </div>
                <div class="mt-1" style="font-size: 0.8rem; color: var(--cui-secondary-color);">
                    Limit for Tom Domains: <?= $tomDomainCount ?>/<?= $tomDomainLimit ?><br>
                    Limit for Custom Domains: Unlimited
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Domain Manage Modal (outside banner) -->
<div class="modal fade" id="domainManageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content blur shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h4 class="modal-title fw-bold" id="domainManageTitle">Domain Details</h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-2" id="domainManageBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-info" role="status"></div>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4 px-4">
                <button type="button" class="btn btn-secondary px-4 rounded-pill" data-coreui-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openDomainManage(domainId) {
    const titleEl = document.getElementById('domainManageTitle');
    const body = document.getElementById('domainManageBody');
    titleEl.textContent = 'Domain Details';
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-info" role="status"></div></div>';

    const modal = new coreui.Modal(document.getElementById('domainManageModal'));
    modal.show();

    fetch('/api/domain/manage?domain_id=' + encodeURIComponent(domainId))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<p class="text-danger">' + (data.error || 'Failed to load domain details.') + '</p>';
                return;
            }

            const d = data.domain;
            const u = data.usage;
            const s = data.ssl;
            let html = '';

            // Domain header
            html += '<div class="d-flex align-items-center gap-3 mb-3">';
            html += '<div class="flex-grow-1">';
            html += '<h5 class="fw-bold mb-1"><a href="https://' + d.name + '" target="_blank" rel="noopener" class="text-white text-decoration-none">' + d.name + '</a></h5>';
            html += '<div class="d-flex gap-2 flex-wrap">';
            html += '<span class="badge ' + (d.type === 'tom' ? 'bg-primary' : 'bg-secondary') + ' rounded-pill fw-bold">' + (d.type === 'tom' ? 'Tom Lab' : 'Custom') + '</span>';
            html += '<span class="badge ' + (d.verified ? 'bg-success' : 'bg-warning') + ' rounded-pill fw-bold">' + (d.verified ? 'verified' : 'unverified') + '</span>';
            if (u && u.status === 'running') {
                html += '<span class="badge bg-primary rounded-pill fw-bold">in use</span>';
            }
            if (s) {
                html += '<span class="badge ' + (s.is_valid ? 'bg-success' : 'bg-danger') + ' rounded-pill fw-bold">ssl ' + (s.is_valid ? 'valid' : 'invalid') + '</span>';
            }
            html += '</div></div></div>';

            // Domain info card
            html += '<div class="ssl-inner-card rounded-4 mb-3">';
            html += '<div class="fw-bold small text-secondary mb-2" style="text-transform:uppercase; letter-spacing:0.5px;">Domain Information</div>';
            const domainInfo = [
                { icon: 'bx-globe', label: 'Domain', value: d.name, color: 'text-info' },
                { icon: 'bx-server', label: 'A Record', value: d.server_ip },
                { icon: 'bx-check-shield', label: 'Verified', value: d.verified ? 'Yes' : 'No', color: d.verified ? 'text-success' : 'text-warning' },
                { icon: 'bx-time', label: 'Last Checked', value: d.last_checked ? new Date(d.last_checked * 1000).toLocaleString() : 'Never' },
                { icon: 'bx-calendar', label: 'Created', value: d.created_at ? new Date(d.created_at * 1000).toLocaleDateString() : 'Unknown' }
            ];
            domainInfo.forEach(item => {
                html += '<div class="d-flex align-items-center gap-2 mb-1">';
                html += '<i class="bx ' + item.icon + ' text-secondary"></i>';
                html += '<strong class="text-secondary me-1">' + item.label + ':</strong>';
                html += '<span class="' + (item.color || '') + '">' + item.value + '</span>';
                html += '</div>';
            });
            html += '</div>';

            // Usage card
            html += '<div class="ssl-inner-card rounded-4 mb-3">';
            html += '<div class="fw-bold small text-secondary mb-2" style="text-transform:uppercase; letter-spacing:0.5px;">Usage</div>';
            if (u && u.status === 'running') {
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-desktop text-secondary"></i><strong class="text-secondary me-1">Service:</strong><span>' + (u.service || '-') + '</span></div>';
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-category text-secondary"></i><strong class="text-secondary me-1">Lab Type:</strong><span>' + (u.lab_type || '-') + '</span></div>';
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-hash text-secondary"></i><strong class="text-secondary me-1">Instance:</strong><span class="font-monospace small">' + (u.instance_hash || '-') + '</span></div>';
            } else {
                html += '<div class="d-flex align-items-center gap-2"><i class="bx bx-info-circle text-secondary"></i><span class="text-secondary">Not assigned to any running lab</span></div>';
            }
            html += '</div>';

            // SSL card
            html += '<div class="ssl-inner-card rounded-4 mb-3">';
            html += '<div class="fw-bold small text-secondary mb-2" style="text-transform:uppercase; letter-spacing:0.5px;">SSL Certificate</div>';
            if (s) {
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-check-shield text-secondary"></i><strong class="text-secondary me-1">Status:</strong><span class="' + (s.is_valid ? 'text-success' : 'text-danger') + '">' + (s.is_valid ? 'Valid' : 'Invalid/Expired') + '</span></div>';
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-server text-secondary"></i><strong class="text-secondary me-1">Resolver:</strong><span class="text-success">' + (s.resolver || '-') + '</span></div>';
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-calendar text-secondary"></i><strong class="text-secondary me-1">Issued:</strong><span>' + (s.issued || 'Unknown') + '</span></div>';
                html += '<div class="d-flex align-items-center gap-2 mb-1"><i class="bx bx-time-five text-secondary"></i><strong class="text-secondary me-1">Expires:</strong><span>' + (s.expires || 'Unknown');
                if (s.days_left !== null) {
                    const daysColor = s.days_left > 30 ? 'text-success' : (s.days_left > 7 ? 'text-warning' : 'text-danger');
                    html += ' <span class="' + daysColor + '">(' + s.days_left + ' days)</span>';
                }
                html += '</span></div>';
                html += '<div class="d-flex align-items-start gap-2 mb-1"><i class="bx bx-list-ul text-secondary mt-1"></i><strong class="text-secondary me-1">SANs:</strong><span class="small">' + (s.sans || []).join(', ') + '</span></div>';
            } else {
                html += '<div class="d-flex align-items-center gap-2"><i class="bx bx-shield-x text-secondary"></i><span class="text-secondary">No SSL certificate found for this domain</span></div>';
            }
            html += '</div>';

            // Actions
            html += '<div class="d-flex gap-2 flex-wrap mt-3">';
            html += '<button class="btn btn-outline-info btn-sm rounded-pill px-3 fw-bold" onclick="reverifyDomain(\'' + d.id + '\', \'' + d.name + '\')"><i class="bx bx-refresh me-1"></i> Re-verify DNS</button>';
            if (s) {
                html += '<button class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-bold" onclick="troubleshootDomainSSL(\'' + d.name + '\')"><i class="bx bx-wrench me-1"></i> Troubleshoot SSL</button>';
            }
            html += '<a href="https://' + d.name + '" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold"><i class="bx bx-link-external me-1"></i> Open Domain</a>';
            html += '</div>';

            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<p class="text-danger small">Network error: ' + err.message + '</p>';
        });
}

function reverifyDomain(domainId, domainName) {
    if (window.TomNotify) TomNotify.show('Re-verifying DNS for ' + domainName + '...', 'Verifying', 'info', 3000);

    fetch('/api/domain/verify_domain', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ domain_id: domainId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (window.TomNotify) TomNotify.show(data.message || 'Domain verified successfully!', 'Success', 'success', 3000);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            if (window.TomNotify) TomNotify.show(data.error || 'Verification failed.', 'Error', 'danger', 4000);
        }
    })
    .catch(() => {
        if (window.TomNotify) TomNotify.show('Network error during verification.', 'Error', 'danger', 4000);
    });
}

function troubleshootDomainSSL(domainName) {
    // Close manage modal, open SSL troubleshoot
    coreui.Modal.getInstance(document.getElementById('domainManageModal')).hide();
    setTimeout(() => {
        if (typeof SSLManager !== 'undefined') {
            SSLManager.troubleshoot(domainName);
        } else {
            window.location.href = '/ssl';
        }
    }, 400);
}
</script>

<div class="container-fluid px-4">
    <div class="row g-4 mb-4">
        <?php foreach (Session::get('user_domains') as $d): ?>
        <?php include __DIR__ . '/../partials/_domain_card.php'; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="addDomainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h4 class="modal-title fw-bold">Add Domain</h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>

            <div id="addDomainModalContent">
                <div class="modal-body p-5 text-center">
                    <i class="bx bx-loader-alt bx-spin text-primary spinner-loader-icon"></i>
                    <div class="mt-3 text-white opacity-75 fw-semibold tracking-widest uppercase loading-form-text">Loading form...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmDeleteDomainModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg rounded-4 border-0 blur glass-modal-content">
        <div class="modal-header border-0 pb-2">
            <h4 class="modal-title fw-bold m-0 modal-title-delete">Delete Domain</h4>
        </div>
        <div class="modal-body py-3 border-top border-bottom border-translucent">
            <p class="mb-0 opacity-75 modal-body-desc">
                You are about to delete a registered domain: <span id="deleteDomainModalName" class="text-info fw-bold font-monospace"></span>. 
                You will no longer be able to communicate via this domain.<br>
                Are you sure to continue?
            </p>
        </div>
        <div class="modal-footer border-0 pt-3 pb-1 d-flex justify-content-end gap-3">
            <button type="button" class="btn px-4 rounded-pill fw-bold border-0 shadow-sm btn-modal-cancel" data-coreui-dismiss="modal">Cancel</button>
            <button type="button" class="btn text-white px-4 rounded-pill fw-bold border-0 btn-modal-delete" id="confirmDeleteDomainBtn" onclick="confirmDeleteDomainAction()">Delete</button>
        </div>
    </div>
  </div>
</div>