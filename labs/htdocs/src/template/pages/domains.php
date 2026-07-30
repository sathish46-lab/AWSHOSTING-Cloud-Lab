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
            <div class="col-auto m-auto">
                <div class="row">
                    <div class="col-auto">
                        <div class="btn-group">
                            <a class="btn btn-add-domain" data-coreui-toggle="modal" data-coreui-target="#addDomainModal">Add New Domain</a>
                            <button class="btn btn-info btn-help-domain rounded-end-pill px-3" data-coreui-toggle="tooltip" data-coreui-placement="top" title="How to use domains?">
                                <i class='bx bx-info-circle'></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="domains-limit-text text-center">
                        Limit for Tom Domains: <?= $tomDomainCount ?>/<?= $tomDomainLimit ?><br>
                        Limit for Custom Domains: Unlimited
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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