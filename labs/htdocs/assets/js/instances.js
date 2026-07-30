/**
 * Instance Management JavaScript
 * Handles deploy, stop, redeploy, status polling, logs, and server panel toggle
 */
(function() {
    'use strict';

    // Status color mapping for badge-neon
    const STATUS_COLORS = {
        running:   'success',
        deploying: 'warning',
        starting:  'warning',
        stopping:  'danger',
        stopped:   'danger',
        error:     'danger',
        none:      'primary',
    };

    // Get instance hash from page (reads from deploymentsTab or body data attribute)
    function getInstanceHash() {
        const tab = document.getElementById('deploymentsTab');
        if (tab?.dataset.hash) return tab.dataset.hash;
        const body = document.querySelector('[data-instance-hash]');
        return body?.dataset.instanceHash || null;
    }

    // Update status badge with badge-neon classes
    function setBadge(status) {
        const badge = document.getElementById('deployStatusBadge');
        if (!badge) return;
        
        badge.className = badge.className.replace(/badge-neon-\w+/g, '');
        
        const colorClass = STATUS_COLORS[status] || 'primary';
        badge.classList.add('badge-neon', 'badge-neon-' + colorClass, 'rounded-pill', 'px-3', 'py-1');
        
        badge.textContent = status;
        badge.dataset.status = status;
    }

    // Toggle button loading state
    function setBtnLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            if (!btn.dataset.originalHtml) btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Processing';
        } else {
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            }
            btn.disabled = false;
        }
    }

    // Status polling
    let pollTimer = null;

    function startStatusPolling(hash) {
        stopStatusPolling();
        pollTimer = setInterval(async () => {
            try {
                const res = await fetch('/api/instances/build_status?hash=' + encodeURIComponent(hash));
                const data = await res.json();
                if (data.status !== 'success') return;
                const s = data.deployed_status || data.instance_status || 'unknown';
                setBadge(s);
                if (['running', 'stopped', 'error', 'none'].includes(s)) {
                    stopStatusPolling();
                    setTimeout(() => location.reload(), 500);
                }
            } catch (_) {}
        }, 3000);
    }

    function stopStatusPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    // Track busy state
    let isBusy = false;

    // Deploy instance
    async function deployInstance(hash, btn) {
        if (isBusy) return;
        isBusy = true;
        setBtnLoading(btn, true);
        if (window.appendInstanceLog) window.appendInstanceLog('[*] Queuing deployment...');
        try {
            const res = await fetch('/api/instances/deploy_instance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'hash=' + encodeURIComponent(hash)
            });
            const data = await res.json();
            if (data.status === 'success') {
                if (window.appendInstanceLog) window.appendInstanceLog('[✓] Job queued. Streaming logs...');
                setBadge('deploying');
                startStatusPolling(hash);
            } else {
                if (window.appendInstanceLog) window.appendInstanceLog('[!] ' + (data.error || 'Deploy failed'));
                setBtnLoading(btn, false);
                isBusy = false;
            }
        } catch (err) {
            if (window.appendInstanceLog) window.appendInstanceLog('[!] Network error');
            setBtnLoading(btn, false);
            isBusy = false;
        }
    }

    // Stop instance
    async function stopInstance(hash, btn) {
        if (isBusy) return;
        if (!confirm('Stop this instance?')) return;
        isBusy = true;
        setBtnLoading(btn, true);
        if (window.appendInstanceLog) window.appendInstanceLog('[*] Queuing stop...');
        try {
            const res = await fetch('/api/instances/stop_instance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'hash=' + encodeURIComponent(hash)
            });
            const data = await res.json();
            if (data.status === 'success') {
                if (window.appendInstanceLog) window.appendInstanceLog('[✓] Stop queued.');
                setBadge('stopping');
                startStatusPolling(hash);
            } else {
                if (window.appendInstanceLog) window.appendInstanceLog('[!] ' + (data.error || 'Stop failed'));
                setBtnLoading(btn, false);
                isBusy = false;
            }
        } catch (err) {
            if (window.appendInstanceLog) window.appendInstanceLog('[!] Network error');
            setBtnLoading(btn, false);
            isBusy = false;
        }
    }

    // Event delegation for all instance action buttons
    document.addEventListener('click', (e) => {
        const hash = getInstanceHash();
        if (!hash) return;

        // Deploy button (header or tab)
        const deployBtn = e.target.closest('.btn-seg-deploy');
        if (deployBtn) {
            e.preventDefault();
            deployInstance(hash, deployBtn);
            return;
        }

        // Redeploy button (header or tab)
        const redeployBtn = e.target.closest('.btn-seg-redeploy');
        if (redeployBtn) {
            e.preventDefault();
            deployInstance(hash, redeployBtn);
            return;
        }

        // Pause button (header or tab)
        const pauseBtn = e.target.closest('.btn-seg-pause');
        if (pauseBtn) {
            e.preventDefault();
            if (window.appendInstanceLog) window.appendInstanceLog('[!] Pause not yet implemented');
            return;
        }

        // Stop button (header or tab)
        const stopBtn = e.target.closest('.btn-seg-stop');
        if (stopBtn) {
            e.preventDefault();
            stopInstance(hash, stopBtn);
            return;
        }
    });

    // ========== Server Logs Panel (minimize/expand with DB persistence) ==========

    function initServerLogsPanel() {
        const container = document.getElementById('live-logs-container');
        const viewport = document.getElementById('terminal-viewport');
        if (!container || !viewport) return;

        const instanceHash = getInstanceHash();
        if (!instanceHash) return;

        let lineCount = 0;

        // Log append function (used by Build/Deploy tabs too)
        window.appendInstanceLog = function(msg) {
            const div = document.createElement('div');
            div.className = 'log-entry';
            div.style.whiteSpace = 'pre-wrap';
            div.style.wordBreak = 'break-all';

            if (typeof msg === 'object' && msg !== null && msg.log) msg = msg.log;
            if (typeof msg !== 'string') msg = JSON.stringify(msg);

            if (msg.startsWith('[✓]') || msg.includes('success') || msg.includes('Complete')) {
                div.style.color = '#a6e3a1';
            } else if (msg.startsWith('[!]') || msg.toLowerCase().includes('error') || msg.toLowerCase().includes('failed')) {
                div.style.color = '#f38ba8';
            } else if (msg.startsWith('[*]') || msg.includes('reload')) {
                div.style.color = '#ffa502';
            }

            div.innerText = msg;
            container.appendChild(div);
            viewport.scrollTop = viewport.scrollHeight;

            lineCount++;
            if (lineCount % 200 === 0) {
                while (container.children.length > 1000) container.removeChild(container.firstChild);
            }

            if (typeof msg === 'string' && msg.includes('[*] reload')) {
                setTimeout(() => {
                    if (typeof htmx !== 'undefined') {
                        htmx.ajax('GET', location.href, '#main-content');
                    } else if (window.__loadInstanceTab) {
                        window.__loadInstanceTab('deployments');
                    }
                }, 2500);
            }
        };

        // LogSocket connection
        const dot = document.getElementById('mq-status-dot');
        let logSocket = null;

        function connectLogs() {
            if (logSocket && logSocket.isConnected) return;
            logSocket = new TomSocketClient();
            logSocket.connect(
                'logs.' + instanceHash,
                (data) => window.appendInstanceLog(data),
                { dot: dot },
                () => {
                    if (dot) { dot.style.color = '#a6e3a1'; }
                }
            );
            window.__instanceLogSocket = logSocket;
        }

        // Toggle minimize/expand (with DB persistence)
        const logsBody = document.getElementById('terminal-viewport');
        const toggleBtn = document.getElementById('instanceLogsToggleBtn');
        const chevrons = document.querySelectorAll('.server-logs-chevron');

        function setMinimizedState(isMinimized) {
            if (isMinimized) {
                logsBody.classList.add('logs-minimized');
                chevrons.forEach(chevron => {
                    chevron.classList.remove('bx-chevron-down');
                    chevron.classList.add('bx-chevron-up');
                });
            } else {
                logsBody.classList.remove('logs-minimized');
                chevrons.forEach(chevron => {
                    chevron.classList.remove('bx-chevron-up');
                    chevron.classList.add('bx-chevron-down');
                });
            }
        }

        if (toggleBtn && logsBody) {
            const isMinimized = toggleBtn.getAttribute('data-minimized') === 'true';

            // Auto-scroll when new logs arrive while minimized
            const observer = new MutationObserver(() => {
                if (toggleBtn.getAttribute('data-minimized') === 'true') {
                    logsBody.scrollTop = logsBody.scrollHeight;
                }
            });
            observer.observe(document.getElementById('live-logs-container'),
                { childList: true, subtree: true });

            if (isMinimized) {
                setTimeout(() => { logsBody.scrollTop = logsBody.scrollHeight; }, 100);
            }

            toggleBtn.addEventListener('click', async function(e) {
                if (e.target.closest('.terminal-info-wrapper')) return;

                const willMinimize = !logsBody.classList.contains('logs-minimized');
                setMinimizedState(willMinimize);
                toggleBtn.setAttribute('data-minimized', willMinimize ? 'true' : 'false');

                // Save state in the database via the API
                const formData = new FormData();
                formData.append('preference_id', 'instance_serverlogs_min');
                formData.append('value', willMinimize ? '1' : '0');

                try {
                    await fetch('/api/user/preference_save', {
                        method: 'POST',
                        body: formData
                    });
                } catch (error) {
                    console.error("Failed to save UI preference:", error);
                }
            });
        }

        // Auto-connect on page load
        window.addEventListener('load', () => connectLogs());

        // Expose for tab scripts
        window.__connectInstanceLogs = connectLogs;
    }

    // ========== Initialize on DOM ready ==========
    document.addEventListener('DOMContentLoaded', () => {
        initServerLogsPanel();
        isBusy = false;
    });

    // Expose for other scripts
    window.InstanceManager = {
        deploy: deployInstance,
        stop: stopInstance,
        setBadge,
        startStatusPolling,
        stopStatusPolling,
        getInstanceHash
    };
})();
