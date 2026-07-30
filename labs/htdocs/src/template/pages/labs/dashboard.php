<?php
    $fullHash = Session::get('full_instance_hash');
    $db = DatabaseConnection::getDefaultDatabase();

    // Query instances collection — deploy data lives in instances.deploy subdocument
    $inst = $db->machine_labs->findOne(
        ['deploy.instance_hash' => $fullHash],
        ['projection' => ['deploy' => 1, 'lab_type' => 1]]
    );
    if ($inst) {
        $labData = $inst['deploy'] ?? [];
        $labData['lab_type'] = $inst['lab_type'] ?? 'essentials';
    } else {
        $labData = null;
    }

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

    function timeAgo($time_ago) {
        $time_difference = time() - $time_ago;
        if ($time_difference < 1) { return 'just now'; }
        $condition = [
            12 * 30 * 24 * 60 * 60 => 'year',
            30 * 24 * 60 * 60      => 'month',
            24 * 60 * 60           => 'day',
            60 * 60                => 'hour',
            60                     => 'minute',
            1                      => 'second'
        ];
        foreach ($condition as $secs => $str) {
            $d = $time_difference / $secs;
            if ($d >= 1) {
                $t = round($d);
                return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
            }
        }
        return 'just now';
    }

    $activityLog = [];
    if (isset($labData['activity_log'])) {
        // Convert BSONArray to array if necessary, or use as is
        foreach ($labData['activity_log'] as $logItem) {
            $activityLog[] = (array)$logItem;
        }
    }
?>

<script>
    window.SESSION_HASH = "<?= $fullHash ?>";
    window.LAB_USER = "<?= htmlspecialchars($currentUsername) ?>";
    window.CODE_SERVER_URL = "<?= $creds['code_server_url'] ?? '' ?>";
    window.LAB_TYPE = "<?= $labType ?>";
</script>
<!-- DOMAIN_USAGE_MAP embedded in data attribute — not exposed as JS variable -->
<div id="lab-data-root"
     data-domain-usage="<?= htmlspecialchars(json_encode($domainUsageMap)) ?>"
     data-lab-config="<?= htmlspecialchars(json_encode($labConfig)) ?>"></div>

<?php 
    $current_page = 'dashboard';
    include __DIR__ . '/partials/lab_header.php'; 
?>

<!-- Modals moved to lab_modals.php -->
    <div class="container-fluid py-3 px-3">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card mb-4 border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h6 class="fw-bold mb-0 text-body-emphasis">Lab Information <span
                            class="small text-body-secondary ms-1">Readme</span></h6>
                    </div>
                    <div class="card-body p-4">
                        <?php if($isRunning && $creds): ?>
                            <p class="text-body-secondary small mb-4 animate__animated animate__fadeIn">
                                Access your lab environment using the credentials below. Ensure you are connected to VPN.
                            </p>

                            <!-- Lab configuration Load place  -->

                            <div class="d-flex flex-column gap-3 animate__animated animate__fadeIn">
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

                        <?php else: ?>
                            <p>This lab is not running, please deploy it to get the connection information.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h6 class="fw-bold mb-0">IO Stats <span class="small text-muted ms-1">Net and Block</span></h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-2">Net IO</div>
                                    <div class="fw-bold text-white mb-2" id="stat-net-io">0B / 0B</div>
                                    <div class="chart-container-40">
                                        <canvas id="chart-net-io"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-2">Block IO</div>
                                    <div class="fw-bold text-white mb-2" id="stat-block-io">0B / 0B</div>
                                    <div class="chart-container-40">
                                        <canvas id="chart-block-io"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-4 border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h6 class="fw-bold mb-0">
                        Container Load
                        <?php if ($isRunning): ?>
                            <span class="badge bg-success rounded-pill ms-2 pulse">Live</span>
                        <?php else: ?>
                            <span class="badge bg-danger rounded-pill ms-2">Offline</span>
                        <?php endif; ?>
                    </h6>

                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 text-start stat-card-inner">
                                    <div class="mb-1">
                                        <span class="small fw-bold text-white text-start">
                                            <span id="stat-cpu-usage">0.00%</span> <small class="text-muted ms-1">CPU LOAD</small>
                                        </span>
                                    </div>
                                    <div class="progress stat-progress-bar">
                                        <div class="progress-bar bg-info" id="stat-cpu-bar" style="width: 0%"></div>
                                    </div>
                                    <div class="small text-muted mt-2 text-start" id="stat-pid-container" style="display: <?= $isRunning ? 'block' : 'none' ?>;">PID Count: <span id="stat-pid-count">0</span></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 text-start stat-card-inner">
                                    <div class="mb-1">
                                        <span class="small fw-bold text-white text-start">
                                            <span id="stat-mem-perc">0.00%</span> <small class="text-muted ms-1">MEMORY USAGE</small>
                                        </span>
                                    </div>
                                    <div class="progress stat-progress-bar">
                                        <div class="progress-bar bg-warning" id="stat-mem-bar" style="width: 0%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-1 stat-label-micro">1 Min Avg
                                    </div>
                                    <div class="fw-bold text-white small" id="stat-load-1">0.0000</div>
                                    <div class="chart-container-35">
                                        <canvas id="chart-avg-1"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-1 stat-label-micro">5 Min Avg
                                    </div>
                                    <div class="fw-bold text-white small" id="stat-load-5">0.0000</div>
                                    <div class="chart-container-35">
                                        <canvas id="chart-avg-5"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-1 stat-label-micro">15 Min Avg
                                    </div>
                                    <div class="fw-bold text-white small" id="stat-load-15">0.0000</div>
                                    <div class="chart-container-35">
                                        <canvas id="chart-avg-15"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm blur rounded-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h6 class="fw-bold mb-0 ">Load History <span class="small text-muted ms-1">One Hour</span></h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-4 text-center">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-1 stat-label-micro">CPU Peak
                                    </div>
                                    <div class="fw-bold text-white" id="stat-peak-cpu">0.00%</div>
                                    <div class="mt-2 chart-container-40">
                                        <canvas id="chart-peak-cpu"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4 text-center">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-1 stat-label-micro">PID Max
                                    </div>
                                    <div class="fw-bold text-white" id="stat-max-pid">0</div>
                                    <div class="mt-2 chart-container-40">
                                        <canvas id="chart-max-pid"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4 text-center">
                                <div class="p-3 rounded-4 bg-dark bg-opacity-25 border border-white border-opacity-10 h-100 text-center stat-card-inner">
                                    <div class="text-muted small text-uppercase fw-bold mb-1 stat-label-micro">Memory High
                                    </div>
                                    <div class="fw-bold text-white" id="stat-high-mem">0.00 MB</div>
                                    <div class="mt-2 chart-container-40">
                                        <canvas id="chart-high-mem"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Lifecycle Card -->
    <div class="container-fluid px-3 mb-4">
        <div class="card border-0 shadow-sm blur rounded-4 lab-activity-card" data-id="<?= htmlspecialchars($fullHash) ?>" data-lab="<?= htmlspecialchars($labType) ?>">
            <div class="card-header bg-transparent border-0 p-4 d-flex justify-content-between align-items-center" role="button" data-coreui-toggle="collapse" data-coreui-target="#collapseActivity" aria-expanded="false" aria-controls="collapseActivity">
                <h6 class="fw-bold mb-0 text-success">Activity <span class="small text-muted fw-normal ms-1">Recent lifecycle</span></h6>
                <i class='bx bx-chevron-down fs-4 text-muted'></i>
            </div>
            <div id="collapseActivity" class="collapse">
                <div class="card-body p-4 border-top border-success border-opacity-10 mt-2">
                <div class="row g-4">
                    <!-- Left Side: Lab Logs -->
                    <div class="col-md-6">
                                <h6 class="fw-bold mb-3 small text-muted text-uppercase">Lab Lifecycle</h6>
                                <?php 
                                $labLogs = array_filter($activityLog, function($log) {
                                    return isset($log['type']) && $log['type'] === 'lab';
                                });
                                if (empty($labLogs)): ?>
                                    <p class="small text-muted mb-0">No recent lab activity.</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush bg-transparent">
                                        <?php foreach(array_slice($labLogs, 0, 10) as $log): 
                                            $actionLower = strtolower($log['action']);
                                            $iconClass = 'bx-refresh text-success';
                                            if (strpos($actionLower, 'stop') !== false) $iconClass = 'bx-power-off text-danger';
                                        ?>
                                            <div class="list-group-item bg-transparent border-bottom border-success border-opacity-10 py-2 px-0 d-flex gap-3 align-items-center">
                                                <i class='bx <?= $iconClass ?> fs-5'></i>
                                                <div>
                                                    <div class="fw-bold text-body small"><?= htmlspecialchars($log['action']) ?></div>
                                                    <div class="text-muted small opacity-75"><?= htmlspecialchars($log['user']) ?> &bull; <?= timeAgo($log['timestamp']) ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Right Side: Preferences Logs -->
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-3 small text-muted text-uppercase">Fast Apply (Preferences)</h6>
                                <?php 
                                $prefLogs = array_filter($activityLog, function($log) {
                                    return isset($log['type']) && $log['type'] === 'preference';
                                });
                                if (empty($prefLogs)): ?>
                                    <p class="small text-muted mb-0">No recent preference updates.</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush bg-transparent">
                                        <?php foreach(array_slice($prefLogs, 0, 10) as $log): ?>
                                            <div class="list-group-item bg-transparent border-bottom border-success border-opacity-10 py-2 px-0 d-flex gap-3 align-items-center">
                                                <i class='bx bx-cog text-primary fs-5'></i>
                                                <div class="overflow-hidden flex-grow-1" >
                                                    <div class="fw-bold text-body small text-truncate"><?= htmlspecialchars($log['action']) ?></div>
                                                    <div class="text-muted small opacity-75 mb-1 text-truncate"><?= htmlspecialchars($log['user']) ?> &bull; <?= timeAgo($log['timestamp']) ?></div>
                                                    <div class="small text-secondary stat-desc-wrap">
                                                        <?= ucfirst(strtolower(htmlspecialchars($log['details'] ?? 'Applied Preferences'))) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<?php include __DIR__ . '/partials/lab_modals.php'; ?>
<?php include __DIR__ . '/partials/server_logs.php'; ?>
