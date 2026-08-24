/**
 * MCP Inspector — Client Tabs, Clipboard, Activity
 * Runs on /mcp page
 */

(function() {
    'use strict';

    const CONFIG = window.MCP_CONFIG || {};

    // ─── Tab Switching (Setup client tabs) ───
    function initClientTabs() {
        const buttons = document.querySelectorAll('.mcp-client');
        const panels = document.querySelectorAll('.mcp-client-panel');
        if (!buttons.length) return;

        const savedClient = buttons[0].getAttribute('data-client');

        buttons.forEach(btn => {
            const target = btn.getAttribute('data-client');
            const isActive = target === savedClient;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(p => {
            const id = p.id.replace('mcp-panel-', '');
            const isActive = id === savedClient;
            p.classList.toggle('is-active', isActive);
            p.hidden = !isActive;
        });

        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                const target = this.getAttribute('data-client');

                buttons.forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');

                panels.forEach(p => {
                    p.classList.remove('is-active');
                    p.hidden = true;
                });

                const panel = document.getElementById('mcp-panel-' + target);
                if (panel) {
                    panel.classList.add('is-active');
                    panel.hidden = false;
                }
            });
        });
    }

    // ─── Clipboard Copy ───
    function initClipboard() {
        document.querySelectorAll('.mcp-copy[data-clipboard-target]').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.getAttribute('data-clipboard-target');
                const target = targetId ? document.querySelector(targetId) : null;
                if (!target) return;
                const text = target.textContent.trim();

                if (window.copyText) {
                    window.copyText(text, "Command copied!");
                } else if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(() => {}).catch(() => {});
                }
                flashIcon(this);
            });
        });
    }

    function flashIcon(btn) {
        const icon = btn && btn.querySelector('.mcp-copy__icon use');
        if (!icon) return;
        const orig = icon.getAttribute('xlink:href');
        icon.setAttribute('xlink:href', '/assets/icons/free.svg#cil-check');
        setTimeout(() => icon.setAttribute('xlink:href', orig), 1500);
    }

    // ─── Connected Clients ───

    async function fetchClients() {
        const res = await fetch('/mcp/clients', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        return data;
    }

    function renderConnectedClients(data) {
        const list = document.getElementById('mcp-connected-list');
        const emptyEl = document.getElementById('mcp-connected-empty');
        const liveCount = document.getElementById('mcp-live-count');
        if (!list) return;

        const clients = (data.clients || []).filter(c => !c.revoked);

        if (liveCount) liveCount.textContent = data.live_count || 0;

        if (clients.length === 0) {
            list.innerHTML = '';
            if (emptyEl) emptyEl.classList.remove('d-none');
            return;
        }
        if (emptyEl) emptyEl.classList.add('d-none');

        list.innerHTML = clients.map(c => {
            const name = c.client_name || 'MCP Client';
            const reqs = c.request_count || 0;
            const last = c.last_used_at ? timeAgo(c.last_used_at) : 'never';
            const shortId = c.client_id.replace(/^labs-mcp-/, '').slice(0, 8);
            const status = c.status || (c.connected ? 'active' : 'offline');
            const statusClass = status === 'active' ? 'mcp-status-ok' : status === 'idle' ? 'mcp-status-idle' : 'mcp-status-off';
            const statusText = status === 'active' ? 'Connected' : status === 'idle' ? 'Idle' : 'Offline';
            return `
                <li class="mcp-side__item d-flex align-items-center gap-2 py-2">
                    <span class="mcp-status-dot ${statusClass}" aria-hidden="true"></span>
                    <span class="flex-grow-1 text-truncate">
                        <span class="fw-semibold text-body">${escapeHtml(name)}</span>
                        <span class="d-block small text-secondary">${escapeHtml(shortId)} · ${reqs} call${reqs === 1 ? '' : 's'} · ${escapeHtml(last)} · ${statusText}</span>
                    </span>
                </li>`;
        }).join('');
    }

    // ─── Activity Feed ───
    let activityFilterClient = '';
    let activityPage = 0;
    let activityHasMore = false;

    function buildRail(clients) {
        const rail = document.getElementById('mcp-rail');
        const activeClients = (clients || []).filter(c => c.request_count > 0 || activityFilterClient === c.client_id);
        const total = clients ? clients.length : 0;
        if (rail) {
            const allActive = activityFilterClient === '';
            rail.innerHTML = `
                <button type="button" class="mcp-rail__item ${allActive ? 'is-active' : ''}" data-client="">
                    <span class="mcp-rail__dot mcp-rail__dot--all" aria-hidden="true"></span>
                    <span class="mcp-rail__body">
                        <span class="mcp-rail__row">
                            <span class="mcp-rail__name">All clients</span>
                            <span class="mcp-rail__count">${total}</span>
                        </span>
                    </span>
                </button>
                ${activeClients.map(c => {
                    const connected = isConnected(c);
                    const last = c.last_used_at ? timeAgo(c.last_used_at) : 'never';
                    const failed = c.failed_count || 0;
                    const failedHtml = failed > 0
                        ? `<span class="mcp-rail__failed" title="${failed} failed call${failed === 1 ? '' : 's'}">${failed} failed</span>`
                        : '';
                    return `
                        <button type="button" class="mcp-rail__item ${activityFilterClient === c.client_id ? 'is-active' : ''}" data-client="${escapeAttr(c.client_id)}">
                            <span class="mcp-rail__dot ${connected ? 'mcp-rail__dot--live' : ''}" aria-hidden="true"></span>
                            <span class="mcp-rail__body">
                                <span class="mcp-rail__row">
                                    <span class="mcp-rail__name text-truncate">${escapeHtml(c.client_name || 'MCP Client')}</span>
                                    <span class="mcp-rail__count">${c.request_count || 0}</span>
                                </span>
                                <span class="mcp-rail__meta">
                                    <span class="${connected ? 'mcp-rail__live-label' : ''}">${connected ? 'Connected' : 'Offline'}</span>
                                    · ${escapeHtml(last)}${failedHtml ? ' · ' + failedHtml : ''}
                                </span>
                            </span>
                        </button>`;
                }).join('')}`;
            rail.querySelectorAll('.mcp-rail__item').forEach(btn => {
                btn.addEventListener('click', function() {
                    activityFilterClient = this.getAttribute('data-client') || '';
                    activityPage = 0;
                    const tag = document.getElementById('mcp-filter-tag');
                    const tagName = document.getElementById('mcp-filter-name');
                    if (tag) {
                        if (activityFilterClient) {
                            tag.classList.remove('d-none');
                            if (tagName) tagName.textContent = this.querySelector('.mcp-rail__name').textContent.trim();
                        } else {
                            tag.classList.add('d-none');
                        }
                    }
                    buildRail(clients);
                    loadActivity();
                });
            });
        }
    }

    function isConnected(c) {
        return !!(c.connected);
    }

    // ─── Render activity list from array (shared by cache + fetch) ───
    function renderActivityItems(items, append) {
        const list = document.getElementById('mcp-history-list');
        const blank = document.getElementById('mcp-history-blank');
        const moreBtn = document.getElementById('mcp-load-more');
        const countEl = document.getElementById('mcp-call-count');
        if (!list) return;

        if (!append) list.innerHTML = '';

        if (items.length === 0 && !append) {
            if (blank) blank.classList.remove('d-none');
        } else if (blank) {
            blank.classList.add('d-none');
        }

        items.forEach(a => list.appendChild(activityCard(a)));

        if (moreBtn) {
            if (activityHasMore) {
                moreBtn.classList.remove('d-none');
                moreBtn.disabled = false;
                moreBtn.textContent = 'Load more';
            } else {
                moreBtn.classList.add('d-none');
            }
        }
    }

    async function loadActivity() {
        const list = document.getElementById('mcp-history-list');
        const countEl = document.getElementById('mcp-call-count');
        if (!list) return;

        // Fetch fresh data
        let url = '/mcp/activity/data?limit=20&page=' + activityPage;
        if (activityFilterClient) url += '&client_id=' + encodeURIComponent(activityFilterClient);

        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            if (countEl) countEl.textContent = data.total;
            activityHasMore = data.has_more;

            const items = data.activity || [];

            // Re-render with fresh data (replace, don't append for page 0)
            if (activityPage === 0) {
                renderActivityItems(items, false);
            } else {
                renderActivityItems(items, true);
            }
        } catch (e) {
            // If fetch fails and no data was shown, show error
            const list = document.getElementById('mcp-history-list');
            if (activityPage === 0 && list && list.children.length === 0) {
                const blank = document.getElementById('mcp-history-blank');
                if (blank) {
                    blank.textContent = 'Could not load activity.';
                    blank.classList.remove('d-none');
                }
            }
        }
    }

    function extractTarget(a) {
        const req = a.request || {};
        const args = req.arguments || req;
        return args.instance_id || args.lab || args.command || '';
    }

    function activityCard(a) {
        const card = document.createElement('div');
        card.className = 'mcp-log';
        const status = a.status === 'ok' ? 'ok' : (a.status === 'refused' ? 'refused' : 'err');
        const clientName = a.client_name || a.username || 'MCP';
        const target = extractTarget(a);

        const line = document.createElement('div');
        line.className = 'mcp-log__line';
        line.innerHTML = `
            <code class="mcp-log__tool">${escapeHtml(a.tool)}</code>
            ${target ? `<span class="mcp-log__target">${escapeHtml(target)}</span>` : ''}
            <span class="badge badge-soft-${status === 'ok' ? 'success' : (status === 'refused' ? 'warning' : 'danger')} mcp-tag">${escapeHtml(a.status)}</span>`;

        const meta = document.createElement('div');
        meta.className = 'mcp-log__meta';
        meta.innerHTML = `${escapeHtml(clientName)} · <span class="mcp-log__ago" data-ts="${new Date(a.created_at).getTime() / 1000}">${formatTime(a.created_at)}</span>${a.duration_ms ? ' · ' + a.duration_ms + ' ms' : ''}`;

        card.appendChild(line);
        card.appendChild(meta);
        card.addEventListener('click', function() {
            const selected = document.querySelector('#mcp-history-list .mcp-log.is-selected');
            if (selected) selected.classList.remove('is-selected');
            card.classList.add('is-selected');
            showDetail(a);
        });
        return card;
    }

    function showDetail(a) {
        const toolEl = document.getElementById('mcp-call-modal-tool');
        const targetEl = document.getElementById('mcp-call-modal-target');
        const statusEl = document.getElementById('mcp-call-modal-status');
        const metaEl = document.getElementById('mcp-call-modal-meta');
        const reqEl = document.getElementById('mcp-call-modal-request');
        const resEl = document.getElementById('mcp-call-modal-response');
        const footEl = document.getElementById('mcp-call-modal-foot');
        const status = a.status === 'ok' ? 'ok' : (a.status === 'refused' ? 'refused' : 'err');
        const clientName = a.client_name || a.username || 'MCP';
        const target = extractTarget(a);

        if (toolEl) toolEl.textContent = a.tool;
        if (targetEl) targetEl.textContent = target;
        if (statusEl) {
            statusEl.textContent = a.status;
            statusEl.className = 'badge badge-soft-' + (status === 'ok' ? 'success' : (status === 'refused' ? 'warning' : 'danger')) + ' mcp-tag';
        }
        if (metaEl) {
            metaEl.innerHTML = `${escapeHtml(clientName)} · ${formatTime(a.created_at)}${a.duration_ms ? ' · ' + a.duration_ms + ' ms' : ''}`;
        }
        if (reqEl) reqEl.innerHTML = highlightJson(a.request || {});
        if (resEl) {
            resEl.innerHTML = a.error
                ? highlightJson({ error: a.error })
                : highlightJson(a.response || {});
        }
        if (footEl) {
            const instanceId = a.request && a.request.arguments ? a.request.arguments.instance_id : '';
            const shortReq = a.id ? a.id.slice(0, 8) : '';
            footEl.innerHTML = `
                ${instanceId ? `<span>instance <code>${escapeHtml(instanceId)}</code></span>` : ''}
                ${shortReq ? `<span>request <code>${escapeHtml(shortReq)}</code></span>` : ''}`;
        }

        const modalEl = document.getElementById('mcp-call-modal');
        if (modalEl) {
            if (window.coreui && coreui.Modal) {
                new coreui.Modal(modalEl).show();
            } else if (window.bootstrap) {
                new bootstrap.Modal(modalEl).show();
            } else {
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
                document.body.classList.add('modal-open');
            }
        }
    }

    function timeAgo(iso) {
        const t = new Date(iso);
        if (isNaN(t.getTime())) return 'never';
        const diff = (Date.now() - t.getTime()) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function formatTime(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
        return d.toLocaleString(undefined, opts);
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function highlightJson(value) {
        // If value is a string, try to parse it as JSON first
        if (typeof value === 'string') {
            try {
                value = JSON.parse(value);
            } catch (e) {
                // Keep as string if not valid JSON
            }
        }
        const src = JSON.stringify(value, null, 2);
        let out = '';
        let i = 0;
        const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const KEY_RE = /^"((?:[^"\\]|\\.)*)"(\s*):/;
        const STR_RE = /^"((?:[^"\\]|\\.)*)"/;
        const NUM_RE = /^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/;
        const LIT_RE = /^(true|false|null)/;

        // Add line numbers
        let lineNum = 1;
        out += '<span class="tok-line-num">' + lineNum + '</span>';

        while (i < src.length) {
            // Check for newline to add line number
            if (src[i] === '\n') {
                out += '\n';
                lineNum++;
                out += '<span class="tok-line-num">' + lineNum + '</span>';
                i++;
                continue;
            }

            const rest = src.slice(i);
            const key = rest.match(KEY_RE);
            if (key) {
                out += '<span class="tok-key">' + esc(key[1]) + '</span><span class="tok-punct">:</span>';
                i += key[0].length;
                continue;
            }
            const str = rest.match(STR_RE);
            if (str) {
                out += '<span class="tok-str">' + esc(str[0]) + '</span>';
                i += str[0].length;
                continue;
            }
            const num = rest.match(NUM_RE);
            if (num) {
                out += '<span class="tok-num">' + esc(num[0]) + '</span>';
                i += num[0].length;
                continue;
            }
            const lit = rest.match(LIT_RE);
            if (lit) {
                const cls = lit[1] === 'null' ? 'tok-null' : 'tok-bool';
                out += '<span class="' + cls + '">' + lit[1] + '</span>';
                i += lit[1].length;
                continue;
            }
            const ch = src[i];
            if (ch === '{' || ch === '}' || ch === '[' || ch === ']' || ch === ',') {
                out += '<span class="tok-punct">' + ch + '</span>';
            } else {
                out += esc(ch);
            }
            i++;
        }
        return out;
    }

    function escapeAttr(s) {
        return escapeHtml(s).replace(/`/g, '&#96;');
    }

    async function refreshAll() {
        // Fetch fresh clients
        try {
            const data = await fetchClients();
            renderConnectedClients(data);
            buildRail(data.clients || []);
        } catch (e) {
            // Silently fail - show empty state
        }

        // Activity: fetch directly
        await loadActivity();
    }

    // ─── Activity Log init ───
    function initActivity() {
        const loadMoreBtn = document.getElementById('mcp-load-more');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                activityPage += 1;
                loadActivity();
            });
        }

        const filterTag = document.getElementById('mcp-filter-tag');
        if (filterTag) {
            filterTag.addEventListener('click', function() {
                this.classList.add('d-none');
                activityFilterClient = '';
                activityPage = 0;
                refreshAll();
            });
        }
    }

    // ─── Connected Clients init ───
    let refreshTimer = null;

    function initConnectedClients() {
        refreshAll();
        if (!refreshTimer) {
            refreshTimer = setInterval(refreshAll, 15000);
        }
    }

    // ─── Init ───
    let initialized = false;

    function init() {
        if (initialized) return;
        initialized = true;
        initClientTabs();
        initClipboard();
        initActivity();
        initConnectedClients();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('htmx:afterSettle', (e) => {
        if (e.target.id === 'main-content' || e.target.querySelector('.mcp-page')) {
            initialized = false;
            if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
            init();
        }
    });

})();
