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
    } elseif ($fullHash === $user->getLabHash('gui_essentials')) {
        $labType = 'gui_essentials';
    }

    if (!$labData) {
        $status = 'not_deployed';
    } else {
        $labType = $labData['lab_type'] ?? $labType;
        $status = $labData['status'] ?? 'offline';
    }
    
    // Define isRunning for the status dot and buttons
    $isRunning = ($status === 'running');
    
    // Determine init script path based on lab type
    $initScriptPath = "/home/{$currentUsername}/init.sh";
    $initScriptName = "init.sh";
    if ($labType === 'minio') {
        $initScriptPath = "/home/{$currentUsername}/.init/minio.sh";
        $initScriptName = "minio.sh";
    } elseif ($labType === 'docker_lab') {
        $initScriptPath = "/home/{$currentUsername}/.init/docker.sh";
        $initScriptName = "docker.sh";
    } elseif ($labType === 'n8n') {
        $initScriptPath = "/home/{$currentUsername}/.init/n8n.sh";
        $initScriptName = "n8n.sh";
    }

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
            'action'  => 'Launch',
            'action_icon' => 'bx-network-chart'
        ],
        'docker_lab' => [
            'title'   => 'Tom Docker Lab',
            'desc'    => 'Ubuntu 24.10 environment equipped with full Docker-in-Docker capabilities.',
            'icon'    => 'bxl-docker',
            'color'   => '#2496ed',
            'action'  => 'Code',
            'action_icon' => 'bx-code-alt'
        ],
        'gui_essentials' => [
            'title'   => 'GUI Essentials Lab',
            'desc'    => 'Ubuntu 24.10 with XFCE4 desktop, VNC GUI access, and code-server.',
            'icon'    => 'bx-desktop',
            'color'   => '#8b5cf6',
            'action'  => 'VNC',
            'action_icon' => 'bx-desktop'
        ]
    ];

    if (!isset($uiConfigs[$labType])) {
        $labTypeError = $labType;
        $cfg = [
            'title'   => 'Error: Unknown Lab Type',
            'desc'    => 'Lab type "' . $labTypeError . '" is not configured.',
            'icon'    => 'bx-error-circle',
            'color'   => '#dc3545',
            'action'  => 'N/A',
            'action_icon' => 'bx-error'
        ];
    } else {
        $cfg = $uiConfigs[$labType];
    }
    $creds = $labData['credentials'] ?? null;
    $deviceIp = isset($labData['internal_ip']) ? $labData['internal_ip'] : "0.0.0.0";
    $sshCommand = ($isRunning && isset($creds['tunnel_ip'])) ? "ssh " . $currentUsername . "@" . $creds['tunnel_ip'] : "#";
    $sudoPass = $creds['su_pass'] ?? $creds['password'] ?? "********";
    // Handle staged preferences for input fields
    $staged = $labData['staged_preferences'] ?? [];
    $stagedPasswordNames = [];
    
    if (isset($staged['su_pass']) && $staged['su_pass'] !== $sudoPass) {
        $stagedPasswordNames[] = '<span class="fw-bold text-cyan">Sudo Password</span>';
    }
    $sudoPassInput = $staged['su_pass'] ?? '';

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

<script>
    window.SESSION_HASH = "<?= $fullHash ?>";
    window.LAB_USER = "<?= htmlspecialchars($currentUsername) ?>";
    window.CODE_SERVER_URL = "<?= $creds['code_server_url'] ?? '' ?>";
    // CRITICAL: Set the global lab type
    window.LAB_TYPE = "<?= $labType ?>"; 
    // Inject Lab Config for JS Access
    window.LAB_CONFIG = <?= json_encode($labConfig) ?>;
    // CRITICAL: Inject cross-lab domain usage map
    window.DOMAIN_USAGE_MAP = <?= json_encode($domainUsageMap) ?>;
</script>

<?php 
    $current_page = 'preferences';
    include __DIR__ . '/partials/lab_header.php'; 
?>

<!-- Modals moved to lab_modals.php -->
<?php if (empty($labTypeError)): ?>
    <?php 
        $showAlwaysOn      = \TomLabs\Labs\LabTemplateConfig::supportsFeature($labType, 'always_on');
        $showHttpProxies   = \TomLabs\Labs\LabTemplateConfig::supportsFeature($labType, 'http_proxies'); 
        $showStartupScript = \TomLabs\Labs\LabTemplateConfig::supportsFeature($labType, 'startup_script');
    ?>
    <div class="container-fluid py-3 px-3">
        <div class="row g-2">
            <div class="col-lg-6">
                 <div class="card border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h6 class="fw-bold mb-0">Security & Access <span class="small text-muted ms-1">Passwords</span></h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-4">
                            <label class="small fw-bold text-secondary mb-2 text-uppercase ls-1">Sudo Password</label>
                            <div class="input-group p-1 bg-dark bg-opacity-50 rounded-pill border border-secondary border-opacity-25">
                                <span class="input-group-text bg-transparent border-0 text-secondary ps-3"><i class='bx bx-key'></i></span>
                                <input type="password" id="sudo-pass-input" class="form-control bg-transparent border-0 text-white fw-bold font-monospace" value="<?= htmlspecialchars($sudoPassInput) ?>" placeholder="Auto-generate like <?= htmlspecialchars($currentUsername) ?>@098">
                                <div class="d-flex align-items-center gap-1 pe-1">
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="togglePasswordVisibility('sudo-pass-input', this)">
                                        <i class='bx bx-hide fs-5'></i>
                                    </button>
                                    <div class="vr bg-secondary opacity-25 pref-vr-line"></div>
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="generateNewPassword('sudo-pass-input')">
                                        <i class='bx bx-refresh fs-5'></i>
                                    </button>
                                    <div class="vr bg-secondary opacity-25 pref-vr-line"></div>
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="copyFromInput('sudo-pass-input')">
                                        <i class='bx bx-copy fs-5'></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-text small opacity-50 mt-2 ms-2"><i class='bx bx-info-circle me-1'></i>Used for root access within the terminal. Leave blank to auto-generate a fresh one on each redeploy.</div>
                        </div>

                        <?php 
                            $codeServerPass = $creds['code_server_pass'] ?? $creds['password'] ?? null;
                            $showCodeServerPass = $codeServerPass && $labType !== 'gui_essentials';
                            if($showCodeServerPass): 
                                $codeServerPassInput = $staged['code_server_pass'] ?? '';
                                if (isset($staged['code_server_pass']) && $staged['code_server_pass'] !== $codeServerPass) {
                                    $stagedPasswordNames[] = '<span class="fw-bold text-cyan">Code-Server Password</span>';
                                }
                        ?>
                        <div class="mb-4">
                            <label class="small fw-bold text-secondary mb-2 text-uppercase ls-1">Code-Server Password</label>
                            <div class="input-group p-1 bg-dark bg-opacity-50 rounded-pill border border-secondary border-opacity-25">
                                <span class="input-group-text bg-transparent border-0 text-secondary ps-3"><i class='bx bx-code-alt'></i></span>
                                <input type="password" id="code-server-pass-input" class="form-control bg-transparent border-0 text-white fw-bold font-monospace" value="<?= htmlspecialchars($codeServerPassInput) ?>" placeholder="Auto-generate random password">
                                <div class="d-flex align-items-center gap-1 pe-1">
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="togglePasswordVisibility('code-server-pass-input', this)">
                                        <i class='bx bx-hide fs-5'></i>
                                    </button>
                                    <div class="vr bg-secondary opacity-25 pref-vr-line"></div>
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="generateNewPassword('code-server-pass-input')">
                                        <i class='bx bx-refresh fs-5'></i>
                                    </button>
                                    <div class="vr bg-secondary opacity-25 pref-vr-line"></div>
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="copyFromInput('code-server-pass-input')">
                                        <i class='bx bx-copy fs-5'></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-text small opacity-50 mt-2 ms-2"><i class='bx bx-info-circle me-1'></i>Password for the web-based VS Code environment. Leave blank to auto-generate a fresh one on each redeploy.</div>
                        </div>
                        <?php endif; ?>

                        <?php 
                            $vncPass = $creds['vnc_pass'] ?? null;
                            $hasVncField = \TomLabs\Labs\LabTemplateConfig::supportsFeature($labType, 'vnc_password');
                            if($hasVncField): 
                                $vncPassInput = $staged['vnc_pass'] ?? '';
                                if (isset($staged['vnc_pass']) && $staged['vnc_pass'] !== $vncPass) {
                                    $stagedPasswordNames[] = '<span class="fw-bold text-purple">VNC Password</span>';
                                }
                        ?>
                        <div class="mb-4">
                            <label class="small fw-bold text-secondary mb-2 text-uppercase ls-1">VNC Password</label>
                            <div class="input-group p-1 bg-dark bg-opacity-50 rounded-pill border border-secondary border-opacity-25">
                                <span class="input-group-text bg-transparent border-0 text-secondary ps-3"><i class='bx bx-desktop'></i></span>
                                <input type="password" id="vnc-pass-input" class="form-control bg-transparent border-0 text-white fw-bold font-monospace" value="<?= htmlspecialchars($vncPassInput) ?>" placeholder="Auto-generate random password">
                                <div class="d-flex align-items-center gap-1 pe-1">
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="togglePasswordVisibility('vnc-pass-input', this)">
                                        <i class='bx bx-hide fs-5'></i>
                                    </button>
                                    <div class="vr bg-secondary opacity-25 pref-vr-line"></div>
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="generateNewPassword('vnc-pass-input')">
                                        <i class='bx bx-refresh fs-5'></i>
                                    </button>
                                    <div class="vr bg-secondary opacity-25 pref-vr-line"></div>
                                    <button type="button" class="btn btn-link text-secondary p-0 d-flex align-items-center justify-content-center" 
                                            class="pref-icon-link"
                                            onclick="copyFromInput('vnc-pass-input')">
                                        <i class='bx bx-copy fs-5'></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-text small opacity-50 mt-2 ms-2"><i class='bx bx-info-circle me-1'></i>Password for KasmVNC desktop login. Leave blank to auto-generate a fresh one on each redeploy.</div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($stagedPasswordNames)): ?>
                        <?php $changedNamesStr = implode(' and ', $stagedPasswordNames); ?>
                        <div class="d-flex align-items-center p-3 mb-0 mt-3 border rounded-3 pref-warning-box">
                            <i class='bx bx-error-circle fs-4 me-3 text-warning'></i>
                            <div class="small text-warning fw-medium pref-warning-text">
                                Your <?= $changedNamesStr ?> is not updated in your lab. You need to redeploy for changes to take effect. The currently active password is shown in your dashboard.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>


             <div class="col-lg-6">
                 <div class="card border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h6 class="fw-bold mb-0">Lab Configuration <span class="small text-muted ms-1">Environment</span></h6>
                    </div>
                    <div class="card-body p-4">
                        <!-- Lab configuration Load place  -->
                        <div class="d-flex flex-column gap-3">
                            <?php foreach($labConfig['fields'] as $field): ?>
                                <div class="row align-items-center">
                                    <div class="col-4 text-body-emphasis small fw-bold">
                                        <?php if(isset($field['icon'])): ?>
                                            <i class='bx <?= $field['icon'] ?> me-1'></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($field['label']) ?>
                                    </div>
                                    <div class="col-8">
                                        <?php if($field['type'] === 'link'): ?>
                                            <a href="<?= htmlspecialchars($field['value']) ?>" target="_blank" class="text-decoration-none small fw-bold">
                                                <?= htmlspecialchars($field['value']) ?> <i class='bx bx-link-external ms-1'></i>
                                            </a>
                                        <?php else: ?>
                                            <div class="input-group input-group-sm">
                                                <input type="<?= $field['type'] === 'password' ? 'password' : 'text' ?>" 
                                                    class="form-control rounded-pill border-secondary bg-body-tertiary text-body px-3 <?= isset($field['mono']) ? 'font-monospace' : '' ?>" 
                                                    value="<?= htmlspecialchars($field['value']) ?>" 
                                                    readonly class="input-readonly-opacity">
                                                
                                                <?php if(isset($field['copy']) && $field['copy']): ?>
                                                    <button class="btn btn-outline-secondary ms-2 rounded-pill px-3" 
                                                            data-copy="<?= htmlspecialchars($field['value']) ?>">
                                                        <i class='bx bx-copy'></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <!-- Side-by-Side: HTTP Proxies & Lifecycle Section -->
        <?php if ($showAlwaysOn || $showHttpProxies): ?>
            <?php if ($showAlwaysOn): ?>
            <!-- Left Side: Lifecycle -->
            <div class="col-md-<?= ($showAlwaysOn && $showHttpProxies) ? '5' : '12' ?>">
                <div class="card border-0 shadow-sm blur rounded-4 pref-card-full">
                    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-2">
                        <h6 class="fw-bold mb-1 text-uppercase ls-1 small">Lifecycle</h6>
                    </div>
                    <div class="card-body p-4 pt-2 d-flex flex-column justify-content-between">
                        <div>
                            <div class="form-check form-switch mb-3">
                                <?php $alwaysOn = ($labData && isset($labData['always_on'])) ? (bool)$labData['always_on'] : false; ?>
                                <input class="form-check-input pref-checkbox-lg" type="checkbox" id="always-on-toggle" <?= $alwaysOn ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold small" for="always-on-toggle">Keep this instance running (Always-on)</label>
                            </div>
                            <p class="text-body-secondary small mb-0 pref-desc-sm">
                                When enabled, a background worker monitors this lab and automatically restarts it within ~10 minutes if it stops. The instance will not auto-expire.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($showHttpProxies): ?>
            <!-- Right Side: HTTP Proxies -->
            <div class="col-md-<?= ($showAlwaysOn && $showHttpProxies) ? '7' : '12' ?>">
                <div class="card border-0 shadow-sm blur rounded-4 pref-card-full">
                    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-2">
                        <h6 class="fw-bold mb-1">HTTP Proxies</h6>
                        <p class="small text-muted mb-0">Reverse-proxy ports to your domains over HTTP. TLS is terminated at the edge.</p>
                    </div>
                    <div class="card-body p-4 pt-2">
                        <!-- Headers -->
                        <div class="row align-items-center mb-2 text-muted small fw-bold d-none d-md-flex px-1">
                            <div class="col-md-4">Local Port</div>
                            <div class="col-md-7">Target Domain</div>
                            <div class="col-md-1"></div>
                        </div>

                        <div id="http-proxies-list">
                            <?php
                                $httpProxies = [];
                                if ($labData && isset($labData['http_proxies'])) {
                                    $httpProxies = (array)$labData['http_proxies'];
                                }
                                // Fetch user's domains for the select dropdown
                                $userDomains = [];
                                $domainsCursor = $db->domains->find(['user_id' => $user->getUserId()]);
                                foreach ($domainsCursor as $d) {
                                    $userDomains[] = (string)$d['domain'];
                                }
                                
                                if (empty($httpProxies)):
                            ?>
                            <div class="row align-items-center mb-3 proxy-row" data-index="0">
                                <label class="col-sm-4 small fw-bold text-secondary">Port & Domains</label>
                                <div class="col-sm-8">
                                    <div class="row g-2">
                                        <div class="col-md-4 col-12 mb-2 mb-md-0">
                                            <input type="number" name="proxy_port[]" class="form-control bg-body-tertiary rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-port" placeholder="Port (e.g. 8080)" min="1" max="65535">
                                        </div>
                                        <div class="col-md-6 col-10">
                                            <select name="proxy_domain[]" class="form-select bg-body-tertiary rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-domain-select">
                                                <option value="">Select Domain...</option>
                                                <?php foreach ($userDomains as $ud): ?>
                                                    <option value="<?= htmlspecialchars($ud) ?>"><?= htmlspecialchars($ud) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 col-2 d-flex justify-content-end">
                                            <button type="button" class="btn rounded-circle d-flex align-items-center justify-content-center p-0 btn-remove-proxy border-secondary border-opacity-25 bg-body-tertiary btn-remove-proxy-icon" onclick="removeProxyRow(this)">
                                                <i class='bx bx-trash'></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                                <?php foreach ($httpProxies as $idx => $proxy): ?>
                                <div class="row align-items-center mb-3 proxy-row" data-index="<?= $idx ?>">
                                    <label class="col-sm-4 small fw-bold text-secondary">Port & Domains</label>
                                    <div class="col-sm-8">
                                        <div class="row g-2">
                                            <div class="col-md-4 col-12 mb-2 mb-md-0">
                                                <input type="number" name="proxy_port[]" class="form-control bg-body-tertiary rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-port" placeholder="Port (e.g. 8080)" min="1" max="65535" value="<?= (int)($proxy['port'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6 col-10">
                                                <select name="proxy_domain[]" class="form-select bg-body-tertiary rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-domain-select">
                                                    <option value="">Select Domain...</option>
                                                    <?php foreach ($userDomains as $ud): ?>
                                                        <option value="<?= htmlspecialchars($ud) ?>" <?= ((string)($proxy['domain'] ?? '') === $ud) ? 'selected' : '' ?>><?= htmlspecialchars($ud) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-2 d-flex justify-content-end">
                                                <button type="button" class="btn rounded-circle d-flex align-items-center justify-content-center p-0 btn-remove-proxy border-secondary border-opacity-25 bg-body-tertiary btn-remove-proxy-icon" onclick="removeProxyRow(this)">
                                                    <i class='bx bx-trash'></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="mt-2 row">
                            <div class="col-sm-4"></div>
                            <div class="col-sm-8">
                                <button type="button" class="btn rounded-pill px-4 py-1.5 d-inline-flex align-items-center gap-2" style="border: 1px solid #22c55e; color: #22c55e; background: transparent; font-size: 0.9rem;" onclick="addProxyRow()">
                                    <i class='bx bx-message-square-add'></i> Add HTTP Proxy
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($showStartupScript): ?>
        <!-- Startup Script Section -->
            <div class="col-12">
                <div class="card border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-start">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class='bx bx-terminal text-muted'></i>
                                <span class="fw-bold small">Startup Script</span>
                                <span class="text-muted small font-monospace"><?= htmlspecialchars($initScriptPath) ?></span>
                            </div>
                        </div>
                        <span class="text-muted small fst-italic">Runs as root on every (re)deploy</span>
                    </div>
                    <div class="card-body p-4 pt-2">
                        <?php
                            $initScript = '';
                            if ($labData && isset($labData['init_script'])) {
                                $initScript = (string)$labData['init_script'];
                            }
                            if (empty($initScript)) {
                                $initScript = "#!/bin/bash\n";
                            }
                        ?>
                        <div class="position-relative">
                            <!-- CodeMirror Dependencies -->
                            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
                            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/dracula.min.css">
                            <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
                            <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/shell/shell.min.js"></script>

                            <textarea id="init-script-editor" class="form-control font-monospace bg-dark text-white border-0 rounded-3 p-3 pref-script-editor" rows="10"><?= htmlspecialchars($initScript) ?></textarea>

                            <script>
                                document.addEventListener("DOMContentLoaded", function() {
                                    // Slight delay to ensure DOM is fully ready when injected dynamically
                                    setTimeout(function() {
                                        var editorArea = document.getElementById("init-script-editor");
                                        if (editorArea && typeof CodeMirror !== 'undefined') {
                                            var isLight = document.documentElement.getAttribute('data-coreui-theme') === 'light';
                                            var activeTheme = isLight ? "default" : "dracula";
                                            
                                            window.initScriptEditor = CodeMirror.fromTextArea(editorArea, {
                                                mode: "shell",
                                                theme: activeTheme,
                                                lineNumbers: true,
                                                matchBrackets: true,
                                                viewportMargin: Infinity
                                            });
                                            
                                            var wrapper = window.initScriptEditor.getWrapperElement();
                                            wrapper.style.borderRadius = "8px";
                                            wrapper.style.fontSize = "13.5px";
                                            wrapper.style.padding = "10px 0";
                                            wrapper.style.fontFamily = "var(--bs-font-monospace)";
                                            wrapper.style.border = "1px solid var(--cui-border-color, rgba(255,255,255,0.1))";
                                            
                                            // Watch for light/dark mode toggles
                                            var themeObserver = new MutationObserver(function(mutations) {
                                                mutations.forEach(function(mutation) {
                                                    if (mutation.attributeName === 'data-coreui-theme') {
                                                        var nowLight = document.documentElement.getAttribute('data-coreui-theme') === 'light';
                                                        window.initScriptEditor.setOption("theme", nowLight ? "default" : "dracula");
                                                    }
                                                });
                                            });
                                            themeObserver.observe(document.documentElement, { attributes: true });
                                        }
                                    }, 200);
                                });
                            </script>
                        </div>
                        <div class="d-flex justify-content-end mt-2">
                            <button type="button" class="btn btn-sm rounded-pill px-3 d-inline-flex align-items-center gap-1 btn-pref-run" onclick="runInitScript()">
                                <i class='bx bx-play'></i> Run now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Save / Apply Buttons -->
            <div class="col-12">
                <div class="card border-0 shadow-sm blur rounded-4 p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-warning rounded-circle d-flex align-items-center justify-content-center pref-warning-icon-wrapper">
                                <i class='bx bx-info-circle fs-4'></i>
                            </div>
                            <div class="pref-note-max-w">
                                <h6 class="mb-1 fw-bold text-warning pref-note-title">Fast Apply Note</h6>
                                <p class="text-body-secondary mb-0 small pref-note-desc">
                                    Applying preferences updates HTTP proxy routes and runs <code class="text-warning font-monospace px-1.5 py-0.5 rounded"><?= htmlspecialchars($initScriptName) ?></code> immediately. This action does not perform a slow, full container redeployment.
                                </p>
                            </div>
                        </div>
                        <div class="d-flex gap-3 mt-3 mt-md-0 align-self-end align-self-md-center">
                            <button type="button" id="btn-save-preferences" class="btn btn-secondary rounded-pill px-4 py-2 d-inline-flex align-items-center gap-2 fw-bold btn-pref-save" onclick="savePreferences()">
                                <i class='bx bx-save'></i> Save Preferences
                            </button>
                            <button type="button" id="btn-apply-redeploy" class="btn rounded-pill px-4 py-2 d-inline-flex align-items-center gap-2 fw-bold btn-pref-apply" onclick="applyAndRedeploy()">
                                <i class='bx bxs-zap'></i> Fast Apply Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div> <!-- Close row g-2 -->
    </div> <!-- Close container-fluid -->
<?php else: ?>
    <!-- Empty state for unconfigured lab type -->
    <div class="container-fluid py-5 px-3 text-center">
        <div class="card border-0 shadow-sm blur rounded-4 mx-auto" style="max-width: 500px;">
            <div class="card-body p-5">
                <i class="bx bx-error-circle text-danger fs-1 mb-3"></i>
                <h5 class="fw-bold text-white mb-2">Lab Type Not Available</h5>
                <p class="text-muted mb-0">The lab type <code class="text-info"><?= htmlspecialchars($labTypeError) ?></code> is not configured or available.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Inject user domains as JS array for dynamic proxy rows -->
<script>
    window.USER_DOMAINS = <?= json_encode($userDomains) ?>;
    window.EXISTING_PROXIES = <?= json_encode($httpProxies) ?>;
</script>

<?php include __DIR__ . '/partials/lab_modals.php'; ?>
<?php include __DIR__ . '/partials/server_logs.php'; ?>