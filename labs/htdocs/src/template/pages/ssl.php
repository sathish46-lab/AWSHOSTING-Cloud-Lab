<?php
$certs = Session::get('ssl_certificates') ?: [];
$autoManaged = Session::get('ssl_auto_managed') ?: 0;
$dm = new DomainManager();
$serverIP = $dm->getServerIP();
?>

<!-- SSL Manager Header -->
<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-4">
        <div class="row align-items-start py-3">
        <div class="col-lg-7">
            <h1 class="fw-bold theme-text m-0" style="font-size: 1.8rem; letter-spacing: -0.5px;">SSL Manager</h1>
            <p class="text-secondary opacity-75 mt-2 mb-0" style="font-size: 0.82rem; line-height: 1.8; letter-spacing: 0.2px;">
                SSL Manager shows every SSL certificate linked to your domains and labs. Certificates exist only for domains exposed to the web by a 
                lab, and all domains exposed on one port share a single certificate &mdash; so the number of certificates will not match the number of 
                domains you own. Track expiry, see why a certificate fails to renew, and request a re-issue once you have cleaned up expired domains. 
                We acquire and renew certificates for you automatically &mdash; this is where you watch it happen 🔐
            </p>
        </div>
        <div class="col-lg-5 text-end mt-3 mt-lg-0">
            <div class="d-flex flex-column align-items-end">
                <div class="btn-group shadow-sm mb-2" role="group">
                    <button class="btn btn-warning fw-bold px-4" style="font-size: 0.8rem; height: 38px; color: #000; border-top-left-radius: 50rem !important; border-bottom-left-radius: 50rem !important;" 
                            id="btnTroubleshootAll" onclick="SSLManager.troubleshootAll()">
                        Troubleshoot
                    </button>
                    <button class="btn fw-bold px-4" style="font-size: 0.8rem; height: 38px; background-color: #00d4a8; border-color: #00d4a8; color: #000;" 
                            id="btnRefresh" onclick="SSLManager.refresh()">
                        Refresh
                    </button>
                    <button class="btn px-3" style="font-size: 0.8rem; height: 38px; background-color: #6edff6; border-color: #6edff6; color: #000; border-top-right-radius: 50rem !important; border-bottom-right-radius: 50rem !important;"
                            data-coreui-toggle="tooltip" data-coreui-placement="top" title="How does SSL work here?"
                            onclick="new coreui.Modal(document.getElementById('sslHelpModal')).show();">
                        <i class="bx bx-info-circle fw-bold"></i>
                    </button>
                </div>
                <div class="small text-secondary opacity-60 text-end" style="font-size: 0.75rem;">
                    Certificate data is cached for up to 15 minutes.<br>
                    Use Refresh to fetch the latest state.
                </div>
                <?php if ($autoManaged > 0): ?>
                <div class="mt-2 text-end">
                    <a href="#" class="small text-success text-decoration-underline fw-semibold" style="font-size: 0.78rem;" 
                       onclick="SSLManager.toggleAutoManaged(); return false;">
                        Show <?php echo htmlspecialchars((string)$autoManaged); ?> auto-managed certificate(s)
                    </a>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
<!-- Certificate Cards Grid -->
<div class="row g-4 mb-4" id="ssl-cards-container">
    <?php if (empty($certs)): ?>
    <div class="col-12">
        <div class="card rounded-4 blur">
            <div class="card-body text-center py-5">
                <i class="bx bx-shield-x" style="font-size: 3rem; color: var(--glass-text-muted);"></i>
                <h5 class="mt-3 fw-bold">No SSL Certificates Found</h5>
                <p class="text-secondary small mb-3">
                    Your labs don't have any active SSL certificates yet. Deploy a lab with web exposure enabled to automatically get an SSL certificate.
                </p>
                <a href="/labs" class="btn btn-primary btn-sm rounded-pill px-4">Go to Labs</a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($certs as $index => $cert): 
        $isAutoManaged = (!empty($cert['resolver']) && stripos($cert['resolver'], 'custom') === false);
    ?>
    <div class="col-xl-4 col-md-6 card-entrance <?php echo $isAutoManaged ? 'd-none auto-managed-card' : ''; ?>">
        <div class="card rounded-4 h-100 ssl-cert-ssl-inner-card blur border-0" data-cert-index="<?php echo (int)$index; ?>">
            <div class="card-body d-flex justify-content-between align-items-start p-3">
                <div class="w-100" style="margin-bottom: 6px;">
                    <!-- Main Domain -->
                    <div class="fw-bold mb-2" style="font-size: 1.15rem; letter-spacing: -0.3px; color: var(--glass-text);">
                        <?php echo htmlspecialchars($cert['main_domain']); ?>
                    </div>

                    <!-- Badges -->
                    <div style="margin-bottom: 14px;" class="d-flex gap-1 flex-wrap">
                        <?php 
                        $badges = $cert['badges'] ?? [];
                        if (empty($badges)) {
                            $badges = [];
                            if ($cert['is_valid']) $badges[] = 'valid';
                            
                            if (!empty($cert['used_by']) && isset($cert['used_by_status']) && $cert['used_by_status'] === 'running') {
                                $badges[] = 'in use';
                            } else {
                                $badges[] = 'orphaned';
                            }

                            if (!empty($cert['resolver']) && stripos($cert['resolver'], 'custom') === false) {
                                $badges[] = 'auto-managed';
                            }

                            $hasWildcard = false;
                            if (strpos($cert['main_domain'], '*') !== false) {
                                $hasWildcard = true;
                            } else {
                                foreach (($cert['sans'] ?? []) as $san) {
                                    if (strpos($san, '*') !== false) {
                                        $hasWildcard = true;
                                        break;
                                    }
                                }
                            }
                            if ($hasWildcard) {
                                $badges[] = 'wildcard';
                            }
                        }

                        $finalBadges = [];
                        foreach ($badges as $badge) {
                            if (strtolower($badge) === 'in use') {
                                $labTypeMatch = '';
                                if (!empty($cert['used_by']) && preg_match('/\((\w+)\s*lab/', $cert['used_by'], $m)) {
                                    $labTypeMatch = strtolower($m[1]);
                                }
                                $finalBadges[] = $labTypeMatch ? 'in use - ' . $labTypeMatch : 'in use';
                            } else {
                                $finalBadges[] = $badge;
                            }
                        }
                        $badges = $finalBadges;

                        foreach ($badges as $badge): 
                            $badgeClass = 'bg-secondary';
                            $textClass = 'text-white';
                            $badgeLabel = htmlspecialchars($badge);
                            $tooltipAttr = '';
                            
                            if (stripos($badge, 'Tom') !== false) {
                                $badgeClass = 'bg-purple';
                                $textClass = 'text-white';
                            } elseif (stripos($badge, 'valid') !== false) {
                                $badgeClass = 'bg-success';
                                $textClass = 'text-white';
                            } elseif (stripos($badge, 'in use') !== false) {
                                $badgeClass = 'bg-primary';
                                $textClass = 'text-white';
                                if (!empty($cert['used_by_hash'])) {
                                    $tooltipAttr = 'data-coreui-toggle="tooltip" title="Instance hash: ' . htmlspecialchars($cert['used_by_hash']) . '"';
                                } else {
                                    $tooltipAttr = 'data-coreui-toggle="tooltip" title="Currently in use by a running lab."';
                                }
                            } elseif (stripos($badge, 'auto-managed') !== false) {
                                $badgeClass = 'bg-info bg-opacity-25';
                                $textClass = 'text-info';
                                $tooltipAttr = 'data-coreui-toggle="tooltip" title="Certificate is managed automatically by the platform"';
                            } elseif (stripos($badge, 'wildcard') !== false) {
                                $badgeClass = 'bg-purple';
                                $textClass = 'text-white';
                                $tooltipAttr = 'data-coreui-toggle="tooltip" title="Wildcard certificate covering all subdomains"';
                            } elseif (stripos($badge, 'orphaned') !== false || stripos($badge, 'available') !== false) {
                                $badgeClass = 'bg-warning';
                                $textClass = 'text-dark';
                                if (!empty($cert['used_by_hash']) && isset($cert['used_by_status']) && $cert['used_by_status'] !== 'running') {
                                    $tooltipAttr = 'data-coreui-toggle="tooltip" title="Lab instance ' . htmlspecialchars(substr($cert['used_by_hash'], 0, 10)) . ' is ' . htmlspecialchars($cert['used_by_status']) . '. No running lab routes these domains anymore."';
                                } else {
                                    $tooltipAttr = 'data-coreui-toggle="tooltip" title="No running lab routes these domains anymore."';
                                }
                            } elseif (stripos($badge, 'expired') !== false) {
                                $badgeClass = 'bg-danger';
                                $textClass = 'text-white';
                            }
                        ?>
                            <span class="badge <?php echo $badgeClass; ?> <?php echo $textClass; ?> rounded-pill fw-bold text-lowercase" 
                                  style="font-size: 8px; padding: 2px 6px;" <?php echo $tooltipAttr; ?>><?php echo $badgeLabel; ?></span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Certificate Details -->
                    <div style="font-size: 0.8rem; line-height: 1.6; color: var(--glass-text);">
                        <div class="mb-1">
                            <b style="color: var(--glass-text-muted); font-weight: 600;">Resolver:</b>
                            <span class="ms-1 text-success"><?php echo htmlspecialchars($cert['resolver']); ?></span>
                        </div>
                        <div class="mb-1">
                            <b style="color: var(--glass-text-muted); font-weight: 600;">SANs:</b>
                            <span class="ms-1">
                                <?php 
                                $sans = $cert['sans'] ?? [];
                                $displayCount = 3;
                                $displayed = array_slice($sans, 0, $displayCount);
                                echo htmlspecialchars(implode(', ', $displayed));
                                if (count($sans) > $displayCount) {
                                    echo ' <span class="text-info">+' . (count($sans) - $displayCount) . ' more</span>';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="mb-1">
                            <b style="color: var(--glass-text-muted); font-weight: 600;">Expires:</b>
                            <span class="ms-1">
                                <?php 
                                $expiresDate = $cert['expires'] ? date('d M Y', $cert['expires_timestamp']) : 'Unknown';
                                $daysLeft = $cert['days_left'] ?? null;
                                echo htmlspecialchars($expiresDate);
                                if ($daysLeft !== null) {
                                    $daysColor = $daysLeft > 30 ? 'text-success' : ($daysLeft > 7 ? 'text-warning' : 'text-danger');
                                    echo ' <span class="' . $daysColor . '">(in ' . (int)$daysLeft . ' days)</span>';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Three-dot dropdown -->
                <div class="dropdown">
                    <button class="action-dots p-0 opacity-50 shadow-none border-0 d-flex align-items-center justify-content-center" 
                            data-coreui-toggle="dropdown" 
                            style="width: 32px; height: 32px; transition: all 0.2s; background: none; border: none;">
                        <i class='bx bx-dots-vertical-rounded fs-4' style="color: var(--glass-icon);"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 10rem; border-radius: 12px; padding: 8px; background: var(--cui-dropdown-bg); backdrop-filter: blur(10px);">
                        <li>
                            <a class="dropdown-item rounded-3 mb-1 px-3 py-2 d-flex align-items-center" href="#" 
                               onclick="SSLManager.viewDetails(<?php echo (int)$index; ?>); return false;" style="font-size: 0.8rem;">
                                <i class='bx bx-info-circle me-2 text-info'></i> View Details
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-3 mb-1 px-3 py-2 d-flex align-items-center" href="#" 
                               onclick="SSLManager.troubleshoot('<?php echo htmlspecialchars($cert['main_domain']); ?>'); return false;" style="font-size: 0.8rem;">
                                <i class='bx bx-wrench me-2 text-warning'></i> Troubleshoot
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="sslDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content blur shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h4 class="modal-title fw-bold" id="sslDetailTitle">Certificate Details</h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-2" id="sslDetailBody">
                <!-- Populated dynamically -->
            </div>
            <div class="modal-footer border-0 pb-4 px-4">
                <button type="button" class="btn btn-secondary px-4 rounded-pill" data-coreui-dismiss="modal">Okay</button>
            </div>
        </div>
    </div>
</div>

<!-- Troubleshoot Modal -->
<div class="modal fade" id="sslTroubleshootModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content blur shadow-lg rounded-4">
            <div class="modal-header border-0 pt-4 px-4">
                <h4 class="modal-title fw-bold">
                    <i class="bx bx-search-alt text-warning me-2"></i>
                    <span id="troubleshootTitle">SSL Troubleshoot</span>
                </h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-2" id="troubleshootBody">
                <div class="text-center py-5" id="troubleshootLoader">
                    <div class="spinner-border text-info mb-3" role="status"></div>
                    <p class="text-secondary small">Verifying DNS records for all domains...</p>
                </div>
                <div id="troubleshootResults" style="display: none;"></div>
            </div>
            <div class="modal-footer border-0 pb-4 px-4">
                <button type="button" class="btn btn-secondary px-4 rounded-pill" data-coreui-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.bg-purple { background-color: #7c3aed !important; }
.ssl-inner-card {
    background: rgba(255, 255, 255, 0.04) !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    border-radius: 12px;
    padding: 14px 16px;
}
.ssl-inner-card table { background: transparent !important; }
.ssl-inner-card th {
    background: transparent !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
}
.ssl-inner-card td {
    background: transparent !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
}
.ssl-inner-card tr:last-child td {
    border-bottom: none !important;
}
</style>

<script>
// Store cert data for JS access (already escaped via PHP json_encode)
const SSL_CERTS = <?php echo json_encode($certs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const SSL_SERVER_IP = <?php echo json_encode($serverIP, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const SSLManager = {
    /**
     * View certificate details in a modal.
     */
    viewDetails(index) {
        const cert = SSL_CERTS[index];
        if (!cert) return;

        const titleEl = document.getElementById('sslDetailTitle');
        titleEl.textContent = 'Certificate: ' + cert.main_domain;

        const body = document.getElementById('sslDetailBody');
        // Clear previous content safely
        body.replaceChildren();

        // "Domains in this certificate" label
        const label = document.createElement('p');
        label.className = 'text-secondary small fw-bold mb-2';
        label.textContent = 'Domains in this certificate:';
        body.appendChild(label);

        // Build the table
        const table = document.createElement('table');
        table.className = 'table table-borderless mb-0';

        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        ['Domain', 'Points to', 'Renewal'].forEach(text => {
            const th = document.createElement('th');
            th.className = 'text-secondary fw-bold';
            th.style.cssText = 'font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px;';
            th.textContent = text;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        (cert.sans || []).forEach(san => {
            const row = document.createElement('tr');

            const domainTd = document.createElement('td');
            const domainLink = document.createElement('span');
            domainLink.className = 'text-info fw-semibold';
            domainLink.textContent = san;
            domainTd.appendChild(domainLink);
            row.appendChild(domainTd);

            const ipTd = document.createElement('td');
            const ipSpan = document.createElement('span');
            ipSpan.className = 'font-monospace small';
            ipSpan.textContent = SSL_SERVER_IP;
            ipTd.appendChild(ipSpan);
            row.appendChild(ipTd);

            const renewalTd = document.createElement('td');
            const renewalBadge = document.createElement('span');
            renewalBadge.className = 'badge bg-success bg-opacity-10 text-success rounded-pill fw-bold';
            renewalBadge.style.cssText = 'font-size:0.72rem;';
            renewalBadge.textContent = 'renewable';
            renewalTd.appendChild(renewalBadge);
            row.appendChild(renewalTd);

            tbody.appendChild(row);
        });
        table.appendChild(tbody);

        // Wrap table in a card
        const tableCard = document.createElement('div');
        tableCard.className = 'ssl-inner-card rounded-4 mb-3';
        tableCard.appendChild(table);
        body.appendChild(tableCard);

        // Metadata section
        const meta = document.createElement('div');
        meta.className = 'ssl-inner-card rounded-4';
        const metaBody = document.createElement('div');
        metaBody.className = 'card-body';

        const items = [
            { icon: 'bx-check-shield', label: 'Resolver', value: cert.resolver, color: 'text-success' },
            { icon: 'bx-calendar', label: 'Issued', value: cert.issued || 'Unknown' },
            { icon: 'bx-time-five', label: 'Expires', value: cert.expires || 'Unknown' },
            { icon: 'bx-server', label: 'Used by', value: cert.used_by || 'Not assigned to a lab' }
        ];

        items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'd-flex align-items-center gap-2 mb-1';

            const icon = document.createElement('i');
            icon.className = 'bx ' + item.icon + ' text-secondary';
            div.appendChild(icon);

            const strong = document.createElement('strong');
            strong.className = 'text-secondary me-1';
            strong.textContent = item.label + ':';
            div.appendChild(strong);

            const span = document.createElement('span');
            span.className = item.color || '';
            span.textContent = item.value;
            div.appendChild(span);

            metaBody.appendChild(div);
        });

        meta.appendChild(metaBody);
        body.appendChild(meta);

        // Show modal
        const modal = new coreui.Modal(document.getElementById('sslDetailModal'));
        modal.show();
    },

    /**
     * Troubleshoot a specific certificate.
     */
    troubleshoot(mainDomain) {
        const titleEl = document.getElementById('troubleshootTitle');
        titleEl.textContent = 'Troubleshoot: ' + mainDomain;

        const loader = document.getElementById('troubleshootLoader');
        const results = document.getElementById('troubleshootResults');
        loader.style.display = 'block';
        results.style.display = 'none';
        results.replaceChildren();

        const modal = new coreui.Modal(document.getElementById('sslTroubleshootModal'));
        modal.show();

        fetch('/api/ssl/troubleshoot?domain=' + encodeURIComponent(mainDomain))
            .then(r => r.json())
            .then(data => {
                loader.style.display = 'none';
                results.style.display = 'block';

                if (!data.success) {
                    const errP = document.createElement('p');
                    errP.className = 'text-danger';
                    errP.textContent = data.error || 'Troubleshoot failed.';
                    results.appendChild(errP);
                    return;
                }

                const res = data.result;

                // Summary banner
                const summary = document.createElement('div');
                summary.className = 'alert d-flex align-items-center gap-2 mb-3 fw-semibold';
                summary.style.cssText = res.issues_found === 0
                    ? 'background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.25); color:#22c55e;'
                    : 'background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25); color:#ef4444;';

                const summaryIcon = document.createElement('i');
                summaryIcon.className = res.issues_found === 0 ? 'bx bx-check-circle fs-5' : 'bx bx-error-circle fs-5';
                summary.appendChild(summaryIcon);

                const summaryText = document.createElement('span');
                summaryText.textContent = res.issues_found === 0
                    ? 'All ' + res.total_sans + ' domains passed verification!'
                    : res.issues_found + ' of ' + res.total_sans + ' domains have issues.';
                summary.appendChild(summaryText);
                results.appendChild(summary);

                // Results table
                const table = document.createElement('table');
                table.className = 'table table-borderless mb-0';

                const thead = document.createElement('thead');
                const headerRow = document.createElement('tr');
                ['Domain', 'Points to', 'Status', 'Issues'].forEach(h => {
                    const th = document.createElement('th');
                    th.className = 'text-secondary fw-bold';
                    th.style.cssText = 'font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px;';
                    th.textContent = h;
                    headerRow.appendChild(th);
                });
                thead.appendChild(headerRow);
                table.appendChild(thead);

                const tbody = document.createElement('tbody');
                (res.domains || []).forEach(d => {
                    const row = document.createElement('tr');

                    const domainTd = document.createElement('td');
                    const domainSpan = document.createElement('span');
                    domainSpan.className = 'text-info fw-semibold';
                    domainSpan.textContent = d.domain;
                    domainTd.appendChild(domainSpan);
                    row.appendChild(domainTd);

                    const ipTd = document.createElement('td');
                    const ipSpan = document.createElement('span');
                    ipSpan.className = 'font-monospace small';
                    ipSpan.textContent = d.points_to || 'N/A';
                    ipTd.appendChild(ipSpan);
                    row.appendChild(ipTd);

                    const statusTd = document.createElement('td');
                    const statusBadge = document.createElement('span');
                    const statusMap = {
                        'ok': { cls: 'bg-success bg-opacity-10 text-success', label: 'OK' },
                        'warning': { cls: 'bg-warning bg-opacity-10 text-warning', label: 'Warning' },
                        'error': { cls: 'bg-danger bg-opacity-10 text-danger', label: 'Error' }
                    };
                    const s = statusMap[d.status] || statusMap['error'];
                    statusBadge.className = 'badge rounded-pill fw-bold ' + s.cls;
                    statusBadge.style.cssText = 'font-size:0.72rem;';
                    statusBadge.textContent = s.label;
                    statusTd.appendChild(statusBadge);
                    row.appendChild(statusTd);

                    const issuesTd = document.createElement('td');
                    if (d.issues && d.issues.length > 0) {
                        d.issues.forEach(issue => {
                            const issueSpan = document.createElement('div');
                            issueSpan.className = 'small text-danger';
                            issueSpan.textContent = issue;
                            issuesTd.appendChild(issueSpan);
                        });
                    } else {
                        const noneSpan = document.createElement('span');
                        noneSpan.className = 'small text-success';
                        noneSpan.textContent = 'No issues';
                        issuesTd.appendChild(noneSpan);
                    }
                    row.appendChild(issuesTd);

                    tbody.appendChild(row);
                });
                table.appendChild(tbody);

                // Wrap table in a card
                const tableCard = document.createElement('div');
                tableCard.className = 'ssl-inner-card rounded-4 mb-3';
                tableCard.appendChild(table);
                results.appendChild(tableCard);

                // Certificate metadata
                const metaCard = document.createElement('div');
                metaCard.className = 'ssl-inner-card rounded-4';
                const metaBody = document.createElement('div');
                metaBody.className = 'card-body';

                const metaItems = [
                    { icon: 'bx-check-shield', label: 'Resolver', value: res.resolver },
                    { icon: 'bx-calendar', label: 'Issued', value: res.issued || 'Unknown' },
                    { icon: 'bx-time-five', label: 'Expires', value: res.expires || 'Unknown' },
                    { icon: 'bx-server', label: 'Used by', value: res.used_by || 'Not assigned' }
                ];
                metaItems.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'd-flex align-items-center gap-2 mb-1';

                    const icon = document.createElement('i');
                    icon.className = 'bx ' + item.icon + ' text-secondary';
                    div.appendChild(icon);

                    const strong = document.createElement('strong');
                    strong.className = 'text-secondary me-1';
                    strong.textContent = item.label + ':';
                    div.appendChild(strong);

                    const span = document.createElement('span');
                    span.textContent = item.value;
                    div.appendChild(span);

                    metaBody.appendChild(div);
                });
                metaCard.appendChild(metaBody);
                results.appendChild(metaCard);
            })
            .catch(err => {
                loader.style.display = 'none';
                results.style.display = 'block';
                const errP = document.createElement('p');
                errP.className = 'text-danger small';
                errP.textContent = 'Network error: ' + err.message;
                results.appendChild(errP);
            });
    },

    /**
     * Troubleshoot ALL certificates via real-time API check.
     */
    troubleshootAll() {
        if (SSL_CERTS.length === 0) return;

        const titleEl = document.getElementById('troubleshootTitle');
        titleEl.textContent = 'Troubleshoot All Certificates';

        const loader = document.getElementById('troubleshootLoader');
        const results = document.getElementById('troubleshootResults');
        loader.style.display = 'block';
        results.style.display = 'none';
        results.replaceChildren();

        const modal = new coreui.Modal(document.getElementById('sslTroubleshootModal'));
        modal.show();

        fetch('/api/ssl/troubleshoot_all')
            .then(r => r.json())
            .then(data => {
                loader.style.display = 'none';
                results.style.display = 'block';

                if (!data.success) {
                    const errP = document.createElement('p');
                    errP.className = 'text-danger';
                    errP.textContent = data.error || 'Troubleshoot failed.';
                    results.appendChild(errP);
                    return;
                }

                // Summary banner
                const summary = document.createElement('div');
                summary.className = 'alert d-flex align-items-center gap-2 mb-3 fw-semibold';
                const noIssues = data.high === 0;
                summary.style.cssText = noIssues
                    ? 'background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.25); color:#22c55e;'
                    : 'background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25); color:#ef4444;';

                const summaryIcon = document.createElement('i');
                summaryIcon.className = noIssues ? 'bx bx-check-circle fs-5' : 'bx bx-error-circle fs-5';
                summary.appendChild(summaryIcon);

                const summaryText = document.createElement('span');
                summaryText.textContent = noIssues
                    ? 'All ' + data.cert_count + ' certificates passed verification!'
                    : data.high + ' of ' + data.cert_count + ' certificates have issues.';
                summary.appendChild(summaryText);
                results.appendChild(summary);

                // Results table
                const table = document.createElement('table');
                table.className = 'table table-borderless mb-0';

                const thead = document.createElement('thead');
                const headerRow = document.createElement('tr');
                ['Certificate', 'SANs', 'Status', 'Issues', 'Expires'].forEach(h => {
                    const th = document.createElement('th');
                    th.className = 'text-secondary fw-bold';
                    th.style.cssText = 'font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px;';
                    th.textContent = h;
                    headerRow.appendChild(th);
                });
                thead.appendChild(headerRow);
                table.appendChild(thead);

                const tbody = document.createElement('tbody');
                (data.findings || []).forEach(f => {
                    const row = document.createElement('tr');

                    const domainTd = document.createElement('td');
                    const domainSpan = document.createElement('span');
                    domainSpan.className = 'text-info fw-semibold';
                    domainSpan.textContent = f.domain;
                    domainTd.appendChild(domainSpan);
                    if (f.used_by) {
                        const usedByDiv = document.createElement('div');
                        usedByDiv.className = 'small text-secondary mt-1';
                        usedByDiv.textContent = f.used_by;
                        domainTd.appendChild(usedByDiv);
                    }
                    row.appendChild(domainTd);

                    const sansTd = document.createElement('td');
                    sansTd.className = 'font-monospace small';
                    sansTd.textContent = f.total_sans || '-';
                    row.appendChild(sansTd);

                    const statusTd = document.createElement('td');
                    const statusBadge = document.createElement('span');
                    const statusMap = {
                        'ok': { cls: 'bg-success bg-opacity-10 text-success', label: 'OK' },
                        'warning': { cls: 'bg-warning bg-opacity-10 text-warning', label: 'Warning' },
                        'error': { cls: 'bg-danger bg-opacity-10 text-danger', label: 'Error' }
                    };
                    const s = statusMap[f.status] || statusMap['error'];
                    statusBadge.className = 'badge rounded-pill fw-bold ' + s.cls;
                    statusBadge.style.cssText = 'font-size:0.72rem;';
                    statusBadge.textContent = s.label;
                    statusTd.appendChild(statusBadge);
                    row.appendChild(statusTd);

                    const issuesTd = document.createElement('td');
                    issuesTd.className = f.issues > 0 ? 'text-danger fw-semibold' : 'text-success';
                    issuesTd.textContent = f.issues || '0';
                    row.appendChild(issuesTd);

                    const expiresTd = document.createElement('td');
                    expiresTd.className = 'small';
                    if (f.days_left !== null) {
                        const daysColor = f.days_left > 30 ? 'text-success' : (f.days_left > 7 ? 'text-warning' : 'text-danger');
                        expiresTd.innerHTML = f.expires ? f.expires.split(' ').slice(0, 1).join(' ') : '-';
                        const daysSpan = document.createElement('span');
                        daysSpan.className = ' ' + daysColor;
                        daysSpan.textContent = ' (' + f.days_left + 'd)';
                        expiresTd.appendChild(daysSpan);
                    } else {
                        expiresTd.textContent = '-';
                    }
                    row.appendChild(expiresTd);

                    tbody.appendChild(row);
                });
                table.appendChild(tbody);

                const tableCard = document.createElement('div');
                tableCard.className = 'ssl-inner-card rounded-4 mb-3';
                tableCard.appendChild(table);
                results.appendChild(tableCard);

                // Checked at timestamp
                const ts = document.createElement('div');
                ts.className = 'text-secondary small text-end mt-2';
                ts.textContent = 'Checked at: ' + new Date(data.checked_at * 1000).toLocaleString();
                results.appendChild(ts);
            })
            .catch(err => {
                loader.style.display = 'none';
                results.style.display = 'block';
                const errP = document.createElement('p');
                errP.className = 'text-danger small';
                errP.textContent = 'Network error: ' + err.message;
                results.appendChild(errP);
            });
    },

    /**
     * Refresh certificates via API.
     */
    _refreshing: false,
    refresh() {
        if (SSLManager._refreshing) return;
        SSLManager._refreshing = true;

        const btn = document.getElementById('btnRefresh');
        btn.disabled = true;
        btn.replaceChildren();
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-1';
        spinner.setAttribute('role', 'status');
        btn.appendChild(spinner);
        btn.appendChild(document.createTextNode(' Refreshing...'));

        fetch('/api/ssl/refresh')
            .then(r => {
                if (r.status === 429) {
                    SSLManager._restoreRefreshBtn(btn);
                    return null;
                }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                if (data && data.success) {
                    window.location.reload();
                } else if (data && !data.success && data.error) {
                    if (window.TomNotify) TomNotify.show(data.error, 'Refresh Failed', 'danger', 4000);
                    SSLManager._restoreRefreshBtn(btn);
                }
            })
            .catch(() => {
                if (window.TomNotify) TomNotify.show('Network error while refreshing certificates.', 'Error', 'danger', 4000);
                SSLManager._restoreRefreshBtn(btn);
            });
    },

    _restoreRefreshBtn(btn) {
        SSLManager._refreshing = false;
        btn.disabled = false;
        btn.replaceChildren();
        const icon = document.createElement('i');
        icon.className = 'bx bx-refresh me-1';
        btn.appendChild(icon);
        btn.appendChild(document.createTextNode(' Refresh'));
    },

    toggleAutoManaged() {
        const cards = document.querySelectorAll('.auto-managed-card');
        const link = document.querySelector('a[onclick="SSLManager.toggleAutoManaged(); return false;"]');
        let isShowing = false;
        
        cards.forEach(card => {
            if (card.classList.contains('d-none')) {
                card.classList.remove('d-none', 'animate__animated', 'animate__fadeIn');
                // Trigger reflow
                void card.offsetWidth;
                card.classList.add('animate__animated', 'animate__fadeIn');
                isShowing = true;
            } else {
                card.classList.add('d-none');
                isShowing = false;
            }
        });
        
        if (link) {
            if (isShowing) {
                link.innerHTML = 'Hide auto-managed certificate(s)';
            } else {
                link.innerHTML = 'Show ' + cards.length + ' auto-managed certificate(s)';
            }
        }
    }
};
</script>
