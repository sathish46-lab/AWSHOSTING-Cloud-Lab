<?php
    $dbId = is_array($device['_id']) ? ($device['_id']['$oid'] ?? '') : (string)$device['_id'];
    $displayStatus = ucfirst(strtolower($device['status'] ?? 'offline'));
    $assignedIp = $device['assigned_ip'] ?? null;
    $isReserved = false;
    if ($assignedIp) {
        $db = DatabaseConnection::getDefaultDatabase();
        $isReserved = $db->ip_registry->countDocuments(['ip_addr' => $assignedIp, 'status' => 'reserved']) > 0;
    }
?>
<div class="col-12 col-md-4 device-row card-entrance" id="device-card-<?= $dbId ?>" data-pubkey="<?= $device['public_key'] ?>">
    <div class="card rounded-4 border-0 blur h-100 device-card-inner">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start">
                <h5 class="fw-bold m-0 text-truncate device-card-title">
                    <?= htmlspecialchars($device['device_name']) ?></h5>
                <div class="dropdown">
                    <button class="btn btn-transparent p-0" type="button" 
                            data-coreui-toggle="dropdown" 
                            >
                        <i class='bx bx-dots-vertical-rounded fs-4'></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end blur">
                        <a class="dropdown-item btn-config-device blur-item" href="javascript:void(0)"
                                onclick="openVPNConnectionModal('<?= $dbId ?>', '<?= htmlspecialchars($device['device_name']) ?>')">Config</a>
                        <a class="dropdown-item btn-config-download blur-item" href="javascript:void(0)"
                                onclick="downloadTunnel('<?= htmlspecialchars($device['device_name']) ?>', '<?= $dbId ?>')">Download</a>
                        <?php if ($assignedIp): ?>
                        <?php if ($isReserved): ?>
                        <a class="dropdown-item btn-unreserve-ip blur-item" href="javascript:void(0)"
                                onclick="toggleReserveIp('<?= $dbId ?>', '<?= $assignedIp ?>', '<?= htmlspecialchars($device['device_name'], ENT_QUOTES) ?>', 'unreserve', this)">Unreserve IP</a>
                        <?php else: ?>
                        <a class="dropdown-item btn-reserve-ip blur-item" href="javascript:void(0)"
                                onclick="toggleReserveIp('<?= $dbId ?>', '<?= $assignedIp ?>', '<?= htmlspecialchars($device['device_name'], ENT_QUOTES) ?>', 'reserve', this)">Reserve IP</a>
                        <?php endif; ?>
                        <?php endif; ?>
                        <a class="dropdown-item btn-delete-device blur-item" href="javascript:void(0)"
                                onclick="deleteDevice('<?= $dbId ?>', '<?= $device['public_key'] ?>', '<?= htmlspecialchars($device['device_name'], ENT_QUOTES) ?>', '<?= $device['assigned_ip'] ?? 'N/A' ?>')">Delete</a>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-1 flex-wrap mb-2">
                <?php 
                    $type = strtolower($device['device_type'] ?? 'mobile');
                    $typeClass = 'bg-primary';
                    $typeIcon = 'bx-mobile-alt';
                    
                    if ($type === 'laptop') { $typeClass = 'bg-info'; $typeIcon = 'bx-laptop'; }
                    elseif ($type === 'desktop') { $typeClass = 'bg-success'; $typeIcon = 'bx-desktop'; }
                    elseif ($type === 'tablet') { $typeClass = 'bg-warning'; $typeIcon = 'bx-tab'; }
                    elseif ($type === 'server') { $typeClass = 'bg-dark'; $typeIcon = 'bx-server'; }
                    elseif ($type === 'iot') { $typeClass = 'bg-danger'; $typeIcon = 'bx-chip'; }
                ?>
                <span class="badge <?= $typeClass ?> fw-bold" >
                    <i class='bx <?= $typeIcon ?> me-1'></i> <?= $type ?>
                </span>
                <?php if ($isReserved): ?>
                <span class="badge bg-warning fw-bold" >
                    <i class='bx bx-lock me-1'></i> Reserved
                </span>
                <?php endif; ?>
                <?php 
                    $status = strtolower($device['status'] ?? 'offline');
                    $statusClass = ($status === 'online') ? 'bg-success' : 'bg-danger';
                    $statusIcon = ($status === 'online') ? 'bx-wifi' : 'bx-wifi-off';
                ?>
                <span class="badge status-pill <?= $statusClass ?> fw-bold" >
                    <i class='bx <?= $statusIcon ?> me-1'></i> <?= $status ?>
                </span>
            </div>
            <div class="small stats-area" >
                <div class="d-flex justify-content-between mb-1">
                    <span >Device IP:</span> 
                    <span class="theme-text font-monospace" ><?= $device['assigned_ip'] ?? 'N/A' ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span >Origin:</span> 
                    <span class="origin-val theme-text" ><?= $device['origin_ip'] ?? 'N/A' ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span >Received:</span> 
                    <span class="rx-val theme-text"><?= $device['rx'] ?? '0 B' ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span >Sent:</span> 
                    <span class="tx-val theme-text"><?= $device['tx'] ?? '0 B' ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
