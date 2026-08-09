<?php
$interfaces = Session::get('network_interfaces', []);
?>

<div class="blur mb-3 rounded-0">
    <div class="container-fluid px-4">
        <div class="row align-items-center py-3">
            <div class="col">
                <h1 class="fw-bold theme-text m-0 network-header-title">Network</h1>
                <p class="text-secondary opacity-75 mt-2 mb-0 network-header-desc">
                    WireGuard network interfaces available on this server.
                </p>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col">
                <ul class="nav nav-tabs lab-nav-tabs border-0" id="networkTabs">
                    <li class="nav-item">
                        <a class="nav-link" href="/network">
                            <i class='bx bx-network-chart me-1'></i> IP Addresses
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="/network/interfaces">
                            <i class='bx bx-link-alt me-1'></i> WG Interfaces
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
    <div class="row g-4 mb-4">
    <?php $idx = 0; foreach ($interfaces as $iface): ?>
    <?php
        $status = $iface['status'] ?? 'unknown';
        $statusConfig = [
            'up'     => ['label' => 'Available',  'color' => '#22c55e', 'bg' => 'rgba(34,197,94,0.12)',  'border' => 'rgba(34,197,94,0.3)'],
            'down'   => ['label' => 'Unavailable', 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.12)',  'border' => 'rgba(239,68,68,0.3)'],
            'unknown'=> ['label' => 'Unknown',     'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.12)', 'border' => 'rgba(245,158,11,0.3)'],
        ];
        $s = $statusConfig[$status] ?? $statusConfig['unknown'];
        $peerCount = $iface['peers'] ?? 0;
    ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card rounded-3 border-0 blur h-100 interface-card card-entrance" style="padding: 12px 16px; animation-delay: <?= ($idx++) * 80 ?>ms;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold m-0" style="font-size: 0.9rem;"><?= htmlspecialchars($iface['label'] ?? 'Public network') ?></h6>
                <span class="badge rounded-pill fw-bold border-0" style="font-size: 9px; padding: 2px 8px; background: rgba(255,255,255,0.08); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.12);">
                    <?= htmlspecialchars($iface['name']) ?>
                </span>
            </div>
            <span class="badge rounded-pill fw-semibold mb-2" style="font-size: 0.65rem; padding: 2px 10px; background: <?= $s['bg'] ?>; color: <?= $s['color'] ?>; border: 1px solid <?= $s['border'] ?>;">
                <?= htmlspecialchars($iface['description'] ?? 'Public network — everyone') ?>
            </span>
            <div>
                <div class="interface-detail-row"><span class="detail-label">CIDR:</span><code class="detail-value text-info"><?= htmlspecialchars($iface['cidr']) ?></code></div>
                <div class="interface-detail-row"><span class="detail-label">Port:</span><span class="detail-value"><?= (int)$iface['port'] ?></span></div>
                <div class="interface-detail-row"><span class="detail-label">Status:</span><span class="detail-value"><span class="d-inline-block rounded-circle me-1" style="width:5px;height:5px;background:<?= $s['color'] ?>;animation:pulse-<?= $status ?> 2s ease-in-out infinite;"></span><?= $s['label'] ?></span></div>
                <div class="interface-detail-row"><span class="detail-label">Peers:</span><span class="detail-value"><?= (int)$peerCount ?></span></div>
                <div class="interface-detail-row"><span class="detail-label">Traffic:</span><span class="detail-value"><i class="bx bx-down-arrow-alt text-info"></i><?= htmlspecialchars($iface['total_rx'] ?? '0 B') ?> <i class="bx bx-up-arrow-alt text-success"></i><?= htmlspecialchars($iface['total_tx'] ?? '0 B') ?></span></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<style>
@keyframes pulse-up {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}
@keyframes pulse-down {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}
@keyframes pulse-unknown {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.interface-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.interface-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3) !important;
}
.interface-detail-row {
    padding: 2px 0;
    font-size: 0.78rem;
    line-height: 1.3;
}
.detail-label {
    color: #94a3b8;
    margin-right: 4px;
}
.detail-value {
    color: #e2e8f0;
}
</style>
