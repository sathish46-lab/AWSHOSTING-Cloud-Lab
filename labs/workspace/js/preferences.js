/**
 * Wrapped with IIFE Error Boundary
 */
try {
  (function() {
    "use strict";


// ========================================================================
// Preferences — HTTP Proxies, Lifecycle, Startup Script
// ========================================================================

/**
 * Add a new proxy row dynamically
 */
function addProxyRow() {
    const list = document.getElementById('http-proxies-list');
    if (!list) return;
    const existingRows = list.querySelectorAll('.proxy-row');
    const idx = existingRows.length;
    const domains = window.USER_DOMAINS || [];
    let optionsHtml = '<option value="">Select...</option>';
    domains.forEach(d => {
        optionsHtml += `<option value="${d}">${d}</option>`;
    });
    const row = document.createElement('div');
    row.className = 'row align-items-center mb-3 proxy-row';
    row.setAttribute('data-index', idx);
    row.innerHTML = `
        <div class="col-md-4 col-12 mb-2 mb-md-0">
            <input type="number" name="proxy_port[]" class="form-control bg-dark bg-opacity-50 rounded-pill border-secondary border-opacity-25 text-white px-3 proxy-port" placeholder="Local Port (e.g. 8080)" min="1" max="65535">
        </div>
        <div class="col-md-7 col-10">
            <select name="proxy_domain[]" class="form-select bg-dark bg-opacity-50 rounded-pill border-secondary border-opacity-25 text-white px-3 proxy-domain-select" onchange="checkProxyDomainConflict(this)">
                ${optionsHtml}
            </select>
        </div>
        <div class="col-md-1 col-2 d-flex justify-content-end">
            <button type="button" class="btn rounded-circle d-flex align-items-center justify-content-center p-0 btn-remove-proxy" style="width: 36px; height: 36px; border: 1px solid #be185d; color: #be185d; background: transparent;" onclick="removeProxyRow(this)">
                <i class='bx bx-trash'></i>
            </button>
        </div>
    `;
    list.appendChild(row);
    updateProxyDomainOptions();
}

/**
 * Remove a proxy row
 */
function removeProxyRow(btn) {
    const row = btn.closest('.proxy-row');
    if (!row) return;
    const list = document.getElementById('http-proxies-list');
    const rows = list.querySelectorAll('.proxy-row');
    if (rows.length <= 1) {
        // Keep one empty row, just clear the inputs
        row.querySelector('.proxy-port').value = '';
        row.querySelector('.proxy-domain-select').value = '';
        updateProxyDomainOptions();
        return;
    }
    row.remove();
    updateProxyDomainOptions();
}

function updateProxyDomainOptions() {
    const selects = document.querySelectorAll('.proxy-domain-select');
    const selectedDomains = new Set();
    selects.forEach(select => {
        if (select.value) selectedDomains.add(select.value);
    });
    selects.forEach(select => {
        Array.from(select.options).forEach(option => {
            if (option.value === "") return;
            if (selectedDomains.has(option.value) && select.value !== option.value) {
                option.disabled = true;
            } else {
                option.disabled = false;
            }
        });
    });
}

/**
 * Check if selected domain is already exposed on port 80/443 (public web exposure)
 */
function checkProxyDomainConflict(selectEl) {
    updateProxyDomainOptions();
    const domain = selectEl.value;
    if (!domain) return;
    const usageMap = (typeof LabData !== 'undefined' && LabData.getDomainUsage) ? LabData.getDomainUsage() : {};
    const usage = usageMap[domain];
    if (usage && usage.usage === 'Public Exposure') {
        if (window.TomNotify) {
            TomNotify.show(
                `"${domain}" is already used for Expose to Web (port 80/443) on your ${usage.lab_type} lab. The HTTP Proxy will override that routing for this domain.`,
                'Domain Conflict',
                'warning',
                6000
            );
        }
    }
}

/**
 * Collect all preferences data from the form
 */
function collectPreferencesData() {
    // HTTP Proxies
    const proxies = [];
    const rows = document.querySelectorAll('#http-proxies-list .proxy-row');
    rows.forEach(row => {
        const port = row.querySelector('.proxy-port')?.value;
        const domain = row.querySelector('.proxy-domain-select')?.value;
        if (port && domain) {
            proxies.push({ port: parseInt(port), domain: domain });
        }
    });

    // Always-on
    const alwaysOn = document.getElementById('always-on-toggle')?.checked || false;

    // Init script
    let initScript = '#!/bin/bash\n';
    if (window.initScriptEditor) {
        initScript = window.initScriptEditor.getValue();
    } else {
        initScript = document.getElementById('init-script-editor')?.value || '#!/bin/bash\n';
    }

    // Sudo and Code-Server passwords
    const suPass = document.getElementById('sudo-pass-input')?.value || '';
    const codeServerPass = document.getElementById('code-server-pass-input')?.value || '';

    return {
        hash: window.SESSION_HASH,
        lab: window.LAB_TYPE || 'essentials',
        http_proxies: proxies,
        always_on: alwaysOn,
        init_script: initScript,
        su_pass: suPass,
        code_server_pass: codeServerPass
    };
}

/**
 * Save preferences to DB without redeploying
 */
async function savePreferences() {
    const btn = document.getElementById('btn-save-preferences');
    const originalHtml = btn.innerHTML;
    btn.classList.add('disabled');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Saving...';

    try {
        const data = collectPreferencesData();
        const response = await fetch('/api/labs/preferences_save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.status === 'success') {
            if (window.TomNotify) {
                let changed = false;
                let delay = 0;
                
                if (result.warning) {
                    setTimeout(() => TomNotify.show(result.warning, 'Action Required', 'warning', 6000), delay);
                    delay += 500;
                    changed = true;
                }
                
                if (result.changes && result.changes.passwords) {
                    setTimeout(() => TomNotify.show('Password changes saved — applied on the next redeploy.', 'Saved', 'info', 5000), delay);
                    delay += 500;
                    changed = true;
                }
                if (result.changes && result.changes.proxies) {
                    setTimeout(() => TomNotify.show('HTTP proxy changes saved. Fast Apply required.', 'Action Required', 'info', 5000), delay);
                    delay += 500;
                    changed = true;
                }
                if (result.changes && result.changes.init_script) {
                    setTimeout(() => TomNotify.show('Init script saved. Fast Apply required.', 'Action Required', 'info', 5000), delay);
                    changed = true;
                }
                if (!changed) {
                    TomNotify.show('Preferences saved successfully.', 'Saved', 'success', 3000);
                }
            }
        } else {
            if (window.TomNotify) TomNotify.show(result.error || 'Failed to save.', 'Error', 'warning', 4000);
        }
    } catch (e) {
        console.error('Save error:', e);
        if (window.TomNotify) TomNotify.show('Network error while saving.', 'Error', 'warning', 4000);
    } finally {
        btn.classList.remove('disabled');
        btn.innerHTML = originalHtml;
    }
}

/**
 * Save preferences AND apply changes (update Traefik + run init script) without full redeploy
 */
async function applyAndRedeploy() {
    const btn = document.getElementById('btn-apply-redeploy');
    const originalHtml = btn.innerHTML;
    btn.classList.add('disabled');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Applying...';

    Dashboard.resetTerminal();
    Dashboard.appendCommand(`labsctl apply-preferences --hash=${window.SESSION_HASH}`);

    try {
        const data = collectPreferencesData();
        const response = await fetch('/api/labs/preferences_apply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.status === 'success') {
            Dashboard.appendLog('[*] Preferences apply job queued. Streaming logs...');
        } else {
            Dashboard.appendLog(`[!] Error: ${result.error || 'Apply failed'}`);
            if (window.TomNotify) TomNotify.show(result.error || 'Failed to apply.', 'Error', 'warning', 4000);
        }
    } catch (e) {
        console.error('Apply error:', e);
        Dashboard.appendLog('[!] Network error during apply.');
    } finally {
        btn.classList.remove('disabled');
        btn.innerHTML = originalHtml;
    }
}

/**
 * Run the init script immediately inside the running container
 */
async function runInitScript() {
    const scriptContent = document.getElementById('init-script-editor')?.value || '';
    if (!scriptContent.trim()) {
        if (window.TomNotify) TomNotify.show('Script is empty.', 'Info', 'warning', 3000);
        return;
    }

    Dashboard.resetTerminal();
    Dashboard.appendCommand('bash /home/' + (window.LAB_USER || 'user') + '/init.sh');

    try {
        const response = await fetch('/api/labs/run_script', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                hash: window.SESSION_HASH,
                lab: window.LAB_TYPE || 'essentials',
                script: scriptContent
            })
        });
        const result = await response.json();
        if (result.status === 'success') {
            Dashboard.appendLog('[*] Script execution queued. Streaming output...');
            if (window.TomNotify) TomNotify.show('Script execution queued. Streaming output...', 'Success', 'success', 4000);
        } else {
            Dashboard.appendLog(`[!] Error: ${result.error || 'Script execution failed'}`);
            if (window.TomNotify && !result.error.includes("Lab is not running")) {
                TomNotify.show(result.error || 'Failed to run script.', 'Error', 'error', 4000);
            }
        }
    } catch (e) {
        console.error('Run script error:', e);
        Dashboard.appendLog('[!] Network error while running script.');
    }
}

// Attach domain conflict checker and auto-password generation to elements on load
window.onPageLoad( function() {
    document.querySelectorAll('.proxy-domain-select').forEach(function(sel) {
        sel.addEventListener('change', function() { checkProxyDomainConflict(this); });
    });


});

/**
 * Toggle visibility of password input
 */
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bx bx-show fs-5';
    } else {
        input.type = 'password';
        icon.className = 'bx bx-hide fs-5';
    }
}

/**
 * Client-side random password generation
 */
function generateNewPassword(inputId) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let newPass = '';
    for (let i = 0; i < 12; i++) {
        newPass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById(inputId);
    input.value = newPass;
    input.type = 'text'; // Show the newly generated pass
    
    // Update show/hide button icon to bx-show since it's visible now
    const parent = input.parentElement;
    const hideBtn = parent.querySelector('button[onclick*="togglePasswordVisibility"]');
    if (hideBtn) {
        const icon = hideBtn.querySelector('i');
        if (icon) icon.className = 'bx bx-show fs-5';
    }
}

/**
 * Copy password from current input value
 */
function copyFromInput(inputId) {
    const input = document.getElementById(inputId);
    copyText(input.value);
}
window.onPageLoad( updateProxyDomainOptions);


    

    // --- Explicit Window Exports for Inline HTML ---
    window.generateNewPassword = generateNewPassword;
    window.collectPreferencesData = collectPreferencesData;
    window.runInitScript = runInitScript;
    window.copyFromInput = copyFromInput;
    window.savePreferences = savePreferences;
    window.applyAndRedeploy = applyAndRedeploy;
    window.updateProxyDomainOptions = updateProxyDomainOptions;
    window.checkProxyDomainConflict = checkProxyDomainConflict;
    window.togglePasswordVisibility = togglePasswordVisibility;
    window.addProxyRow = addProxyRow;
    window.removeProxyRow = removeProxyRow;

  })();
} catch (e) {
  console.error("[Fatal Error in preferences.js]", e);
}
