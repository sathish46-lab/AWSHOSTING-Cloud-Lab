/**
 * Account Settings Modal — Global overlay for account management
 * Opens from header dropdown, loads tab data lazily from /api/account/settings
 */
(function() {
    let settingsLoaded = false;
    let settingsData = null;

    // ── Open Account Settings ──
    window.openAccountSettings = function() {
        const modal = new coreui.Modal(document.getElementById('accountSettingsModal'));
        modal.show();
        if (!settingsLoaded) loadAccountSettings();
    };

    // ── Tab Switching ──
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.acct-tab-btn');
        if (!btn) return;
        const tabId = btn.dataset.tab;
        if (!tabId) return;

        // Update active button
        document.querySelectorAll('.acct-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update active pane
        document.querySelectorAll('.acct-tab-pane').forEach(p => p.classList.remove('active'));
        const pane = document.getElementById(tabId);
        if (pane) pane.classList.add('active');

        // Lazy-load data for tabs that need it
        if ((tabId === 'acct-tab-limits' || tabId === 'acct-tab-storage' || tabId === 'acct-tab-ssh' || tabId === 'acct-tab-security') && !settingsLoaded) {
            loadAccountSettings();
        }
    });

    // ── Load Settings Data ──
    async function loadAccountSettings() {
        try {
            const res = await fetch('/api/account/settings');
            const data = await res.json();
            if (!data.success) return;
            settingsData = data;
            settingsLoaded = true;
            renderLimits(data);
            renderStorage(data);
            renderSSHKeys(data);
            renderSecurity(data);
        } catch (err) {
            console.error('Failed to load account settings:', err);
        }
    }

    // ── Render Limits Tab ──
    function renderLimits(data) {
        const limits = data.limits;
        const storage = data.storage;
        const services = data.services;

        function barColorClass(pct) {
            if (pct >= 80) return 'bar-danger';
            if (pct >= 50) return 'bar-warning';
            return 'bar-success';
        }

        const platformItems = [
            { icon: 'bx-desktop', label: 'Devices', key: 'devices', href: '/devices' },
            { icon: 'bx-globe', label: 'Domains', key: 'domains', href: '/domains' },
            { icon: 'bx-terminal', label: 'Running labs', key: 'labs', href: '/labs' },
            { icon: 'bx-copy', label: 'Lab copies', key: 'copies', href: '/instances' },
            { icon: 'bx-layer', label: 'Custom deployments', key: 'deployments', href: '/instances' }
        ];

        let platformHtml = '';
        platformItems.forEach(item => {
            const l = limits[item.key];
            const pct = l.limit > 0 ? Math.round((l.used / l.limit) * 100) : 0;
            const colorCls = l.used === 0 ? '' : barColorClass(pct);
            platformHtml += `
                <div class="acct-limit-row">
                    <div class="acct-limit-icon"><i class="bx ${item.icon}"></i></div>
                    <div class="acct-limit-label"><a href="${item.href}">${item.label}</a></div>
                    <div class="acct-limit-progress"><div class="acct-limit-bar ${colorCls}" style="width:${pct}%"></div></div>
                    <div class="acct-limit-value">${l.used} <span class="acct-limit-sep">/</span> ${l.limit}</div>
                </div>`;
        });
        document.getElementById('acctLimitsPlatform').innerHTML = platformHtml;

        const sPct = storage.home.limit_gb > 0 ? Math.round((storage.home.used_gb / storage.home.limit_gb) * 100) : 0;
        const sColorCls = storage.home.used_gb === 0 ? '' : 'bar-info';
        document.getElementById('acctLimitsStorage').innerHTML = `
            <div class="acct-limit-row">
                <div class="acct-limit-icon"><i class="bx bx-hdd"></i></div>
                <div class="acct-limit-label">
                    <span>Home storage</span>
                    ${storage.home.enforced ? '<span class="acct-limit-badge" style="background:rgba(45,180,100,0.15);color:#2db464;">enforced</span>' : ''}
                </div>
                <div class="acct-limit-progress"><div class="acct-limit-bar ${sColorCls}" style="width:${sPct}%"></div></div>
                <div class="acct-limit-value">${storage.home.used_gb} <span class="acct-limit-sep">/</span> ${storage.home.limit_gb} GB</div>
            </div>`;

        // Services
        const svcList = [
            { key: 'mysql', label: 'MySQL Server', desc: 'databases', icon: 'bx-data' },
            { key: 'mariadb', label: 'MariaDB Server', desc: 'databases', icon: 'bx-data' },
            { key: 'postgresql', label: 'PostgreSQL Server', desc: 'databases', icon: 'bx-data' },
            { key: 'mongodb', label: 'MongoDB Server', desc: 'databases', icon: 'bx-data' },
            { key: 'rabbitmq', label: 'RabbitMQ Server', desc: 'vhosts', icon: 'bx-data' },
            { key: 'redis', label: 'Redis Server', desc: '', icon: 'bx-data' }
        ];
        const svcPct = services.total.limit > 0 ? Math.round((services.total.used / services.total.limit) * 100) : 0;
        const svcColorCls = services.total.used === 0 ? '' : 'bar-info';
        let svcHtml = `
            <div class="acct-limit-row">
                <div class="acct-limit-icon"><i class="bx bx-data"></i></div>
                <div class="acct-limit-label">Services total<span class="acct-limit-desc">one shared ceiling — every service user below counts against it</span></div>
                <div class="acct-limit-progress"><div class="acct-limit-bar ${svcColorCls}" style="width:${svcPct}%"></div></div>
                <div class="acct-limit-value">${services.total.used} <span class="acct-limit-sep">/</span> ${services.total.limit}</div>
            </div>`;
        svcList.forEach(svc => {
            const s = services[svc.key];
            if (!s) return;
            const count = svc.key === 'redis' ? s.used : s.databases || s.vhosts || 0;
            svcHtml += `
                <div class="acct-limit-row">
                    <div class="acct-limit-icon"><i class="bx ${svc.icon}"></i></div>
                    <div class="acct-limit-label">${svc.label}<span class="acct-limit-desc">up to ${s.limit} ${svc.desc} per user</span></div>
                    <div class="acct-limit-progress"></div>
                    <div class="acct-limit-value">${count} <span class="text-body-secondary" style="font-size:0.7rem;">· shared</span></div>
                </div>`;
        });
        document.getElementById('acctLimitsServices').innerHTML = svcHtml;
    }

    // ── Render Storage Tab ──
    function renderStorage(data) {
        const s = data.storage;
        const poolBadge = document.getElementById('acctStoragePoolBadge');
        if (poolBadge) poolBadge.textContent = `pool: ${s.pool.id} · ${s.pool.fs}`;

        document.getElementById('acctStorageBreakdown').innerHTML = `
            <div class="acct-limit-row">
                <div class="acct-limit-icon"><i class="bx bx-hdd"></i></div>
                <div class="acct-limit-label"><strong>Home storage</strong><br><span class="small text-body-secondary">your files inside labs</span></div>
                <div class="acct-limit-progress"><div class="acct-limit-bar" style="width:${s.home.percent}%;background:var(--cui-primary,#677378)"></div></div>
                <div class="acct-limit-value">${s.home.used_gb} / ${s.home.limit_gb} GB</div>
            </div>
            <div class="acct-limit-row">
                <div class="acct-limit-icon"><i class="bx bx-cloud-upload"></i></div>
                <div class="acct-limit-label"><strong>My Files (S3)</strong><br><span class="small text-body-secondary">uploads: themes, avatars, attachments</span></div>
                <div class="acct-limit-progress"></div>
                <div class="acct-limit-value">${s.s3.used_gb} GB</div>
            </div>
            <div class="acct-limit-row">
                <div class="acct-limit-icon"><i class="bx bx-layer"></i></div>
                <div class="acct-limit-label"><strong>Container layers</strong><br><span class="small text-body-secondary">changes your running labs wrote outside home (${s.containers.count} containers)</span></div>
                <div class="acct-limit-progress"></div>
                <div class="acct-limit-value">${s.containers.used_gb} GB</div>
            </div>`;
    }

    // ── Render SSH Keys Tab ──
    function renderSSHKeys(data) {
        const keys = data.ssh_keys || [];
        if (keys.length === 0) {
            document.getElementById('acctPlatformKeys').innerHTML = `
                <div class="text-center text-body-secondary py-3">No platform keys yet.
                    <button type="button" class="btn btn-sm btn-link p-0 align-baseline" onclick="document.getElementById('acctAddKeySection').classList.remove('d-none')">Add your first key</button>.
                </div>`;
            return;
        }
        let html = '';
        keys.forEach(key => {
            const expired = key.expires_at && key.expires_at < Date.now() / 1000;
            const expText = key.expires_at ? new Date(key.expires_at * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Never';
            const expClass = expired ? 'text-danger' : 'text-warning';
            html += `
                <div class="d-flex align-items-center gap-2 py-2 border-bottom" style="border-color:rgba(255,255,255,0.04) !important;">
                    <i class="bx bx-key text-secondary" style="font-size:1.1rem;"></i>
                    <div class="min-w-0 flex-grow-1">
                        <div class="small fw-semibold text-truncate">${escHtml(key.title)}</div>
                        <code class="small text-body-secondary" style="font-size:0.7rem;">${escHtml(key.fingerprint)}</code>
                        <span class="small ms-2 ${expClass}" style="font-size:0.7rem;">${expText}</span>
                    </div>
                    <button class="btn btn-sm btn-outline-danger border-0 rounded-pill" style="font-size:0.7rem;padding:2px 8px;" onclick="deleteAccountKey('${escHtml(key.id)}')">
                        <i class="bx bx-trash"></i>
                    </button>
                </div>`;
        });
        document.getElementById('acctPlatformKeys').innerHTML = html;
    }

    // ── Render Security Tab ──
    function renderSecurity(data) {
        const sessions = data.sessions || {};
        const active = sessions.active || [];
        const logins = sessions.recent_logins || [];

        document.getElementById('acctSessionCount').textContent = `(${active.length})`;

        if (active.length === 0) {
            document.getElementById('acctActiveSessions').innerHTML = '<div class="text-body-secondary small py-2">No active sessions.</div>';
        } else {
            let html = '';
            active.forEach(s => {
                const icon = s.mobile ? 'bx-mobile-alt' : 'bx-desktop';
                const deviceLabel = `${s.browser} · ${s.os}`;
                const currentBadge = s.is_current
                    ? '<span class="badge rounded-pill ms-2" style="background:rgba(var(--cui-success-rgb,45,180,100),0.15);color:var(--cui-success,#2db464);font-size:0.6rem;">This device</span>'
                    : '';
                const timeAgo = timeAgoFormat(s.last_activity);
                html += `
                    <div class="d-flex align-items-center gap-3 py-2 border-bottom" style="border-color:rgba(255,255,255,0.04) !important;">
                        <i class="bx ${icon} text-secondary" style="font-size:1.4rem;"></i>
                        <div class="min-w-0 flex-grow-1">
                            <div class="small fw-semibold">${escHtml(deviceLabel)}${currentBadge}</div>
                            <div class="text-body-secondary" style="font-size:0.75rem;">${escHtml(s.ip)} · ${timeAgo}</div>
                        </div>
                    </div>`;
            });
            document.getElementById('acctActiveSessions').innerHTML = html;
        }

        if (logins.length === 0) {
            document.getElementById('acctRecentLogins').innerHTML = '<div class="text-body-secondary small py-2">No login history.</div>';
        } else {
            let html = '';
            logins.forEach(l => {
                html += `
                    <div class="d-flex align-items-center gap-2 py-2 border-bottom" style="border-color:rgba(255,255,255,0.04) !important;">
                        <i class="bx bx-time text-secondary"></i>
                        <div class="small">${l.formatted}</div>
                    </div>`;
            });
            document.getElementById('acctRecentLogins').innerHTML = html;
        }
    }

    // ── Time Ago Helper ──
    function timeAgoFormat(timestamp) {
        const seconds = Math.floor(Date.now() / 1000 - timestamp);
        if (seconds < 60) return 'just now';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m ago`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours}h ago`;
        const days = Math.floor(hours / 24);
        if (days < 7) return `${days}d ago`;
        return new Date(timestamp * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // ── Delete SSH Key ──
    window.deleteAccountKey = async function(keyId) {
        if (!confirm('Revoke this SSH key?')) return;
        try {
            const formData = new FormData();
            formData.append('key_id', keyId);
            const res = await fetch('/api/account/ssh_delete', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                if (window.TomNotify) TomNotify.show('Key revoked successfully.', 'Success', 'success');
                settingsLoaded = false;
                loadAccountSettings();
            } else {
                if (window.TomNotify) TomNotify.show(data.error || 'Failed to revoke key.', 'Error', 'danger');
            }
        } catch (err) {
            if (window.TomNotify) TomNotify.show('Network error.', 'Error', 'danger');
        }
    };

    // ── SSH Add Form ──
    document.addEventListener('submit', function(e) {
        if (e.target.id !== 'acctSshAddForm') return;
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        fetch('/api/account/ssh_add', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (window.TomNotify) TomNotify.show('Key added successfully!', 'Success', 'success');
                    form.reset();
                    document.getElementById('acctAddKeySection').classList.add('d-none');
                    settingsLoaded = false;
                    loadAccountSettings();
                } else {
                    if (window.TomNotify) TomNotify.show(data.error || 'Failed to add key.', 'Error', 'danger');
                }
            })
            .catch(() => { if (window.TomNotify) TomNotify.show('Network error.', 'Error', 'danger'); });
    });

    // ── Avatar Upload ──
    document.addEventListener('change', function(e) {
        if (e.target.id !== 'acctAvatarUpload') return;
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 800 * 1024) {
            if (window.TomNotify) TomNotify.show('Max file size is 800KB.', 'Error', 'danger');
            return;
        }
        const formData = new FormData();
        formData.append('avatar', file);
        fetch('/api/account/update_avatar', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success' && data.avatar_url) {
                    document.getElementById('acctSettingsAvatar').src = data.avatar_url;
                    // Update header avatar too
                    document.querySelectorAll('.avatar-img').forEach(img => { img.src = data.avatar_url; });
                    if (window.TomNotify) TomNotify.show('Avatar updated!', 'Success', 'success');
                } else {
                    if (window.TomNotify) TomNotify.show(data.error || 'Upload failed.', 'Error', 'danger');
                }
            })
            .catch(() => { if (window.TomNotify) TomNotify.show('Network error.', 'Error', 'danger'); });
    });

    // ── Appearance: Blur Toggle ──
    document.addEventListener('change', function(e) {
        if (e.target.id !== 'acctBlurToggle') return;
        const enabled = e.target.checked;
        if (window.TomVisuals) TomVisuals.toggleBlur(enabled);
    });

    // ── Appearance: Color Mode ──
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.acct-mode-btn');
        if (!btn) return;
        document.querySelectorAll('.acct-mode-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const mode = btn.dataset.mode;
        if (window.TomVisuals) TomVisuals.switchBGTheme(mode);
        // Save preference
        fetch('/api/account/theme_save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme: mode })
        });
    });

    // ── Storage Lens ──
    let lensCurrentPath = '';

    window.openStorageLens = function() {
        lensCurrentPath = '';
        const lensModal = new coreui.Modal(document.getElementById('storageLensModal'));
        lensModal.show();
        loadStorageLens(false);
    };

    window.refreshAccountStorage = function() {
        settingsLoaded = false;
        loadAccountSettings();
        if (window.TomNotify) TomNotify.show('Storage re-scanned.', 'Success', 'success', 2000);
    };

    async function loadStorageLens(isRefresh) {
        const grid = document.getElementById('lensGrid');
        grid.innerHTML = '<div class="lens-loading"><i class="bx bx-loader-alt bx-spin mb-2" style="font-size:2rem;"></i><div class="fw-semibold">Measuring...</div></div>';

        const startTime = Date.now();
        try {
            let url = '/api/account/storage_lens';
            if (lensCurrentPath) url += '?path=' + encodeURIComponent(lensCurrentPath);
            if (isRefresh) url += (lensCurrentPath ? '&' : '?') + 'action=refresh';
            const res = await fetch(url);
            const data = await res.json();

            // Minimum 3s loading for smooth UX
            const elapsed = Date.now() - startTime;
            if (elapsed < 3000) {
                await new Promise(r => setTimeout(r, 3000 - elapsed));
            }

            if (!data.success) {
                grid.innerHTML = `<div class="lens-loading text-danger"><i class="bx bx-error mb-2" style="font-size:2rem;"></i><div class="fw-semibold">${escHtml(data.error || 'Failed to load')}</div></div>`;
                return;
            }
            renderStorageLens(data);
        } catch (err) {
            const elapsed = Date.now() - startTime;
            if (elapsed < 3000) {
                await new Promise(r => setTimeout(r, 3000 - elapsed));
            }
            grid.innerHTML = '<div class="lens-loading text-danger"><i class="bx bx-wifi-off mb-2" style="font-size:2rem;"></i><div class="fw-semibold">Network error</div><div class="small text-body-secondary mt-1">Check your connection and try again</div></div>';
        }
    }

    window.refreshStorageLens = function() {
        loadStorageLens(true);
    };

    window.navigateToLensPath = function(path) {
        lensCurrentPath = path;
        loadStorageLens(false);
    };

    function renderStorageLens(data) {
        const totalGb = (data.total_bytes / (1024*1024*1024)).toFixed(1);
        const usedMb = data.quota.used_mb;
        const usedGb = (usedMb / 1024).toFixed(1);
        const pct = data.quota.limit_gb > 0 ? Math.min(100, Math.round((usedMb / (data.quota.limit_gb * 1024)) * 100)) : 0;

        document.getElementById('lensVolumeBadge').textContent = totalGb + ' GB';
        document.getElementById('lensProgressBar').style.width = pct + '%';
        document.getElementById('lensProgressText').textContent = `${usedGb} / ${data.quota.limit_gb} GB`;
        document.getElementById('lensPoolBadge').textContent = `pool: ${data.pool.id} · ${data.pool.fs}`;
        document.getElementById('lensRefreshCount').textContent = `${data.remaining_refreshes} refresh${data.remaining_refreshes !== 1 ? 'es' : ''} left`;

        // Breadcrumbs
        const bc = document.getElementById('lensBreadcrumbs');
        if (bc && data.breadcrumbs) {
            let bcHtml = '';
            data.breadcrumbs.forEach((crumb, i) => {
                if (i > 0) bcHtml += ' <span class="text-secondary">/</span> ';
                if (i < data.breadcrumbs.length - 1) {
                    bcHtml += `<button type="button" class="btn btn-sm btn-ghost-primary stg-crumb" onclick="navigateToLensPath('${escHtml(crumb.path)}')">${escHtml(crumb.name)}</button>`;
                } else {
                    bcHtml += `<button type="button" class="btn btn-sm btn-ghost-primary stg-crumb fw-bold">${escHtml(crumb.name)}</button>`;
                }
            });
            bc.innerHTML = bcHtml;
        }

        // Grid
        const grid = document.getElementById('lensGrid');
        if (!data.entries || data.entries.length === 0) {
            grid.innerHTML = '<div class="col-12 text-center py-4 text-secondary">No items found.</div>';
            return;
        }

        let html = '';
        data.entries.forEach((entry, idx) => {
            const sizeStr = formatBytes(entry.bytes);
            const modDate = entry.mtime ? new Date(entry.mtime * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
            const displayName = entry.label || entry.name;
            const isFeatured = idx === 0 && entry.bytes > 0 && entry.type === 'dir';
            const isSecondLarge = idx === 1 && entry.bytes > 0 && entry.type === 'dir';
            const isDir = entry.type === 'dir';
            const isLab = entry.name.startsWith('.') && entry.name !== '.';
            const canDelete = isLab && entry.name !== 'home';
            const relPath = lensCurrentPath ? lensCurrentPath + '/' + entry.name : entry.name;

            const gridClass = isFeatured ? 'stg-lens-grid-item--featured' : (isSecondLarge ? 'stg-lens-grid-item--second' : '');

            html += `
                <div class="stg-lens-grid-item ${gridClass}" style="--i:${idx}">
                    <div class="stg-lens-card ${isDir ? 'lens-card-dir' : ''}" ${isDir ? `onclick="navigateToLensPath('${escHtml(relPath)}')"` : ''} style="${isDir ? 'cursor:pointer;' : ''}">
                        <div class="d-flex align-items-start gap-2 min-w-0">
                            <i class="bx ${isDir ? 'bx-folder text-primary' : 'bx-file'} fs-4 flex-shrink-0"></i>
                            <div class="fw-semibold text-truncate" style="max-width:100%;font-size:0.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escHtml(displayName)}">${escHtml(displayName)}</div>
                        </div>
                        <div class="d-flex align-items-end justify-content-between mt-2">
                            <span class="fw-bold font-monospace" style="font-size:1.3rem">${sizeStr}</span>
                            ${isDir ? `<span class="text-body-secondary small">${entry.items.toLocaleString()} items</span>` : ''}
                        </div>
                        <div class="text-body-secondary small mt-1 text-truncate stg-lens-card-date">Modified ${modDate}</div>
                        ${canDelete ? `
                        <button type="button" class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 lens-card-delete" title="Delete" onclick="event.stopPropagation();deleteLensItem('${escHtml(entry.name)}','${escHtml(displayName)}',${entry.bytes})">
                            <i class="bx bx-trash"></i>
                        </button>` : ''}
                    </div>
                </div>`;
        });
        grid.innerHTML = html;
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    }

    window.deleteLensItem = function(name, label, bytes) {
        const sizeStr = formatBytes(bytes);
        const delModal = document.getElementById('lensDeleteModal');
        delModal.querySelector('.lens-del-name').textContent = label;
        delModal.querySelector('.lens-del-size').textContent = sizeStr;
        delModal.querySelector('.lens-del-btn').onclick = async function() {
            this.disabled = true;
            this.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i>Deleting...';
            try {
                const res = await fetch('/api/account/storage_lens', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'delete', path: lensCurrentPath ? lensCurrentPath + '/' + name : name })
                });
                const data = await res.json();
                coreui.Modal.getInstance(delModal).hide();
                this.disabled = false;
                this.innerHTML = 'Delete';
                if (data.success) {
                    if (window.TomNotify) TomNotify.show(`Freed ${sizeStr}.`, 'Storage Lens', 'success');
                    loadStorageLens(false);
                } else {
                    if (window.TomNotify) TomNotify.show(data.error || 'Delete failed.', 'Error', 'danger');
                }
            } catch (err) {
                coreui.Modal.getInstance(delModal).hide();
                this.disabled = false;
                this.innerHTML = 'Delete';
                if (window.TomNotify) TomNotify.show('Network error.', 'Error', 'danger');
            }
        };
        new coreui.Modal(delModal).show();
    };

    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Profile Form Submission ──
    document.addEventListener('submit', function(e) {
        if (e.target.id !== 'acctProfileForm') return;
        e.preventDefault();
        const form = e.target;
        const saveBtn = document.getElementById('acctProfileSaveBtn');
        const savedMsg = document.getElementById('acctProfileSaved');
        const errorMsg = document.getElementById('acctProfileError');
        const origText = saveBtn.innerHTML;

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Saving...';
        savedMsg.classList.add('d-none');
        errorMsg.classList.add('d-none');

        const formData = new FormData(form);
        fetch('/api/account/update_profile', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    savedMsg.classList.remove('d-none');
                    const firstName = form.querySelector('[name="first_name"]').value.trim();
                    const lastName = form.querySelector('[name="last_name"]').value.trim();
                    const fullName = [firstName, lastName].filter(Boolean).join(' ') || null;
                    if (fullName) {
                        const dn = document.getElementById('acctSettingsDisplayName');
                        if (dn) dn.textContent = fullName;
                        document.querySelectorAll('.user-name, .profile-name, #header-user-name').forEach(el => {
                            if (el.id === 'header-user-name' || el.classList.contains('user-name')) el.textContent = fullName;
                        });
                    }
                    setTimeout(() => savedMsg.classList.add('d-none'), 2000);
                } else {
                    errorMsg.textContent = data.error || 'Failed to save.';
                    errorMsg.classList.remove('d-none');
                }
            })
            .catch(() => { errorMsg.textContent = 'Network error.'; errorMsg.classList.remove('d-none'); })
            .finally(() => { saveBtn.disabled = false; saveBtn.innerHTML = origText; });
    });

    // ── 2FA State ──
    let acct2faEnabled = false;
    let acct2faDisabling = false;
    let acct2faTimerInterval = null;
    let acct2faResendTimeout = null;

    function init2FAStatus() {
        fetch('/api/account/settings')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                render2FAStatus();
            })
            .catch(() => {});
    }

    function render2FAStatus() {
        const statusEl = document.getElementById('acct2faStatus');
        const toggleBtn = document.getElementById('acct2faToggleBtn');
        if (acct2faEnabled) {
            statusEl.innerHTML = '<span class="text-success"><i class="bx bx-check-circle me-1"></i>Enabled</span>';
            toggleBtn.className = 'btn btn-sm btn-outline-danger rounded-pill px-3 d-inline-block';
            toggleBtn.textContent = 'Disable';
        } else {
            statusEl.innerHTML = '<span class="text-body-secondary"><i class="bx bx-shield me-1"></i>Not enabled</span>';
            toggleBtn.className = 'btn btn-sm btn-outline-success rounded-pill px-3 d-inline-block';
            toggleBtn.textContent = 'Enable 2FA';
        }
    }

    document.addEventListener('click', function(e) {
        if (e.target.id !== 'acct2faToggleBtn') return;
        acct2faDisabling = acct2faEnabled;
        if (acct2faDisabling && !confirm('Disable two-factor authentication?')) return;
        send2faOtp(acct2faDisabling ? 'disable' : 'enable');
    });

    function send2faOtp(action) {
        const otpSection = document.getElementById('acct2faOtpSection');
        const errorEl = document.getElementById('acct2faError');
        const successEl = document.getElementById('acct2faSuccess');
        const resendBtn = document.getElementById('acct2faResendBtn');
        const verifyBtn = document.getElementById('acct2faVerifyBtn');

        errorEl.classList.add('d-none');
        successEl.classList.add('d-none');
        otpSection.classList.remove('d-none');
        verifyBtn.disabled = false;
        resendBtn.classList.add('d-none');

        fetch('/api/account/send_2fa_otp' + (action === 'disable' ? '?action=disable' : ''))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    start2faTimer();
                    document.getElementById('acct2faOtpInput').value = '';
                    document.getElementById('acct2faOtpInput').focus();
                } else {
                    errorEl.textContent = data.error || 'Failed to send OTP.';
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => { errorEl.textContent = 'Network error.'; errorEl.classList.remove('d-none'); });
    }

    function start2faTimer() {
        let seconds = 60;
        const timerEl = document.getElementById('acct2faTimer');
        const resendBtn = document.getElementById('acct2faResendBtn');
        resendBtn.classList.add('d-none');
        clearInterval(acct2faTimerInterval);
        clearTimeout(acct2faResendTimeout);

        acct2faTimerInterval = setInterval(() => {
            seconds--;
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            if (seconds <= 0) {
                clearInterval(acct2faTimerInterval);
                timerEl.textContent = '00:00';
                resendBtn.classList.remove('d-none');
            }
        }, 1000);
    }

    document.addEventListener('click', function(e) {
        if (e.target.id !== 'acct2faVerifyBtn') return;
        const otpInput = document.getElementById('acct2faOtpInput');
        const otp = otpInput.value.trim();
        const errorEl = document.getElementById('acct2faError');
        const successEl = document.getElementById('acct2faSuccess');
        const verifyBtn = document.getElementById('acct2faVerifyBtn');

        if (!/^\d{6}$/.test(otp)) {
            errorEl.textContent = 'Enter a valid 6-digit code.';
            errorEl.classList.remove('d-none');
            return;
        }

        verifyBtn.disabled = true;
        errorEl.classList.add('d-none');
        successEl.classList.add('d-none');

        const endpoint = acct2faDisabling ? '/api/account/disable_2fa' : '/api/account/verify_2fa';
        const formData = new FormData();
        formData.append('otp', otp);

        fetch(endpoint, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    acct2faEnabled = !acct2faDisabling;
                    render2FAStatus();
                    successEl.textContent = acct2faEnabled ? '2FA enabled successfully!' : '2FA disabled.';
                    successEl.classList.remove('d-none');
                    clearInterval(acct2faTimerInterval);
                    document.getElementById('acct2faOtpSection').classList.add('d-none');
                    otpInput.value = '';
                } else {
                    errorEl.textContent = data.error || 'Verification failed.';
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => { errorEl.textContent = 'Network error.'; errorEl.classList.remove('d-none'); })
            .finally(() => { verifyBtn.disabled = false; });
    });

    document.addEventListener('click', function(e) {
        if (e.target.id !== 'acct2faResendBtn') return;
        send2faOtp(acct2faDisabling ? 'disable' : 'enable');
    });

    document.addEventListener('keydown', function(e) {
        if (e.target.id === 'acct2faOtpInput' && e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('acct2faVerifyBtn').click();
        }
    });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.acct-tab-btn');
        if (btn && btn.dataset.tab === 'acct-tab-security' && settingsLoaded) {
            init2FAStatus();
        }
    });
})();
