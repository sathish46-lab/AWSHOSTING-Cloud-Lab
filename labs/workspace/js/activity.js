/**
 * Activity & Analytics Page
 * Loads audit log feed + aggregated charts for the authenticated user.
 */
(function() {
    let actCurrentPage = 0;
    let actPageSize = 30;
    let actTotalEntries = 0;
    let actFilterAction = '';
    let actFilterEntity = '';
    let actFilterSearch = '';
    let actAnalyticsData = null;

    window.initActivityPage = function() {
        loadAnalytics();
        loadTimeline();

        document.getElementById('actFilterAction')?.addEventListener('change', function() {
            actFilterAction = this.value;
            actCurrentPage = 0;
            loadTimeline();
        });
        document.getElementById('actFilterEntity')?.addEventListener('change', function() {
            actFilterEntity = this.value;
            actCurrentPage = 0;
            loadTimeline();
        });
        document.getElementById('actSearchInput')?.addEventListener('input', debounce(function() {
            actFilterSearch = this.value.trim().toLowerCase();
            actCurrentPage = 0;
            loadTimeline();
        }, 300));
        document.getElementById('actPrevPage')?.addEventListener('click', function() {
            if (actCurrentPage > 0) { actCurrentPage--; loadTimeline(); }
        });
        document.getElementById('actNextPage')?.addEventListener('click', function() {
            const maxPage = Math.ceil(actTotalEntries / actPageSize) - 1;
            if (actCurrentPage < maxPage) { actCurrentPage++; loadTimeline(); }
        });
        document.getElementById('actRefreshBtn')?.addEventListener('click', function() {
            loadAnalytics();
            loadTimeline();
        });
    };

    function loadAnalytics() {
        fetch('/api/account/activity_analytics')
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') return;
                actAnalyticsData = data;
                updateStats(data.summary);
                renderPieChart(data.action_breakdown);
                renderBarChart(data.hourly_activity);
                renderSecurityFeed(data.security_events);
            })
            .catch(() => {});
    }

    function updateStats(summary) {
        const el = (id, val) => { const e = document.getElementById(id); if (e) e.textContent = val; };
        el('actStatTotal', summary.total_actions?.toLocaleString() ?? '0');
        el('actStatActiveDays', summary.active_days ?? '0');
        el('actStatThisWeek', summary.this_week?.toLocaleString() ?? '0');
        el('actStatTopAction', summary.most_common_action || '\u2014');
    }

    function renderPieChart(breakdown) {
        const canvas = document.getElementById('actPieChart');
        if (!canvas || !window.Chart || !breakdown?.length) return;
        const colors = ['#6366f1','#06b6d4','#10b981','#f59e0b','#ef4444','#ec4899','#8b5cf6','#14b8a6'];
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: breakdown.map(b => b.action),
                datasets: [{
                    data: breakdown.map(b => b.count),
                    backgroundColor: colors.slice(0, breakdown.length),
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, padding: 12, font: { size: 12 } } }
                }
            }
        });
    }

    function renderBarChart(hourlyData) {
        const canvas = document.getElementById('actBarChart');
        if (!canvas || !window.Chart || !hourlyData?.length) return;
        const labels = Array.from({length: 24}, (_, i) => `${String(i).padStart(2, '0')}:00`);
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Actions',
                    data: hourlyData,
                    backgroundColor: 'rgba(99, 102, 241, 0.6)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' }, ticks: { font: { size: 11 } } }
                },
                plugins: { legend: { display: false } }
            }
        });
    }

    function renderSecurityFeed(events) {
        const el = document.getElementById('actSecurityFeed');
        if (!el) return;
        if (!events?.length) {
            el.innerHTML = '<p class="text-body-secondary small mb-0">No recent security events.</p>';
            return;
        }
        el.innerHTML = events.slice(0, 10).map(ev => {
            const time = ev.created_at ? new Date(ev.created_at).toLocaleString() : '';
            return `<div class="d-flex align-items-start gap-2 mb-2 pb-2 border-bottom">
                <i class="bx bx-shield-quarter text-warning mt-1"></i>
                <div><small class="fw-semibold">${escActivity(ev.action)}</small><br><small class="text-body-secondary">${escActivity(time)} &middot; ${escActivity(ev.ip_address || '')}</small></div>
            </div>`;
        }).join('');
    }

    function loadTimeline() {
        const container = document.getElementById('actTimeline');
        if (!container) return;
        container.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
        const params = new URLSearchParams({ limit: actPageSize, offset: actCurrentPage * actPageSize });
        if (actFilterAction) params.set('action', actFilterAction);
        if (actFilterEntity) params.set('entity_type', actFilterEntity);
        fetch('/api/account/activity?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') {
                    container.innerHTML = '<p class="text-danger small">Failed to load.</p>';
                    return;
                }
                actTotalEntries = data.total;
                renderTimeline(data.entries);
                updatePagination();
            })
            .catch(() => { container.innerHTML = '<p class="text-danger small">Network error.</p>'; });
    }

    function renderTimeline(entries) {
        const container = document.getElementById('actTimeline');
        if (!container) return;
        let filtered = entries;
        if (actFilterSearch) {
            filtered = entries.filter(e => {
                const haystack = [e.action, e.entity_type, e.entity_id, e.ip_address, JSON.stringify(e.details)].join(' ').toLowerCase();
                return haystack.includes(actFilterSearch);
            });
        }
        if (!filtered.length) {
            container.innerHTML = '<div class="text-center py-5"><i class="bx bx-history display-4 text-body-secondary"></i><p class="text-body-secondary mt-2">No activity found.</p></div>';
            return;
        }
        const actionIcon = (a) => {
            const icons = { create: 'bx-plus-circle text-success', update: 'bx-edit text-primary', delete: 'bx-trash text-danger', trash: 'bx-archive text-warning', restore: 'bx-revision text-info', permanent_delete: 'bx-x-circle text-danger', change_password: 'bx-lock text-warning' };
            return icons[a] || 'bx-radio-circle text-body-secondary';
        };
        const actionLabel = (a) => a?.replace(/_/g, ' ') || '';
        const entityLabel = (e) => e?.replace(/_/g, ' ') || '';
        const detailsSummary = (d) => {
            if (!d || typeof d !== 'object' || !Object.keys(d).length) return '';
            const parts = [];
            for (const [k, v] of Object.entries(d)) {
                if (v !== null && v !== undefined && v !== '') parts.push(`<span class="text-body-secondary">${escActivity(k)}:</span> ${escActivity(String(v).substring(0, 80))}`);
            }
            return parts.length ? '<br><small class="text-body-secondary">' + parts.join(' &middot; ') + '</small>' : '';
        };
        container.innerHTML = filtered.map(e => {
            const time = e.created_at ? new Date(e.created_at) : null;
            const timeStr = time ? time.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';
            return `<div class="d-flex gap-3 pb-3 mb-3 border-bottom act-timeline-item">
                <div class="flex-shrink-0 mt-1"><i class="bx ${actionIcon(e.action)} fs-5"></i></div>
                <div class="flex-grow-1 min-width-0">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                        <div><span class="fw-semibold">${escActivity(actionLabel(e.action))}</span>
                        <span class="badge bg-body-secondary bg-opacity-10 text-body-secondary ms-1">${escActivity(entityLabel(e.entity_type))}</span>
                        ${e.entity_id ? `<small class="text-body-secondary ms-1">#${escActivity(String(e.entity_id).substring(0, 8))}</small>` : ''}</div>
                        <small class="text-body-secondary flex-shrink-0">${escActivity(timeStr)}</small>
                    </div>
                    ${e.ip_address ? `<small class="text-body-secondary"><i class="bx bx-globe me-1"></i>${escActivity(e.ip_address)}</small>` : ''}
                    ${detailsSummary(e.details)}
                </div>
            </div>`;
        }).join('');
    }

    function updatePagination() {
        const totalPages = Math.max(1, Math.ceil(actTotalEntries / actPageSize));
        const pageInfo = document.getElementById('actPageInfo');
        const prevBtn = document.getElementById('actPrevPage');
        const nextBtn = document.getElementById('actNextPage');
        if (pageInfo) pageInfo.textContent = `Page ${actCurrentPage + 1} of ${totalPages} (${actTotalEntries} entries)`;
        if (prevBtn) prevBtn.disabled = actCurrentPage <= 0;
        if (nextBtn) nextBtn.disabled = actCurrentPage >= totalPages - 1;
    }

    function debounce(fn, delay) {
        let timer;
        return function(...args) { clearTimeout(timer); timer = setTimeout(() => fn.apply(this, args), delay); };
    }

    function escActivity(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
