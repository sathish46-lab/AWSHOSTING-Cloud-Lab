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
    <?php foreach ($interfaces as $iface): ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-lg rounded-4 border-0 blur h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h5 class="fw-bold m-0"><?= htmlspecialchars($iface['name']) ?></h5>
                    <span class="badge rounded-pill fw-bold bg-dark border border-secondary text-white" style="font-size:10px; padding:4px 10px;"><?= htmlspecialchars($iface['name']) ?></span>
                </div>
                <div class="mb-2">
                    <span class="badge rounded-pill fw-bold bg-success border-0" style="font-size:10px; padding:4px 10px;"><?= htmlspecialchars($iface['description']) ?></span>
                </div>
                <div class="small">
                    <b>CIDR:</b> <code><?= htmlspecialchars($iface['cidr']) ?></code><br>
                    <b>Port:</b> <?= (int)$iface['port'] ?><br>
                    <b>Status:</b> <span class="d-inline-block rounded-circle me-1" style="width:.6rem;height:.6rem;background:var(--cui-success, #2eb85c);"></span><?= ucfirst(htmlspecialchars($iface['status'])) ?><br>
                    <b>Peers:</b> <?= (int)$iface['peers'] ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
