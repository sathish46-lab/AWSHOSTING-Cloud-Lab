// Instance Manager tab switching.
// IMPORTANT: The manage page is often loaded via HTMX (body has hx-boost="true"),
// so #main-content is swapped by AJAX without a full page reload. We therefore:
//   1. Use event DELEGATION on document for tab clicks (survives any DOM swap).
//   2. Re-run init on htmx:afterSettle / htmx:load so tab state stays correct.
//   3. Never rely solely on DOMContentLoaded (it only fires once per full load).

function initInstanceTabs() {
    const tabs = document.querySelectorAll('.manage-tab-btn');
    const contentContainer = document.getElementById('instanceTabsContent');
    if (tabs.length === 0 || !contentContainer) return;

    // Prevent re-init loop: skip if same tab is already loaded
    if (window.__currentTab === window.location.pathname) return;

    // Determine current slug from URL path (e.g., /instances/my-lab)
    const pathParts = window.location.pathname.split('/').filter(Boolean);
    const slug = pathParts.length >= 2 && pathParts[0] === 'instances' ? pathParts[1] : null;
    if (!slug) return;

    const loadTab = async (tabName) => {
        // Update UI state
        tabs.forEach(t => t.classList.remove('active'));
        const activeBtn = document.querySelector(`.manage-tab-btn[data-tab="${tabName}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        // Show loader
        contentContainer.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-secondary" role="status"></div>
            </div>
        `;

        // Update browser URL cleanly (no page reload)
        const newUrl = `/instances/${slug}/${tabName}`;
        window.history.pushState(null, '', newUrl);
        window.__currentTab = newUrl;

        try {
            const response = await fetch(newUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (response.ok) {
                contentContainer.innerHTML = await response.text();

                // Re-execute <script> tags injected via innerHTML
                contentContainer.querySelectorAll('script').forEach(old => {
                    const s = document.createElement('script');
                    if (old.src) s.src = old.src;
                    else s.textContent = old.textContent;
                    old.replaceWith(s);
                });

                // Fire event so tab scripts can initialize
                document.dispatchEvent(new CustomEvent('instanceTabLoaded', { detail: { tab: tabName } }));
            } else {
                contentContainer.innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load tab (HTTP ${response.status}).
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error fetching tab:', error);
            contentContainer.innerHTML = `
                <div class="alert alert-danger">
                    A network error occurred while loading this tab.
                </div>
            `;
        }
    };

    // Expose for htmx re-init / popstate without duplicating listeners.
    window.__loadInstanceTab = loadTab;

    // Browser back/forward
    if (!window.__instancePopstateBound) {
        window.__instancePopstateBound = true;
        window.addEventListener('popstate', () => {
            const parts = window.location.pathname.split('/').filter(Boolean);
            const tab = parts.length >= 3 ? parts[2] : 'configuration';
            if (window.__loadInstanceTab) window.__loadInstanceTab(tab);
        });
    }

    // Auto-load the tab from the URL on a fresh page load (deep links like
    // /instances/tech/files must open the Files tab, not just configuration).
    // Skip if the tab content is already rendered server-side (prevents flicker).
    const urlParts = window.location.pathname.split('/').filter(Boolean);
    const urlTab = urlParts.length >= 3 && urlParts[0] === 'instances' ? urlParts[2] : '';
    const validTabs = ['deployments', 'files', 'configuration', 'build', 'sharing', 'versions'];

    if (validTabs.includes(urlTab)) {
        // Content is already rendered by PHP. Just sync nav active state.
        tabs.forEach(t => t.classList.remove('active'));
        const activeBtn = document.querySelector(`.manage-tab-btn[data-tab="${urlTab}"]`);
        if (activeBtn) activeBtn.classList.add('active');
    } else {
        // Bare /instances/{slug}: PHP defaults to configuration, just sync nav.
        tabs.forEach(t => t.classList.remove('active'));
        const configBtn = document.querySelector(`.manage-tab-btn[data-tab="configuration"]`);
        if (configBtn) configBtn.classList.add('active');
    }
}

// Delegated click handler — works for both full loads and HTMX-swapped content.
if (!window.__instanceTabDelegated) {
    window.__instanceTabDelegated = true;
    document.addEventListener('click', (e) => {
        // Tab switching
        const tabBtn = e.target.closest('.manage-tab-btn');
        if (tabBtn) {
            e.preventDefault();
            const tabName = tabBtn.dataset.tab;
            if (tabName && window.__loadInstanceTab) window.__loadInstanceTab(tabName);
            return;
        }

        // Save configuration
        if (e.target.closest('#saveConfigBtn')) {
            e.preventDefault();
            saveInstanceConfig();
            return;
        }

        // Add user
        if (e.target.closest('#addConfigUser')) {
            e.preventDefault();
            addConfigUser();
            return;
        }

        // Remove user
        const removeBtn = e.target.closest('.remove-user');
        if (removeBtn) {
            removeBtn.closest('[data-user-index]').remove();
            return;
        }

        // Add bind mount
        if (e.target.closest('#addBindMount')) {
            e.preventDefault();
            addBindMount();
            return;
        }

        // Remove bind mount
        const removeMount = e.target.closest('.remove-mount');
        if (removeMount) {
            removeMount.closest('[data-mount-index]').remove();
            return;
        }
    });
}

function getSlugFromUrl() {
    const parts = window.location.pathname.split('/').filter(Boolean);
    return parts.length >= 2 && parts[0] === 'instances' ? parts[1] : null;
}

async function saveInstanceConfig() {
    const slug = getSlugFromUrl();
    if (!slug) return;

    const saveBtn = document.getElementById('saveConfigBtn');
    if (!saveBtn) return;

    const data = {};
    document.querySelectorAll('#instanceTabsContent [data-field]').forEach(el => {
        const field = el.dataset.field;
        if (field.startsWith('users.') || field.startsWith('bind_mounts.')) return;

        if (el.type === 'checkbox') {
            data[field] = el.checked;
        } else if (field === 'ports') {
            data[field] = el.value.trim().split(/\s+/).filter(Boolean);
        } else {
            data[field] = el.value;
        }
    });

    // Collect users
    const usersList = document.getElementById('configUsersList');
    if (usersList) {
        const userRows = usersList.querySelectorAll('[data-user-index]');
        data.users = [];
        userRows.forEach(row => {
            const username = row.querySelector('[data-field$=".username"]');
            const shell = row.querySelector('[data-field$=".shell"]');
            const sudo = row.querySelector('[data-field$=".sudo"]');
            if (username && username.value.trim()) {
                data.users.push({
                    username: username.value.trim(),
                    shell: shell ? shell.value : '/bin/bash',
                    sudo: sudo ? sudo.checked : false
                });
            }
        });
    }

    // Collect bind mounts
    const mountsList = document.getElementById('configBindMountsList');
    if (mountsList) {
        const mountInputs = mountsList.querySelectorAll('[data-field^="bind_mounts."]');
        data.bind_mounts = [];
        mountInputs.forEach(input => {
            if (input.value.trim()) data.bind_mounts.push(input.value.trim());
        });
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    try {
        const res = await fetch('/api/instances/save_config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ slug: slug, config: data })
        });
        const result = await res.json();

        if (result.status === 'success') {
            saveBtn.innerHTML = '<i class="bx bx-check me-1"></i> Saved!';
            setTimeout(() => {
                saveBtn.innerHTML = '<i class="bx bx-save me-1"></i> Save configuration';
            }, 2000);
        } else {
            alert('Error: ' + (result.error || 'Failed to save'));
            saveBtn.innerHTML = '<i class="bx bx-save me-1"></i> Save configuration';
        }
    } catch (e) {
        alert('Network error.');
        saveBtn.innerHTML = '<i class="bx bx-save me-1"></i> Save configuration';
    } finally {
        saveBtn.disabled = false;
    }
}

function addConfigUser() {
    const usersList = document.getElementById('configUsersList');
    if (!usersList) return;

    const idx = usersList.querySelectorAll('[data-user-index]').length;
    const html = `
        <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded-3 border border-secondary border-opacity-25 bg-black bg-opacity-50" data-user-index="${idx}">
            <div class="bg-secondary bg-opacity-25 p-1 rounded d-flex">
                <i class='bx bx-user text-secondary'></i>
            </div>
            <input type="text" class="form-control form-control-sm config-input bg-transparent border-0 text-white fw-bold" style="max-width:120px;" placeholder="username" data-field="users.${idx}.username">
            <select class="form-select form-select-sm config-input bg-transparent border-0 text-secondary" style="max-width:120px;" data-field="users.${idx}.shell">
                <option value="/bin/bash">/bin/bash</option>
                <option value="/bin/sh">/bin/sh</option>
                <option value="/bin/zsh">/bin/zsh</option>
            </select>
            <div class="ms-auto d-flex align-items-center gap-3 pe-2">
                <div class="form-check form-switch m-0 d-flex align-items-center gap-2">
                    <input class="form-check-input m-0" type="checkbox" role="switch" data-field="users.${idx}.sudo">
                    <label class="form-check-label text-secondary small">sudo</label>
                </div>
                <i class='bx bx-trash text-danger pointer small remove-user' data-user-index="${idx}"></i>
            </div>
        </div>`;

    usersList.insertAdjacentHTML('beforeend', html);
}

function addBindMount() {
    const mountsList = document.getElementById('configBindMountsList');
    if (!mountsList) return;

    // Remove "no mounts" placeholder if present
    const placeholder = mountsList.querySelector('.opacity-50');
    if (placeholder) placeholder.remove();

    const idx = mountsList.querySelectorAll('[data-mount-index]').length;
    const html = `
        <div class="d-flex align-items-center gap-2 mb-2" data-mount-index="${idx}">
            <input type="text" class="form-control form-control-sm config-input" placeholder="{labstorage}/home:/home" data-field="bind_mounts.${idx}" style="font-size: 0.8rem;">
            <i class='bx bx-trash text-danger pointer small remove-mount' data-mount-index="${idx}"></i>
        </div>`;

    mountsList.insertAdjacentHTML('beforeend', html);
}

// Initialise now (full load) and whenever HTMX settles a swap.
function initManagePage() {
    initInstanceTabs();
}

if (document.readyState !== 'loading') {
    initManagePage();
}

document.addEventListener('DOMContentLoaded', initManagePage);
document.addEventListener('htmx:afterSettle', initManagePage);
document.addEventListener('htmx:load', initManagePage);