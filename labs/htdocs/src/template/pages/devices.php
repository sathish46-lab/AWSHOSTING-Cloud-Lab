<?php
// Retrieve active devices and reserved resources from the session
$devices = Session::get('devices', []); 
$resources = Session::get('network_resources', []); 

// LOGIC: Automatically select the last reserved IP for default selection
$defaultIp = !empty($resources) ? end($resources)['ip_addr'] : ""; 
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tweetnacl/1.0.3/nacl-fast.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>



<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-4">
        <div class="row align-items-center py-3">
            <div class="col-lg-9 col-auto me-auto">
                <div class="p-3">
                    <h3 class="devices-header-title"><strong>Devices</strong></h3>
                    <p class="devices-header-desc">
                        My Devices is a section where you can register your device to access our labs to learn, develop tools and play challenges and many more features in a secure way. 
                        We have launched a new VPN app where you can connect to our network with one click to access your labs and do much more, now available for Windows and Ubuntu. 
                        <a href="#" class="devices-header-link">Download here</a> or watch how to use this tool <a href="#" class="devices-header-link">here</a>.
                    </p>
                </div>
            </div>
            <div class="col-auto m-auto">
                <div class="row">
                    <a class="btn btn-success btn-add-device" data-coreui-toggle="modal" data-coreui-target="#addDeviceModal">Add Device</a>
                </div>
                <div class="row mt-2">
                    <div class="device-limit-text text-center">Limit: <?= count($devices) ?>/5</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
    <div class="row g-4" id="devices-container">
    <?php foreach ($devices as $device): ?>
    <?php include __DIR__ . '/../partials/_device_card.php'; ?>
    <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="addDeviceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form id="vpnAddForm" hx-boost="false">
                <div class="modal-header border-bottom border-light border-opacity-10 p-4">
                    <h5 class="modal-title fw-bold">Add Device</h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal"></button>
                </div>
                <div id="addDeviceModalContent">
                    <div class="modal-body p-5 text-center">
                        <i class="bx bx-loader-alt bx-spin text-primary spinner-loader-icon"></i>
                        <div class="mt-3 text-white opacity-75 fw-semibold tracking-widest uppercase loading-form-text">Loading form...</div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="configModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom border-light border-opacity-10 p-4">
                <h5 class="modal-title fw-bold" id="configTitle">Wireguard Configuration</h5>
                <button type="button" class="btn-close" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <div class="config-view mb-3" id="configText"></div><button
                            class="btn btn-primary btn-sm w-100 fw-bold" onclick="copyConfig()"><i
                                class='bx bx-copy'></i> Copy Configuration</button>
                    </div>
                    <div class="col-md-5 text-center mt-3 mt-md-0">
                        <div id="qrcode" class="d-inline-block"></div>
                        <p class="small text-secondary mt-3 mb-0">Scan QR for Mobile VPN.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0"><button
                    class="btn btn-warning w-100 rounded-pill text-dark fw-bold"
                    data-coreui-dismiss="modal">Okay</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmDeleteDeviceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg rounded-4 border-0 blur glass-modal-content">
        <div class="modal-header border-0 pb-2">
            <h4 class="modal-title fw-bold m-0 modal-title-delete">Delete Device: <span id="deleteDeviceModalTitleName"></span></h4>
        </div>
        <div class="modal-body py-3 border-top border-bottom border-translucent">
            <p class="mb-2 opacity-75 modal-body-desc">
                You are about to delete a registered device named <strong id="deleteDeviceModalBodyName" class="text-white"></strong>. 
                You will no longer be able to communicate via this device and you will lose your IP address If its not reserved.
            </p>
            <p class="mb-0 opacity-75 modal-body-ip">
                Current IP: <span id="deleteDeviceModalIp" class="text-info fw-bold font-monospace"></span>.<br>
                Are you sure to continue?
            </p>
        </div>
        <div class="modal-footer border-0 pt-3 pb-1 d-flex justify-content-end gap-3">
            <button type="button" class="btn px-4 rounded-pill fw-bold border-0 shadow-sm btn-modal-cancel" data-coreui-dismiss="modal">Cancel</button>
            <button type="button" class="btn text-white px-4 rounded-pill fw-bold border-0 btn-modal-delete" id="confirmDeleteDeviceBtn" onclick="confirmDeleteDeviceAction()">Delete</button>
        </div>
    </div>
  </div>
</div>