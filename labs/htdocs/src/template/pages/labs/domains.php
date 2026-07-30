<?php
    $fullHash = Session::get('full_instance_hash');
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
    $inst = $db->machine_labs->findOne(['deploy.instance_hash' => $fullHash]);
    $labData = $inst ? ($inst['deploy'] ?? []) : null;
    
    // CRITICAL FIX: Define missing variables
    $user = Session::getUser();
    $currentUsername = $user->getUsername();

    $labType = 'essentials';
    if ($fullHash === $user->getLabHash('minio')) {
        $labType = 'minio';
    } elseif ($fullHash === $user->getLabHash('n8n')) {
        $labType = 'n8n';
    } elseif ($fullHash === $user->getLabHash('docker_lab')) {
        $labType = 'docker_lab';
    }

    if (!$labData) {
        $status = 'not_deployed';
    } else {
        $labType = $labData['lab_type'] ?? $labType;
        $status = $labData['status'] ?? 'offline';
    }
    
    // Define isRunning for the status dot and buttons
    $isRunning = ($status === 'running');

    // 2. UI CONFIGS
    $uiConfigs = [
        'essentials' => [
            'title'   => 'Essentials Lab',
            'desc'    => 'Ubuntu 24.10 environment for general development.',
            'icon'    => 'bxl-tux',
            'color'   => '#E95420',
            'action'  => 'Code',
            'action_icon' => 'bx-code-alt'
        ],
        'minio' => [
            'title'   => 'MinIO S3 Storage',
            'desc'    => 'MinIO is a high-performance, S3-compatible object storage solution for machine learning, analytics, and application data workloads, released under the GNU AGPL v3.0.',
            'icon'    => Session::cdn3('icons/minio_avatar_small.png'),
            // 'icon' => 'bxl-docker',
            'color'   => '#00a6e0',
            'action'  => ' Launch',
            'action_icon' => 'bx-cloud'
        ],
        'n8n' => [
            'title'   => 'n8n Workflow Automation',
            'desc'    => 'n8n is an extendable workflow automation tool that enables you to connect anything to everything via its open, fair-code model.',
            'icon'    => 'bx-git-repo-forked',
            'color'   => '#ea4b71',
            'action'  => ' Launch',
            'action_icon' => 'bx-network-chart'
        ],
        'docker_lab' => [
            'title'   => 'Tom Docker Lab',
            'desc'    => 'Ubuntu 24.10 environment equipped with full Docker-in-Docker capabilities.',
            'icon'    => 'bxl-docker',
            'color'   => '#2496ed',
            'action'  => 'Code',
            'action_icon' => 'bx-code-alt'
        ]
    ];

    $cfg = $uiConfigs[$labType] ?? $uiConfigs['essentials'];
    $creds = $labData['credentials'] ?? null;
    $deviceIp = isset($labData['internal_ip']) ? $labData['internal_ip'] : "0.0.0.0";
    $sshCommand = ($isRunning && isset($creds['tunnel_ip'])) ? "ssh " . $currentUsername . "@" . $creds['tunnel_ip'] : "#";
    $sudoPass = $creds['password'] ?? "********";

    // Lab configuration Load place
    $configData = (array)$labData;
    if (empty($configData['instance_hash'])) {
        $configData['instance_hash'] = $fullHash;
    }
    $labConfig = \TomLabs\Labs\LabTemplateConfig::getTemplate($labType, $configData, $currentUsername);
    
    // REUSABLE: Get domain usage map from DomainManager (works for ALL lab types)
    $dm = new DomainManager();
    $domainUsageMap = $dm->getDomainUsageMap($user->getUserId());
?>


<?php 
    $current_page = 'domains';
    include __DIR__ . '/partials/lab_header.php'; 
?>

<!-- Modals moved to lab_modals.php -->
    <div class="container-fluid py-3 p-0 px-3">
        <!-- Grid for Domains -->
        <div class="row row-cols-1 row-cols-md-3 g-4 align-items-start" id="masonry-area" data-masonry='{"percentPosition": true }'>
           <?php 
                // Filter DomainManager's map for this specific instance (includes http proxies)
                $instanceDomains = [];
                foreach ($domainUsageMap as $dom => $info) {
                    if (($info['instance_hash'] ?? '') === $fullHash) {
                       $instanceDomains[$dom] = $info;
                    }
                }
                if(!$isRunning || empty($instanceDomains)):
            ?>
                <div class="d-flex justify-content-center align-items-center vh-10 w-100">
                    <div class="card p-4 text-center border-0 rounded-4 blur shadow-sm empty-domains-card">
                        
                        <div class="mb-3 mx-auto">
                            <div class="empty-state-glow"></div>
                            <i class='bx bx-globe text-white opacity-50' ></i>
                        </div>

                        <h4 class="fw-bold text-white mb-3">No Domains Associated Yet</h4>
                        
                        <p class="text-secondary mb-2 mx-auto small empty-domains-text">
                            Deploy this lab to see associated domains here. Once deployed, your domains will be automatically configured and displayed on this page.
                        </p>

                        <button class="btn btn-lg rounded-pill px-4 py-1 fw-bold hover-scale text-white border-0 shadow-sm bg-gradient mt-2" 
                                onclick="handleDeploy(this, '<?= $labType ?>')">
                            <i class='bx <?= $isRunning ? 'bx-refresh' : 'bx-cloud-upload' ?> me-2'></i> <?= $isRunning ? 'Redeploy' : 'Deploy' ?> Now
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach($instanceDomains as $dom => $info): ?>
                <?php 
                    $usageStr = $info['usage'] ?? 'Public Exposure';
                    $isProxy = false;
                    $portStr = '';
                    $usageLabel = 'Port 80 Public';
                    $headerClass = 'bg-success-gradient';
                    $svgIcon = 'cil-globe-alt';

                    if (strpos($usageStr, 'HTTP Proxy') !== false) {
                        $isProxy = true;
                        $usageLabel = 'Your Proxy';
                        $headerClass = 'bg-primary-gradient';
                        $svgIcon = 'cil-share';
                        if (preg_match('/Port\s+(\d+)/', $usageStr, $matches)) {
                            $portStr = $matches[1];
                        }
                    } elseif (strpos($usageStr, 'VS Code Web') !== false) {
                        $usageLabel = 'VS Code Editor';
                        $headerClass = 'bg-info-gradient';
                        $svgIcon = 'cil-code';
                    } elseif (strpos($usageStr, 'MinIO') !== false || strpos($usageStr, 'S3 API') !== false) {
                        $usageLabel = $usageStr;
                        $headerClass = 'bg-warning-gradient';
                        $svgIcon = 'cil-hdd';
                    }
                    
                    $isCustom = (strpos($dom, 'tomweb') === false && strpos($dom, 'selfmade') === false && strpos($dom, 'zeal') === false);
                    $domainBadgeClass = $isCustom ? 'bg-warning' : 'bg-primary';
                    $domainBadgeLabel = $isCustom ? 'custom' : 'tomlab';
                ?>
                <div class="col">
                    <div class="card blur h-100">
                        <div class="card-header <?= $headerClass ?>">
                            <div class="d-flex flex-row align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    <svg class="icon me-2">
                                        <use xlink:href="/assets/icons/free.svg#<?= $svgIcon ?>"></use>
                                    </svg>
                                    <strong><?= htmlspecialchars($usageLabel) ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <h5 class="card-title mb-2">
                                    <a style="text-decoration: none; word-break: break-all;" target="_blank" href="https://<?= htmlspecialchars($dom) ?>">
                                        <?= htmlspecialchars($dom) ?>
                                    </a>
                                </h5>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <span class="badge <?= $domainBadgeClass ?>"><?= $domainBadgeLabel ?></span>
                                    <span class="badge bg-success">verified</span>
                                    <span class="badge bg-success">active</span>
                                </div>
                            </div>
                            <div class="small text-muted">
                                <div class="mb-1">
                                    <strong>Service:</strong>
                                    <br>
                                    <code>TomCloudLab</code>
                                </div>
                                <?php if ($isProxy && !empty($portStr)): ?>
                                <div class="mb-1">
                                    <strong>Port:</strong>
                                    <br>
                                    <code><?= htmlspecialchars($portStr) ?></code>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="row mt-3">
            <div class="col-12">
                <div class="card blur">
                    <div class="card-header">
                        <svg class="icon me-2">
                            <use xlink:href="/assets/icons/free.svg#cil-info"></use>
                        </svg>
                        <strong>Domain Information</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-2">Domain Types</h6>
                                <ul class="list-unstyled mb-0 list-line-16">
                                    <li class="mb-1">
                                        <span class="badge bg-success-gradient me-2">Port 80 Public</span>
                                        <span class="small">Port 80/443 (Essentials lab)</span>
                                    </li>
                                    <li class="mb-1">
                                        <span class="badge bg-primary-gradient me-2">VS Code Web</span>
                                        <span class="small">Code-server access</span>
                                    </li>
                                    <li class="mb-1">
                                        <span class="badge bg-info-gradient me-2">Public Expose Proxy</span>
                                        <span class="small">Lab services (n8n, MinIO, Node-RED)</span>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-2">How to Manage</h6>
                                <ul class="small mb-0 list-line-16">
                                    <li class="mb-1"><strong>Redeploy</strong> to change domains</li>
                                    <li class="mb-1">Tom Lab domains auto-configured with SSL</li>
                                    <li class="mb-1">Custom domains need DNS A record</li>
                                    <li class="mb-1"><a href="/domains">Domain Management</a> for more domains</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

<?php include __DIR__ . '/partials/lab_modals.php'; ?>
<?php include __DIR__ . '/partials/server_logs.php'; ?>

<script>
    window.SESSION_HASH = "<?= $fullHash ?>";
    window.onPageLoad(function() {
        var grid = document.querySelector('#masonry-area');
        if (grid && typeof Masonry !== 'undefined') {
            new Masonry(grid, {
                percentPosition: true
            });
        }
    });
</script>
