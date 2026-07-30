<div class="col-12 col-md-4 domain-card-wrapper card-entrance" id="domain-card-<?= $d['_id'] ?>">
    <div class="card rounded-4 overflow-hidden border-0 blur h-100">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-0">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="fw-bold m-0 text-truncate domain-card-title">
                        <?= htmlspecialchars(explode('.', $d['domain'])[0]) ?>
                    </h5>
                    <?php if(strtolower($d['type']) == 'tom'): ?>
                        <span class="badge bg-primary rounded-pill badge-domain-type">Tom Lab</span>
                    <?php else: ?>
                        <span class="badge bg-secondary rounded-pill badge-domain-type">Custom</span>
                    <?php endif; ?>
                </div>
                
                <div class="dropdown">
                    <button class="action-dots p-0 opacity-50 shadow-none border-0 d-flex align-items-center justify-content-center" 
                            data-coreui-toggle="dropdown" 
                            >
                        <i class='bx bx-dots-vertical-rounded fs-4' ></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" >
                        <li>
                            <a class="dropdown-item rounded-3 mb-1 px-2 py-1" href="javascript:void(0)" onclick="if(window.TomNotify) window.TomNotify.show('Manage features coming soon!', 'Info', 'info', 3000);" >
                                <i class='bx bx-cog me-2 text-primary'></i> Manage
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item text-danger rounded-3 px-2 py-1" href="javascript:void(0)" 
                                onclick="deleteDomain('<?= $d['_id'] ?>', '<?= htmlspecialchars($d['domain']) ?>')">
                                <i class='bx bx-trash me-2'></i> Delete
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="d-flex gap-1 flex-wrap mb-2">
                <?php if ($d['verified']): ?>
                    <span class="badge bg-success rounded-pill fw-bold text-lowercase">verified</span>
                <?php else: ?>
                    <span class="badge bg-warning rounded-pill fw-bold text-lowercase">unverified</span>
                <?php endif; ?>
                
                <?php 
                    // Add usage status badge
                    if (!isset($dm)) {
                        require_once __DIR__ . '/../../lib/core/DomainManager.class.php';
                        $dm = new DomainManager();
                    }
                    $usageInfo = $dm->getDomainUsage(Session::getUser()->getUserId(), $d['domain']);
                    if ($usageInfo && isset($usageInfo['status']) && $usageInfo['status'] === 'running'): 
                ?>
                    <span class="badge bg-primary rounded-pill fw-bold text-lowercase">in use</span>
                <?php else: ?>
                    <span class="badge bg-warning rounded-pill fw-bold text-lowercase">orphaned</span>
                    <span class="badge bg-danger rounded-pill fw-bold text-lowercase">not in use</span>
                <?php endif; ?>
                
                <?php
                    // Check if this domain has a valid SSL certificate
                    if (!isset($certs)) {
                        $certs = Session::get('ssl_certificates') ?: [];
                    }
                    $certIndex = -1;
                    $hasValidSsl = false;
                    foreach ($certs as $idx => $cert) {
                        if (in_array($d['domain'], $cert['sans'])) {
                            $certIndex = $idx;
                            $hasValidSsl = $cert['is_valid'];
                            break;
                        }
                    }
                    if ($certIndex >= 0):
                ?>
                    <a href="/ssl" class="badge <?= $hasValidSsl ? 'bg-success' : 'bg-danger' ?> rounded-pill fw-bold text-decoration-none text-lowercase">
                        ssl <?= $hasValidSsl ? 'valid' : 'invalid' ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="domain-details-area">
                <div class="mb-1">
                    <b >Common Name:</b> 
                    <span class="ms-1"><?= htmlspecialchars(explode('.', $d['domain'])[0]) ?></span>
                </div>
                
                <div class="mb-1">
                    <b >Domain Name:</b> 
                    <span class="ms-1 domain-name-val"><?= htmlspecialchars($d['domain']) ?></span>
                </div>
                
                <div class="mb-1">
                    <b >A Record:</b> 
                    <span class="ms-1"><?php echo $dm->getServerIP(); ?></span>
                </div>
                
                <div class="mb-1">
                    <b >Service:</b> 
                    <span class="ms-1"><?= (strtolower($d['type']) == 'tom') ? 'Tom Lab' : 'Custom' ?></span>
                </div>
                
                <?php 
                    if ($usageInfo && isset($usageInfo['status']) && $usageInfo['status'] === 'running'): 
                ?>
                    <div class="mb-1">
                        <b >Currently Used:</b> 
                        <span class="text-success ms-1"><?= htmlspecialchars($usageInfo['usage']) ?> (<?= htmlspecialchars($usageInfo['lab_type']) ?> lab)</span>
                    </div>
                <?php else: ?>
                    <div class="mb-1">
                        <b >Currently Used:</b> 
                        <span class="text-secondary ms-1 opacity-75">None</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
