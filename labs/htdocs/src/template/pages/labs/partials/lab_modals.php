<!-- Stop Confirmation Modal -->
<div class="modal fade" id="stopModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg blur rounded-4 overflow-hidden modal-connection-info">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="modal-title fw-bold text-white mb-0">Stop Lab?</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-secondary mb-3">This will stop your lab instance. Your files in the home directory will be preserved, but running processes will be terminated.</p>
                <div class="d-flex align-items-start gap-2 mb-2 px-1">
                    <i class='bx bxs-info-square text-secondary opacity-50 info-icon-micro'></i>
                    <div class="text-secondary opacity-75 info-text-micro">
                        You can restart the lab anytime from the Labs page.
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4 px-4">
                <button type="button" class="btn btn-danger fw-bold px-4 text-white rounded-pill" id="stop-confirm-btn">
                    Confirm Stop
                </button>
                <button type="button" class="btn btn-secondary px-4 rounded-pill" data-coreui-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Code Info Modal (Simplified IDE Launch) -->
<div class="modal fade" id="codeInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg blur rounded-4 overflow-hidden modal-code-access">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="modal-title fw-bold text-white mb-0" id="codeModalTitle">Launch</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="codeModalLoading" class="text-center py-5">
                    <div class="spinner-grow text-primary" role="status"></div>
                </div>
                <div id="codeModalOffline" class="text-center py-5 d-none">
                    <i class='bx bx-power-off text-danger fs-1 mb-3'></i>
                    <h6 class="text-white fw-bold">Instance is Offline</h6>
                    <p class="text-white-50 small">Please deploy the lab first.</p>
                </div>
                <div id="codeModalContent" class="d-none">
                    <div id="codeFields"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Technical Connection Info Modal -->
<div class="modal fade" id="connectionInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg blur rounded-4 overflow-hidden modal-connection-info">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="modal-title fw-bold text-white mb-0">Connection Information</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-3 py-2" id="modalLabName">Lab Name</span>
                </div>
                <div id="modalLoading" class="text-center py-5"><div class="spinner-grow text-info" role="status"></div></div>
                <div id="modalOffline" class="text-center py-5 d-none">
                    <i class='bx bx-power-off text-danger fs-1 mb-3'></i>
                    <h6 class="text-white fw-bold">Instance is Offline</h6>
                    <p class="text-white-50 small">Please deploy the lab first.</p>
                </div>
                <div id="modalContent" class="d-none">
                    <div id="connectionFields"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-warning fw-bold px-4 rounded-pill" data-coreui-dismiss="modal">Okay</button>
            </div>
        </div>
    </div>
</div>
