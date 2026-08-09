<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: text/html; charset=utf-8');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo '<p class="text-danger">Unauthorized</p>';
    exit;
}

$hash = $_GET['hash'] ?? '';
if (empty($hash)) {
    http_response_code(400);
    echo '<p class="text-danger">Missing hash</p>';
    exit;
}

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');

$inst = $db->machine_labs->findOne(
    ['instance_hash' => $hash],
    ['projection' => ['lab_type' => 1, 'user_id' => 1, 'instance_hash' => 1, 'internal_ip' => 1, 'credentials' => 1, 'status' => 1, 'code_domain' => 1, 'gui_domain' => 1, 'domains' => 1, 'expose_web' => 1, 'http_proxies' => 1]]
);
if ($inst) {
    $labData = $inst;
    $labData['lab_type'] = $inst['lab_type'] ?? 'essentials';
} else {
    $labData = [];
}
$user = Session::getUser();
$fullHash = $hash;

$labType = $labData['lab_type'] ?? 'essentials';
$status = $labData['status'] ?? 'not_deployed';
$isRunning = ($status === 'running');
$creds = $labData['credentials'] ?? null;

$deviceIp = $labData['internal_ip'] ?? '0.0.0.0';

$ipReg = $db->ip_registry;
$myIPs = $ipReg->find(['email' => $user->getEmail(), 'status' => 'reserved'])->toArray();

$inUseIps = [];
$labs = $db->machine_labs->find(['email' => $user->getEmail()])->toArray();
foreach ($labs as $l) {
    $ip = $l['internal_ip'] ?? null;
    if ($ip) $inUseIps[$ip] = true;
}
$instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instances = $instDb->instances->find(['email' => $user->getEmail()])->toArray();
foreach ($instances as $i) {
    $ip = $i['internal_ip'] ?? null;
    if ($ip) $inUseIps[$ip] = true;
}
$devices = $db->devices->find(['user_id' => $user->getUserId()])->toArray();
foreach ($devices as $dev) {
    $ip = $dev['assigned_ip'] ?? null;
    if ($ip) $inUseIps[$ip] = true;
}

$availableIPs = [];
foreach ($myIPs as $ip) {
    if (!isset($inUseIps[$ip['ip_addr']]) || $ip['ip_addr'] === $deviceIp) {
        $availableIPs[] = $ip['ip_addr'];
    }
}

$sysConsole = "s3-{$fullHash}.tomweb.shop";
$sysApi = "api-{$fullHash}.tomweb.shop";
$currConsole = str_replace(['https://', 'http://'], '', $creds['console_url'] ?? $sysConsole);
$currConsole = rtrim($currConsole, '/');
$currApi = str_replace(['https://', 'http://'], '', $creds['s3_api_url'] ?? $sysApi);
$currApi = rtrim($currApi, '/');

$userId = $user->getUserId();
$userDomainsCursor = $db->domains->find(['user_id' => $userId, 'verified' => true]);
$userDomainsList = iterator_to_array($userDomainsCursor);

$dm = new DomainManager();
$domainUsageMap = $dm->getDomainUsageMap($userId);

// Proxy domains
$proxyUserDomains = [];
foreach ($userDomainsList as $d) {
    $proxyUserDomains[] = (string)$d['domain'];
}
$httpProxies = isset($labData['http_proxies']) ? (array)$labData['http_proxies'] : [];

ob_start();
?>
<div class="modal fade" id="redeployModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h5 class="modal-title fw-bold">Confirm <?= $isRunning ? 'Redeploy' : 'Deploy' ?>?</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                
                <p class="mb-3 mt-2 modal-section-title">NETWORKING</p>

                <div class="row mb-2 align-items-center">
                    <label class="col-sm-4 small fw-bold text-secondary">Reallocate IP</label>
                    <div class="col-sm-8">
                        <select id="reallocate_ip_selector" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white">
                            <?php if (!empty($deviceIp) && $deviceIp !== '0.0.0.0'): ?>
                            <option value="<?= htmlspecialchars($deviceIp) ?>" selected><?= htmlspecialchars($deviceIp) ?></option>
                            <?php endif; ?>
                            <?php foreach ($availableIPs as $availIp): ?>
                                <?php if ($availIp !== $deviceIp): ?>
                                <option value="<?= htmlspecialchars($availIp) ?>"><?= htmlspecialchars($availIp) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <option value="new">Assign New IP Address</option>
                        </select>
                    </div>
                </div>

                <div id="vsc_domain_wrapper" class="row mb-3 align-items-center">
                    <label class="col-sm-4 small fw-bold text-secondary">Domain for VS Code Web</label>
                    <div class="col-sm-8">
                        <select id="vsc_domain_selector" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white" onchange="updateDomainAvailability()">
                            <?php 
                                $currentCodeDomain = $labData['code_domain'] ?? ($fullHash . '.tomweb.shop');
                                $isDefaultSelected = ($currentCodeDomain === ($fullHash . '.tomweb.shop'));
                            ?>
                            <option value="<?= htmlspecialchars($fullHash) ?>.tomweb.shop" <?= $isDefaultSelected ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fullHash) ?>.tomweb.shop
                            </option>
                            <?php foreach($userDomainsList as $d): ?>
                                <?php $isSelectedVSC = ($currentCodeDomain === $d['domain']); ?>
                                <option value="<?= htmlspecialchars($d['domain']) ?>" <?= $isSelectedVSC ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['domain']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="gui_domain_wrapper" class="row mb-3 align-items-center" style="display:none;">
                    <label class="col-sm-4 small fw-bold text-secondary">Domain for VNC GUI</label>
                    <div class="col-sm-8">
                        <select id="gui_domain_selector" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white" onchange="updateDomainAvailability()">
                            <?php 
                                $currentGuiDomain = $labData['gui_domain'] ?? ("gui-{$fullHash}.tomweb.shop");
                                $isGuiDefault = ($currentGuiDomain === ("gui-{$fullHash}.tomweb.shop"));
                            ?>
                            <option value="gui-<?= htmlspecialchars($fullHash) ?>.tomweb.shop" <?= $isGuiDefault ? 'selected' : '' ?>>
                                gui-<?= htmlspecialchars($fullHash) ?>.tomweb.shop
                            </option>
                            <?php foreach($userDomainsList as $d): ?>
                                <?php $isSelectedGui = ($currentGuiDomain === $d['domain']); ?>
                                <option value="<?= htmlspecialchars($d['domain']) ?>" <?= $isSelectedGui ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['domain']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php $isExposed = (isset($labData['expose_web']) && $labData['expose_web'] === true); ?>
                <?php if (\TomLabs\Labs\LabFeatures::supports($labType, 'expose_web')): ?>
                
                <p class="mb-2 mt-3 modal-section-title">PUBLIC EXPOSURE</p>

                <div id="expose_web_wrapper" class="row mb-3 align-items-center">
                    <label class="col-sm-4 small fw-bold text-secondary">Expose to Web (port 80)</label>
                    <div class="col-sm-8">
                        <select id="expose_web_toggle" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white" onchange="toggleDomainSection()">
                            <option value="false" <?= !$isExposed ? 'selected' : '' ?>>Private, not exposed</option>
                            <option value="true" <?= $isExposed ? 'selected' : '' ?>>Public, 80 exposed over 443</option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <!-- MinIO -->
                <div id="minio_domain_wrapper" class="initially-hidden">
                    <hr class="border-secondary opacity-25 my-3">
                    <p class="small fw-bold text-info mb-3"><i class='bx bx-server me-1'></i> MinIO Configuration</p>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 small fw-bold text-secondary">Domain for MinIO Console<br><span class="fw-normal opacity-75">(Port 9001)</span></label>
                        <div class="col-sm-8">
                            <select id="minio_console_domain" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white" onchange="updateDomainAvailability()">
                                <?php
                                    $foundConsole = false;
                                    foreach($userDomainsList as $d) {
                                        $isSel = ($d['domain'] === $currConsole);
                                        if($isSel) $foundConsole = true;
                                        echo '<option value="'.htmlspecialchars($d['domain']).'" '.($isSel ? 'selected' : '').'>'.htmlspecialchars($d['domain']).'</option>';
                                    }
                                    $isSysSel = ($currConsole === $sysConsole);
                                    if($isSysSel) $foundConsole = true;
                                    echo '<option value="'.htmlspecialchars($sysConsole).'" '.($isSysSel ? 'selected' : '').'> '.htmlspecialchars($sysConsole).'</option>';
                                    if(!$foundConsole) echo '<option value="'.htmlspecialchars($currConsole).'" selected> '.htmlspecialchars($currConsole).'</option>';
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 small fw-bold text-secondary">Domain for MinIO S3 Endpoint<br><span class="fw-normal opacity-75">(Port 9000)</span></label>
                        <div class="col-sm-8">
                            <select id="minio_api_domain" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white" onchange="updateDomainAvailability()">
                                <?php
                                    $foundApi = false;
                                    foreach($userDomainsList as $d) {
                                        $isSel = ($d['domain'] === $currApi);
                                        if($isSel) $foundApi = true;
                                        echo '<option value="'.htmlspecialchars($d['domain']).'" '.($isSel ? 'selected' : '').'>'.htmlspecialchars($d['domain']).'</option>';
                                    }
                                    $isSysSelApi = ($currApi === $sysApi);
                                    if($isSysSelApi) $foundApi = true;
                                    echo '<option value="'.htmlspecialchars($sysApi).'" '.($isSysSelApi ? 'selected' : '').'> '.htmlspecialchars($sysApi).'</option>';
                                    if(!$foundApi) echo '<option value="'.htmlspecialchars($currApi).'" selected> '.htmlspecialchars($currApi).'</option>';
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-text small opacity-50 mb-3">MinIO Console and S3 API can optionally use the same custom domain.</div>
                    <hr class="border-secondary opacity-25 my-3">
                </div>

                <!-- n8n -->
                <div id="n8n_domain_wrapper" class="initially-hidden">
                    <hr class="border-secondary opacity-25 my-3">
                    <p class="small fw-bold text-danger mb-3"><i class='bx bx-network-chart me-1'></i> n8n Configuration</p>
                    <?php
                        $sysN8n = "n8n-{$fullHash}.tomweb.shop";
                        if (!function_exists('cleanDomainN8n')) {
                            function cleanDomainN8n($url) { return rtrim(str_replace(['https://', 'http://'], '', $url), '/'); }
                        }
                        $currN8n = cleanDomainN8n($creds['n8n_url'] ?? $sysN8n);
                    ?>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 small fw-bold text-secondary">n8n Domain</label>
                        <div class="col-sm-8">
                            <select id="n8n_domain_selector" class="form-select bg-transparent border-secondary border-opacity-25 shadow-none rounded-pill px-3 text-white" onchange="updateDomainAvailability()">
                                <?php
                                    $foundN8n = false;
                                    foreach($userDomainsList as $d) {
                                        $isSel = ($d['domain'] === $currN8n);
                                        if($isSel) $foundN8n = true;
                                        echo '<option value="'.htmlspecialchars($d['domain']).'" '.($isSel ? 'selected' : '').'>'.htmlspecialchars($d['domain']).'</option>';
                                    }
                                    $isSysSel = ($currN8n === $sysN8n);
                                    if($isSysSel) $foundN8n = true;
                                    echo '<option value="'.htmlspecialchars($sysN8n).'" '.($isSysSel ? 'selected' : '').'> '.htmlspecialchars($sysN8n).'</option>';
                                    if(!$foundN8n) echo '<option value="'.htmlspecialchars($currN8n).'" selected> '.htmlspecialchars($currN8n).'</option>';
                                ?>
                            </select>
                        </div>
                    </div>
                    <hr class="border-secondary opacity-25 my-3">
                </div>

                <!-- Domain Selection -->
                <div id="domain_selection_wrapper" class="row mb-3 align-items-start" style="display: <?= $isExposed ? 'flex' : 'none' ?>;">
                    <label class="col-sm-4 small fw-bold text-secondary mt-2">Choose Domains</label>
                    <div class="col-sm-8 position-relative">
                        <div class="form-control bg-transparent border-secondary border-opacity-25 rounded-4 p-2 d-flex flex-wrap align-content-start gap-1 transition-all" onclick="document.getElementById('domain_search').focus()" id="domain_search_container">
                            <div id="selected_domains_display" class="domain-display-contents"></div>
                            <input type="text" id="domain_search" class="flex-grow-1 bg-transparent border-0 shadow-none small px-1 m-0 text-white" placeholder="Click to select domains..." onkeyup="filterDomains()" onclick="event.stopPropagation()">
                            <div class="ms-auto pe-1 d-flex align-items-start cursor-pointer" onclick="toggleDomainDropdown(event)">
                                <i class='bx bx-chevron-down fs-5 opacity-50 transition-icon mt-1' id="dropdown_arrow"></i>
                            </div>
                        </div>
                        <div id="domain_dropdown" class="border border-secondary border-opacity-10 rounded-3 mt-1 p-2 shadow-lg bg-body-tertiary" style="display:none;">
                            <div class="px-2 py-1 mb-1 d-flex justify-content-between align-items-center">
                                <button type="button" class="btn btn-sm btn-link text-primary p-0 text-decoration-none small" onclick="selectAllDomains()">Select all</button>
                                <span class="text-muted text-micro">Verified Domains</span>
                            </div>
                            <hr class="border-secondary opacity-25 my-1">
                            <div id="domain_list">
                                <?php 
                                    $currentLabDomains = (array)($labData['domains'] ?? []);
                                    foreach($userDomainsList as $d): 
                                        $isChecked = in_array($d['domain'], $currentLabDomains);
                                ?>
                                    <div class="form-check domain-item p-2 rounded mx-1 mb-1 cursor-pointer" onclick="toggleCheckbox('dom_<?= $d['_id'] ?>')">
                                        <input class="form-check-input domain-selector ms-0 me-2" type="checkbox" value="<?= htmlspecialchars($d['domain']) ?>" id="dom_<?= $d['_id'] ?>" <?= $isChecked ? 'checked' : '' ?> onchange="updateSelectedDomains()" onclick="event.stopPropagation()">
                                        <label class="form-check-label text-white small" for="dom_<?= $d['_id'] ?>">
                                            <?= htmlspecialchars($d['domain']) ?>
                                            <?php if (!empty($domainUsageMap[$d['domain']])): ?>
                                                <span class="badge bg-secondary ms-1"><?= count($domainUsageMap[$d['domain']]) ?> lab(s)</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (\TomLabs\Labs\LabFeatures::supports($labType, 'http_proxies')): ?>
                <div id="http_proxies_wrapper">
                    <p class="mb-2 mt-3 modal-section-title">HTTP PROXIES</p>
                    <div class="form-text small opacity-50 mb-3 px-1">Reverse-proxy any port to one or more of your domains over HTTP.</div>
                    <div id="deploy-proxy-container">
                        <?php if (empty($httpProxies)): ?>
                        <div class="row align-items-center mb-3 proxy-row" data-index="0">
                            <label class="col-sm-4 small fw-bold text-secondary">Port & Domains</label>
                            <div class="col-sm-8">
                                <div class="row g-2">
                                    <div class="col-md-4 col-12 mb-2 mb-md-0">
                                        <input type="number" name="deploy_proxy_port[]" class="form-control bg-transparent rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-port text-white" placeholder="Port" min="1" max="65535">
                                    </div>
                                    <div class="col-md-6 col-10">
                                        <select name="deploy_proxy_domain[]" class="form-select bg-transparent rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-domain-select text-white">
                                            <option value="">Select Domain...</option>
                                            <?php foreach ($proxyUserDomains as $ud): ?>
                                                <option value="<?= htmlspecialchars($ud) ?>"><?= htmlspecialchars($ud) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 col-2 d-flex justify-content-end">
                                        <button type="button" class="btn rounded-circle d-flex align-items-center justify-content-center p-0 btn-remove-proxy border-secondary border-opacity-25 bg-body-tertiary" style="width:36px;height:36px;color:#be185d;" onclick="removeProxyRow(this)"><i class='bx bx-trash'></i></button>
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
                                            <input type="number" name="deploy_proxy_port[]" class="form-control bg-transparent rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-port text-white" placeholder="Port" min="1" max="65535" value="<?= (int)($proxy['port'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 col-10">
                                            <select name="deploy_proxy_domain[]" class="form-select bg-transparent rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-domain-select text-white">
                                                <option value="">Select Domain...</option>
                                                <?php foreach ($proxyUserDomains as $ud): ?>
                                                    <option value="<?= htmlspecialchars($ud) ?>" <?= ((string)($proxy['domain'] ?? '') === $ud) ? 'selected' : '' ?>><?= htmlspecialchars($ud) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 col-2 d-flex justify-content-end">
                                            <button type="button" class="btn rounded-circle d-flex align-items-center justify-content-center p-0 btn-remove-proxy border-secondary border-opacity-25 bg-body-tertiary" style="width:36px;height:36px;color:#be185d;" onclick="removeProxyRow(this)"><i class='bx bx-trash'></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 row mb-2">
                        <div class="col-sm-4"></div>
                        <div class="col-sm-8">
                            <button type="button" class="btn rounded-pill px-4 py-1.5 d-inline-flex align-items-center gap-2 btn-add-proxy-row" onclick="addDeployProxyRow()"><i class='bx bx-message-square-add'></i> Add HTTP Proxy</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex align-items-start gap-2 mt-4 mb-2 px-1">
                    <i class='bx bxs-info-square text-secondary opacity-50 info-icon-micro'></i>
                    <div class="text-secondary opacity-75 info-text-micro">
                        <?= $isRunning ? 'Redeploy' : 'Deploy' ?> gives your lab a fresh instance &mdash; files outside your home directory are wiped.
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4 px-4">
                <button type="button" class="btn <?= $isRunning ? 'btn-warning' : 'btn-success' ?> fw-bold px-4 text-dark rounded-pill" id="redeploy-confirm-btn">
                    Confirm <?= $isRunning ? 'Redeploy' : 'Deploy' ?>
                </button>
                <button type="button" class="btn btn-secondary px-4 rounded-pill" data-coreui-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
<?php
echo ob_get_clean();