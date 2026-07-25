<!-- VS Code Launch Modal -->
<div class="modal fade" id="vscModal" tabindex="-1" aria-labelledby="vscModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4" >
            <div class="modal-header border-0 pt-4 px-4">
                <h5 class="modal-title fw-bold" id="vscModalLabel">Visual Studio Code on Web</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4">
                <div class="mb-4">
                    <p class=" small fw-bold mb-2">What can you do here?</p>
                    <ul class="text-secondary small mb-0 ps-3">
                        <li>Code Effortlessly on Browser</li>
                        <li>Browse the filesystem and do CRUD</li>
                        <li>Access linux shell CLI</li>
                        <li>Develop effortlessly on the go</li>
                    </ul>
                </div>

                <div class="password-section p-3 rounded-3 mb-3" >
                    <p class=" small mb-3">You need this password in the next screen to login to VS Code on Web - Happy Coding!</p>
                    
                    <div class="row align-items-center g-2">
                        <div class="col-4">
                            <span class="text-secondary small fw-bold">Code Server Password</span>
                        </div>
                        <div class="col-8">
                            <div class="input-group input-group-sm">
                                <?php $modalCodeServerPass = $creds['code_server_pass'] ?? $creds['password'] ?? '********'; ?>
                                <input type="password" id="code-server-pass" 
                                       class="form-control border-secondary rounded-start-pill border-opacity-25" 
                                       value="<?= htmlspecialchars($modalCodeServerPass) ?>" readonly>
                                <button class="btn btn-outline-secondary rounded-end-pill px-3" 
                                        onclick="copyValue('code-server-pass')">
                                    <i class='bx bx-copy'></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="text-secondary small italic mb-0">
                    <span class="fw-bold ">Tip:</span> If there is an error while logging in with the password above or launcher doesn't work, redeploy and try again.
                </p>
            </div>
            <div class="modal-footer border-0 pb-4 px-4 gap-2">
                <button type="button" class="btn btn-success rounded-pill px-4 text-dark fw-bold" 
                        class="btn btn-sm btn-modal-green"
                        onclick="launchCodeIDE(event)"> Launch Code IDE
                </button>
                <button type="button" class="btn btn-secondary rounded-pill px-4" 
                        class="btn btn-sm btn-modal-gray"
                        data-coreui-dismiss="modal">
                    Dismiss
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MinIO Launch Modal -->
<div class="modal fade" id="minioModal" tabindex="-1" aria-labelledby="minioModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h5 class="modal-title fw-bold" id="minioModalLabel">MinIO Console Access</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4">
                <p class="small opacity-75 mb-4">
                    Use the credentials below to log in to the MinIO Console.
                </p>

                <?php 
                    // Extract specific MinIO fields from the config for the modal
                    $minioFields = [];
                    
                    if(isset($labConfig['fields'])) {
                        foreach($labConfig['fields'] as $f) {
                            if($f['label'] === 'MinIO Access Key' || $f['label'] === 'Minio Secret Key') {
                                $minioFields[] = $f;
                            }
                        }
                    }

                    // Helper to clean domains
                    if (!function_exists('cleanDomain')) {
                        function cleanDomain($url) {
                            $d = str_replace(['https://', 'http://'], '', $url);
                            return rtrim($d, '/');
                        }
                    }

                    $creds = $labData['credentials'] ?? [];
                    $hash = $labData['instance_hash'] ?? $fullHash;

                    // 1. Define SYSTEM DEFAULTS
                    $sysConsole = "s3-{$hash}.tomweb.shop";
                    $sysApi = "api-{$hash}.tomweb.shop";

                    // 2. Determine CURRENT CONFIGURATION
                    $currConsole = cleanDomain($creds['minio_url_console'] ?? $sysConsole);
                    $currApi = cleanDomain($creds['minio_url_api'] ?? $sysApi);
                    
                    $correctMinioConsoleUrl = "https://" . $currConsole;
                ?>

                <div class="d-flex flex-column gap-3 mb-4">
                    <?php foreach($minioFields as $field): ?>
                        <div class="password-section p-3 rounded-3">
                            <label class="small fw-bold text-secondary mb-1"><?= htmlspecialchars($field['label']) ?></label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control border-secondary border-opacity-25 bg-body-tertiary text-body" 
                                       value="<?= htmlspecialchars($field['value']) ?>" readonly>
                                <button class="btn btn-outline-secondary px-3" data-copy="<?= htmlspecialchars($field['value']) ?>">
                                    <i class='bx bx-copy'></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="alert alert-info border-0 d-flex align-items-center gap-2 small">
                    <i class='bx bx-info-circle fs-5'></i>
                    <span>The console requires HTTPS. Ensure you accept self-signed certificates if prompted.</span>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4 px-4 gap-2">
                <button type="button" 
                        class="btn btn-primary rounded-pill px-4 fw-bold text-dark d-flex align-items-center gap-2"
                        class="btn btn-sm btn-modal-blue"
                        onclick="launchCodeIDE(event, '<?= htmlspecialchars($correctMinioConsoleUrl) ?>')">
                    <i class='bx bx-window-open'></i> Open Console
                </button>
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-coreui-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Redeploy Modal (fetched on-demand via API when deploy button clicked) -->
<div id="redeployModalPlaceholder"></div>
<!-- Stop Lab Confirmation Modal -->
<div class="modal fade" id="stopModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden modal-stop-content">
            <div class="modal-header border-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-white mb-0">Decommission Lab?</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <div class="bg-danger bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3 stop-icon-wrapper">
                        <i class='bx bx-power-off text-danger stop-icon-lg'></i>
                    </div>
                    <h6 class="text-white fw-bold mb-2">Are you sure you want to stop this instance?</h6>
                    <p class="text-secondary small mb-0 px-3">
                        Stopping the lab will terminate all active processes and release CPU/RAM resources. 
                        <span class="text-info fw-bold">Your files and IP address will remain safe and reserved.</span>
                    </p>
                </div>

                <div class="p-3 rounded-3 bg-dark bg-opacity-25 border border-white border-opacity-10 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-secondary fw-bold">Target Instance</span>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25"><?= strtoupper($labType) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-secondary fw-bold">Reserved IP</span>
                        <span class="small font-monospace text-white"><?= $deviceIp ?></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 gap-2">
                <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold flex-grow-1" 
                        id="stop-confirm-btn" onclick="executeStop()">
                    Stop Instance
                </button>
                <button type="button" class="btn btn-secondary bg-opacity-25 border-0 fw-bold px-4 rounded-pill" 
                        data-coreui-dismiss="modal">
                    Keep Running
                </button>
            </div>
        </div>
    </div>
</div>
