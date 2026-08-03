/**
 * Wrapped with IIFE Error Boundary
 */
try {
  (function() {
    "use strict";


let activeConfigRaw = "";

function toggleManualKey() {
    document.getElementById('manualKeyArea').style.display = document.getElementById('autoGenCheck').checked ? 'none' :
        'block';
}

function generateWGKeypair() {
    const keyPair = nacl.box.keyPair();
    return {
        private: btoa(String.fromCharCode(...keyPair.secretKey)),
        public: btoa(String.fromCharCode(...keyPair.publicKey))
    };
}

async function openVPNConnectionModal(id, name) {
    // 1. Show Loading State (could use a spinner in modal if needed)
    console.log("Fetching VPN config for:", id);

    try {
        const response = await fetch(`/api/vpn/connection_info?id=${id}`);
        const data = await response.json();

        if (data.status === 'success') {
            showConfig(name, data.data.config_raw);
        } else {
            alert('Failed to load configuration: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        console.error(err);
        alert('Network error occurred.');
    }
}

function showConfig(name, configRaw) {
    activeConfigRaw = configRaw;
    const html = activeConfigRaw
        .replace(/\[Interface\]/g, '<span class="config-header">[Interface]</span>')
        .replace(/\[Peer\]/g, '<span class="config-header">[Peer]</span>')
        .replace(/(PrivateKey|Address|DNS|PublicKey|AllowedIPs|Endpoint|PersistentKeepalive)/g,
            '<span class="config-label">$1</span>')
        .replace(/ = (.*)/g, ' = <span class="config-value">$1</span>');

    document.getElementById('configText').innerHTML = html.replace(/\n/g, '<br>');
    document.getElementById('configTitle').innerText = "Wireguard Config: " + name;

    const qrEl = document.getElementById("qrcode");
    qrEl.innerHTML = "";
    
    // Add a mandatory white "quiet zone" padding around the QR code. 
    // Scanners fail if the black edges bleed into a dark UI background.
    qrEl.style.padding = "10px";
    qrEl.style.backgroundColor = "#ffffff";
    qrEl.style.borderRadius = "8px";
    qrEl.style.display = "inline-block";
    
    // Strict WireGuard Scanners fail if the QR code is damaged by a logo, or if there are weird line endings.
    // We normalize the string to strict \n and trim it.
    const cleanConfig = activeConfigRaw.trim().replace(/\r\n/g, '\n');
    
    const qrcode = new QRCode(qrEl, {
        text: cleanConfig,
        width: 256,  
        height: 256,
        // Level M provides the perfect balance of readability and block size
        correctLevel: QRCode.CorrectLevel.M 
    });
    
    new coreui.Modal(document.getElementById('configModal')).show();
}

function copyConfig() {
    copyText(activeConfigRaw, "Config Copied!");
}

async function downloadTunnel(name, deviceId) {
    try {
        const response = await fetch(`/api/vpn/connection_info?id=${deviceId}`);
        const data = await response.json();

        if (data.status === 'success') {
            const blob = new Blob([data.data.config_raw], {
                type: 'text/plain'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${name.replace(/\s+/g, '_')}.conf`;
            link.click();
        } else {
            alert("Download failed: " + data.error);
        }
    } catch (err) {
        alert("Network Error during download");
    }
}

document.addEventListener('submit', async function (e) {
    if (e.target && e.target.id === 'vpnAddForm') {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        if (document.getElementById('autoGenCheck').checked) {
            const keys = generateWGKeypair();
            document.getElementById('hiddenPrivKey').value = keys.private;
            document.getElementById('hiddenPubKey').value = keys.public;
        } else {
            document.getElementById('hiddenPubKey').value = document.getElementById('inputPubKey').value;
            document.getElementById('hiddenPrivKey').value = "";
        }
        btn.disabled = true;
        btn.innerText = 'Provisioning...';
        try {
            const res = await fetch('/api/vpn/add', {
                method: 'POST',
                body: new FormData(form)
            });
            if (res.ok) {
                const htmlText = await res.text();
                const container = document.getElementById('devices-container');
                if (container && htmlText.trim()) {
                    container.insertAdjacentHTML('beforeend', htmlText);
                }
                
                const modalEl = document.getElementById('addDeviceModal');
                if (modalEl) {
                    const modal = coreui.Modal.getInstance(modalEl) || coreui.Modal.getOrCreateInstance(modalEl);
                    if (modal) {
                        modal.hide();
                    } else {
                        // Fallback: click the dismiss button
                        const dismissBtn = modalEl.querySelector('[data-coreui-dismiss="modal"]');
                        if (dismissBtn) dismissBtn.click();
                    }
                    
                    // Reset form
                    form.reset();
                    document.getElementById('hiddenPubKey').value = "";
                    document.getElementById('hiddenPrivKey').value = "";
                    
                    // Show success toast or alert
                    console.log("Device added successfully!");
                } else {
                    window.location.reload();
                }
            } else {
                const errorMsg = await res.text();
                alert("Error: " + (errorMsg || "Unknown Error"));
            }
        } catch (err) {
            console.error(err);
            alert("Network Error");
        } finally {
            btn.disabled = false;
            btn.innerText = 'Verify and Add';
        }
    }
});

let currentDeleteDeviceId = null;
let currentDeleteDevicePubKey = null;

async function deleteDevice(dbId, pubKey, deviceName, ipAddress) {
    currentDeleteDeviceId = dbId;
    currentDeleteDevicePubKey = pubKey;
    
    // Update modal content
    const titleSpan = document.getElementById('deleteDeviceModalTitleName');
    const bodySpan = document.getElementById('deleteDeviceModalBodyName');
    const ipSpan = document.getElementById('deleteDeviceModalIp');
    
    if (titleSpan) titleSpan.textContent = deviceName || 'Device';
    if (bodySpan) bodySpan.textContent = deviceName || 'this device';
    if (ipSpan) ipSpan.textContent = ipAddress || 'Unknown IP';
    
    // Show Trash Bin (lid open, waiting)
    if (window.TrashBin) window.TrashBin.show();
    
    // Show Modal
    const modalEl = document.getElementById('confirmDeleteDeviceModal');
    const deleteModal = new coreui.Modal(modalEl);
    deleteModal.show();
    
    // Hide bin if modal is dismissed (cancel / backdrop click / ESC)
    modalEl.addEventListener('hidden.coreui.modal', function onHide() {
        modalEl.removeEventListener('hidden.coreui.modal', onHide);
        if (window.TrashBin) window.TrashBin.hide();
    });
}

async function confirmDeleteDeviceAction() {
    const confirmBtn = document.getElementById('confirmDeleteDeviceBtn');
    if (!confirmBtn) return;
    
    const modalEl = document.getElementById('confirmDeleteDeviceModal');
    const deleteModal = coreui.Modal.getInstance(modalEl) || new coreui.Modal(modalEl);
    
    const originalText = confirmBtn.innerHTML;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin me-1'></i> Deleting...";

    try {
        const res = await fetch('/api/vpn/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: currentDeleteDeviceId,
                public_key: currentDeleteDevicePubKey
            })
        });
        const resData = await res.json();
        if (resData.status === 'success') {
            deleteModal.hide();
            const card = document.getElementById(`device-card-${currentDeleteDeviceId}`);
            if (card) {
                window.TrashBin.animateDelete(card, "Device successfully deleted.");
            } else {
                window.location.reload();
            }
        } else {
            if (window.TomNotify) window.TomNotify.show(resData.error || 'Failed to delete device', 'Error', 'error', 3000);
        }
    } catch (e) {
        if (window.TomNotify) window.TomNotify.show('Connection error.', 'Error', 'error', 3000);
    } finally {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalText;
    }
}
window.confirmDeleteDeviceAction = confirmDeleteDeviceAction;

window.onPageLoad( () => {
    let statsInterval = null;

    // Position device card dropdowns on open
    document.addEventListener('show.coreui.dropdown', function(e) {
        const dropdown = e.target;
        if (!dropdown.closest('.device-row')) return;
        const menu = dropdown.querySelector('.dropdown-menu');
        if (!menu) return;
        // Let CSS handle positioning, just ensure it's visible
        menu.style.zIndex = '9999';
    });

    async function fetchStats() {
        // 1. Page Check: Only run if device rows exist
        if (document.querySelectorAll('.device-row').length === 0) return;

        try {
            // 2. Clean URL
            const response = await fetch('/api/vpn/stats');
            const data = await response.json();

            document.querySelectorAll('.device-row').forEach(card => {
                const live = data.find(d => d.id === card.dataset.pubkey);
                // 3. Auto-detect Offline/Online
                if (live) {
                    const pill = card.querySelector('.status-pill');
                    if (!pill) return;
                    const icon = live.status === 'Online' ? 'bx-wifi' : (live.status === 'Unreachable' ? 'bx-time-five' : 'bx-wifi-off');

                    pill.innerHTML = `<i class='bx ${icon} me-1'></i> ${live.status}`;
                    pill.className = `badge rounded-pill status-pill bg-${live.color} fw-bold d-flex align-items-center`;

                    // Update Stats
                    const rxEl = card.querySelector('.rx-val');
                    const txEl = card.querySelector('.tx-val');
                    const originEl = card.querySelector('.origin-val');

                    if (rxEl) rxEl.innerText = live.rx;
                    if (txEl) txEl.innerText = live.tx;
                    if (originEl) originEl.innerText = live.origin;
                }
            });
        } catch (e) {
            console.warn("VPN Stats Error:", e);
        }
    }

    // 4. Smart Polling Controller
    function startPolling() {
        if (statsInterval) clearInterval(statsInterval);
        fetchStats(); // Capture fast (Immediate run)
        statsInterval = setInterval(fetchStats, 3000); // 3s for faster updates
    }

    function stopPolling() {
        if (statsInterval) {
            clearInterval(statsInterval);
            statsInterval = null;
        }
    }

    // 5. Visibility & HTMX Navigation Handlers
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling(); // Resumes and fetches immediately
        }
    });

    document.addEventListener("htmx:beforeSwap", () => {
        stopPolling();
    });

    // Start if visible
    if (!document.hidden && document.querySelectorAll('.device-row').length > 0) {
        startPolling();
    }

});

// Global Dynamic loader for Add Device Modal (Works across HTMX swaps)
document.addEventListener('show.coreui.modal', function(e) {
    if (e.target && e.target.id === 'addDeviceModal') {
        const content = document.getElementById('addDeviceModalContent');
        if (!content || content.getAttribute('data-loaded') === 'true') return;
        
        fetch('/api/device/add')
            .then(res => {
                if (res.status === 429) {
                    return res.json().catch(function() { return {}; }).then(function(data) {
                        var retry = data.retry_after || 60;
                        var msg = data.error || 'Too many requests.';
                        content.innerHTML = '<div class="p-5 text-center">' +
                            '<i class="bx bx-time-five text-warning" style="font-size:3rem;"></i>' +
                            '<h5 class="text-white fw-bold mt-3 mb-2">Slow Down</h5>' +
                            '<p class="text-secondary mb-3" style="font-size:0.85rem;">' + msg + '</p>' +
                            '<p class="text-warning mb-3" style="font-size:0.8rem;">Try again in ' + retry + ' seconds.</p>' +
                            '<button class="btn btn-outline-warning rounded-pill px-4 fw-bold" data-coreui-dismiss="modal">Okay</button>' +
                            '</div>';
                        // Also show global toast
                        if (window.showRateLimitToast) window.showRateLimitToast(data);
                    });
                }
                if (!res.ok) throw new Error('Failed to load form');
                return res.text();
            })
            .then(function(html) {
                if (!html) return;
                content.innerHTML = html;
                content.setAttribute('data-loaded', 'true');
            })
            .catch(function(err) {
                console.error(err);
                content.innerHTML = '<div class="p-4 text-danger text-center">Failed to load form.</div>';
            });
    }
});


    

    // --- Explicit Window Exports for Inline HTML ---
    window.copyConfig = copyConfig;
    window.activeConfigRaw = activeConfigRaw;
    window.showConfig = showConfig;
    window.openVPNConnectionModal = openVPNConnectionModal;
    window.toggleManualKey = toggleManualKey;
    window.downloadTunnel = downloadTunnel;
    window.generateWGKeypair = generateWGKeypair;
    window.deleteDevice = deleteDevice;

let pendingUnreserve = { deviceId: '', ipAddr: '', deviceName: '', btn: null };

function toggleReserveIp(deviceId, ipAddr, deviceName, action, btn) {
    if (action === 'unreserve') {
        pendingUnreserve = { deviceId, ipAddr, deviceName, btn };
        document.getElementById('unreserveModalIp').textContent = ipAddr;
        document.getElementById('unreserveModalIpBold').textContent = ipAddr;
        new coreui.Modal(document.getElementById('confirmUnreserveModal')).show();
        return;
    }
    doReserve(deviceId, ipAddr, deviceName, btn);
}

async function doReserve(deviceId, ipAddr, deviceName, btn) {
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt bx-spin me-1'></i>`;

    try {
        const res = await fetch('/api/vpn/reserve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ip: ipAddr })
        });
        const data = await res.json();
        if (data.result) {
            const card = document.getElementById(`device-card-${deviceId}`);
            if (card) {
                const badgeArea = card.querySelector('.d-flex.gap-1.flex-wrap');
                const existingBadge = badgeArea ? badgeArea.querySelector('.bg-warning') : null;
                if (!existingBadge && badgeArea) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-warning fw-bold';
                    badge.innerHTML = "<i class='bx bx-lock me-1'></i> Reserved";
                    badgeArea.insertBefore(badge, badgeArea.children[1] || null);
                }
                btn.innerHTML = "<i class='bx bx-lock me-1'></i> Unreserve IP";
                btn.setAttribute('onclick', `toggleReserveIp('${deviceId}','${ipAddr}','${deviceName}','unreserve',this)`);
                btn.className = 'dropdown-item rounded-3 mb-1 px-2 py-1 text-warning';
            }
        }
    } catch (e) {
        btn.innerHTML = "<i class='bx bx-lock-open me-1'></i> Reserve IP";
    }
    btn.disabled = false;
}

async function confirmUnreserveAction() {
    const { deviceId, ipAddr, deviceName, btn } = pendingUnreserve;
    const confirmBtn = document.getElementById('confirmUnreserveBtn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin me-1'></i> Unreserving...";

    try {
        const res = await fetch('/api/vpn/unreserve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ip: ipAddr })
        });
        const data = await res.json();
        if (data.result) {
            const modal = coreui.Modal.getInstance(document.getElementById('confirmUnreserveModal'));
            if (modal) modal.hide();

            const card = document.getElementById(`device-card-${deviceId}`);
            if (card) {
                const badgeArea = card.querySelector('.d-flex.gap-1.flex-wrap');
                const existingBadge = badgeArea ? badgeArea.querySelector('.bg-warning') : null;
                if (existingBadge) existingBadge.remove();

                btn.innerHTML = "<i class='bx bx-lock-open me-1'></i> Reserve IP";
                btn.setAttribute('onclick', `toggleReserveIp('${deviceId}','${ipAddr}','${deviceName}','reserve',this)`);
                btn.className = 'dropdown-item rounded-3 mb-1 px-2 py-1 text-success';
            }
        }
    } catch (e) {
        btn.innerHTML = "<i class='bx bx-lock me-1'></i> Unreserve IP";
    }
    confirmBtn.disabled = false;
    confirmBtn.innerHTML = "Unreserve";
}
window.confirmUnreserveAction = confirmUnreserveAction;
window.toggleReserveIp = toggleReserveIp;

  })();
} catch (e) {
  console.error("[Fatal Error in device.js]", e);
}
