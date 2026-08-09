<?php
require_once '../../load.php';

// Only allow authenticated users
if (!Session::getUser()) {
    http_response_code(401);
    die("Unauthorized");
}

$user = Session::getUser();
$db = DatabaseConnection::getDefaultDatabase();
$instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');

// Get user's reserved IPs from ip_registry
$ipReg = $db->ip_registry;
$myIPs = $ipReg->find(['email' => $user->getEmail(), 'status' => 'reserved']);

// Collect IPs already in use by devices, labs, or instances
$usedIps = [];

// Devices
$activeMetadata = $db->devices->find(['user_id' => $user->getUserId(), 'status' => ['$ne' => 'deleted']])->toArray();
foreach ($activeMetadata as $d) {
    if (!empty($d['assigned_ip'])) $usedIps[$d['assigned_ip']] = true;
}

// Machine labs
$labs = $db->machine_labs->find(['deploy.email' => $user->getEmail()])->toArray();
foreach ($labs as $lab) {
    $ip = $lab['deploy']['internal_ip'] ?? null;
    if ($ip) $usedIps[$ip] = true;
}

// Instances
$instances = $instDb->instances->find(['email' => $user->getEmail()])->toArray();
foreach ($instances as $inst) {
    $ip = $inst['deploy']['internal_ip'] ?? null;
    if ($ip) $usedIps[$ip] = true;
}

$resources = [];
foreach ($myIPs as $ip) {
    // Skip IPs in .1-.9 range (reserved for system infrastructure by vpn-api)
    $lastOctet = (int) substr(strrchr($ip['ip_addr'], '.'), 1);
    if ($lastOctet >= 1 && $lastOctet <= 9) continue;

    if (!isset($usedIps[$ip['ip_addr']])) {
        $resources[] = ['ip_addr' => $ip['ip_addr']];
    }
}
$defaultIp = !empty($resources) ? end($resources)['ip_addr'] : "";
?>
                <div class="modal-body px-4 pt-4 pb-2">
                    <p class="small opacity-75 mb-3" style="line-height: 1.5; font-size: 0.8rem;">
                        You can add devices to connect to your labs, allowing them to communicate with our services. To get started, please provide the details of the device below.
                    </p>

                    <hr class="border-secondary opacity-25 mb-3">

                    <div class="row mb-2 align-items-center">
                        <label class="col-sm-4 small fw-bold" style="font-size: 0.8rem;">Device Name</label>
                        <div class="col-sm-8">
                            <input type="text" class="form-control bg-transparent text-white border-secondary shadow-none px-3 rounded-pill" style="border-radius: 12px !important; border: 1px solid rgba(255,255,255,0.2); font-size: 0.85rem;" name="device_name" required placeholder="e.g. My Laptop">
                        </div>
                    </div>

                    <div class="row mb-2 align-items-center">
                        <label class="col-sm-4 small fw-bold" style="font-size: 0.8rem;">Device Type</label>
                        <div class="col-sm-8">
                            <select class="form-select bg-transparent text-white border-secondary shadow-none px-3 rounded-pill" style="border-radius: 12px !important; border: 1px solid rgba(255,255,255,0.2); font-size: 0.85rem;" name="device_type">
                                <option value="Mobile">Mobile</option>
                                <option value="Laptop">Laptop</option>
                                <option value="Desktop">Desktop</option>
                                <option value="Tablet">Tablet</option>
                                <option value="Server">Server</option>
                                <option value="IoT">IoT</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-2 align-items-center">
                        <label class="col-sm-4 small fw-bold" style="font-size: 0.8rem;">Reallocate IP</label>
                        <div class="col-sm-8">
                            <select class="form-select bg-transparent text-white border-secondary shadow-none px-3 rounded-pill" style="border-radius: 12px !important; border: 1px solid rgba(255,255,255,0.2); font-size: 0.85rem;" name="ip">
                                <option value="">Assign New IP Address</option>
                                <?php foreach ($resources as $res): ?>
                                <option value="<?= $res['ip_addr'] ?>"
                                    <?= $res['ip_addr'] == $defaultIp ? 'selected' : '' ?>>
                                    <?= $res['ip_addr'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-2">
                        <div class="col-sm-4"></div>
                        <div class="col-sm-8">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="autoGenCheck" checked onchange="toggleManualKey()">
                                <label class="form-check-label opacity-75" for="autoGenCheck" style="line-height: 1.4; font-size: 0.75rem;">
                                    Auto Generate Keypair (Note: Your PrivateKey will be generated and managed by us, inturn you can download the tunnel file or scan QR to configure VPN without hassle).
                                </label>
                            </div>
                        </div>
                    </div>

                    <div id="manualKeyArea" style="display:none" class="mb-2">
                        <div class="row align-items-center">
                            <label class="col-sm-4 small fw-bold" style="font-size: 0.8rem;">Wireguard Public Key</label>
                            <div class="col-sm-8">
                                <textarea class="form-control bg-transparent text-white border-secondary shadow-none py-2 px-3 rounded-4 font-monospace" style="border-radius: 12px !important; border: 1px solid rgba(255,255,255,0.2); font-size: 0.8rem;" id="inputPubKey" rows="2" placeholder="Paste your Public Key here"></textarea>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="public_key" id="hiddenPubKey">
                    <input type="hidden" name="private_key" id="hiddenPrivKey">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($user->getEmail()) ?>">

                    <div class="row mb-1 mt-1">
                        <div class="col-sm-4"></div>
                        <div class="col-sm-8">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="is_primary" id="primaryCheck">
                                <label class="form-check-label opacity-75" for="primaryCheck" style="font-size: 0.8rem;">Primary Device</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="is_trusted" id="trustedCheck">
                                <label class="form-check-label opacity-75" for="trustedCheck" style="font-size: 0.8rem;">Trusted Device</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="reserved" id="reserveCheck">
                                <label class="form-check-label opacity-75" for="reserveCheck" style="font-size: 0.8rem;">Reserve IP when Allocated</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pb-4 px-4 gap-2">
                    <button type="submit" class="btn btn-warning fw-bold px-4 text-dark rounded-pill" style="font-size: 0.85rem;">Verify and Add</button>
                    <button type="button" class="btn btn-secondary px-4 rounded-pill" data-coreui-dismiss="modal" style="font-size: 0.85rem;">Cancel</button>
                </div>
