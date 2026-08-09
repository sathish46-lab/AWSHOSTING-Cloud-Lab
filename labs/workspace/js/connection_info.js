/**
 * Wrapped with IIFE Error Boundary
 */
try {
  (function() {
    "use strict";


function openConnectionModal(hash, name, status) {
    // 1. Set static info
    const modalEl = document.getElementById('connectionInfoModal');
    if (!modalEl) return;

    document.getElementById('modalLabName').textContent = name;

    // 2. Reset View State
    const loadingEl = document.getElementById('modalLoading');
    const offlineEl = document.getElementById('modalOffline');
    const contentEl = document.getElementById('modalContent');
    const fieldsEl = document.getElementById('connectionFields');

    loadingEl.classList.remove('d-none');
    offlineEl.classList.add('d-none');
    contentEl.classList.add('d-none');
    fieldsEl.innerHTML = '';

    // 3. Show Modal
    const modal = new coreui.Modal(modalEl, { backdrop: true, keyboard: true });
    modal.show();

    // 4. Check Status
    if (status !== 'running') {
        loadingEl.classList.add('d-none');
        offlineEl.classList.remove('d-none');
        return;
    }

    // 5. Fetch Technical Connection Info (returns HTML)
    fetch(`/api/labs/connection_info?hash=${hash}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.text();
        })
        .then(html => {
            loadingEl.classList.add('d-none');

            if (!html || html.trim() === '' || html.trim().startsWith('<div class="text-center text-danger')) {
                fieldsEl.innerHTML = '<div class="text-center text-danger py-3"><i class="bx bx-error-circle fs-4 mb-2 d-block"></i>Failed to load connection details.</div>';
                contentEl.classList.remove('d-none');
                return;
            }

            contentEl.classList.remove('d-none');
            fieldsEl.innerHTML = html;

            // Copy button functionality
            fieldsEl.querySelectorAll('[data-copy]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const text = this.getAttribute('data-copy');
                    navigator.clipboard.writeText(text).then(() => {
                        const icon = this.querySelector('i');
                        if (icon) {
                            icon.className = 'bx bx-check';
                            setTimeout(() => { icon.className = 'bx bx-copy'; }, 1500);
                        }
                    });
                });
            });
        })
        .catch(err => {
            console.error('Connection info error:', err);
            loadingEl.classList.add('d-none');
            fieldsEl.innerHTML = '<div class="text-center text-danger py-3"><i class="bx bx-error-circle fs-4 mb-2 d-block"></i>Network error. Please try again.</div>';
            contentEl.classList.remove('d-none');
        });
}

    

    // --- Explicit Window Exports for Inline HTML ---
    window.openConnectionModal = openConnectionModal;

  })();
} catch (e) {
  console.error("[Fatal Error in connection_info.js]", e);
}
