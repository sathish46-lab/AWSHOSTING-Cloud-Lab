<?php
$hash = $instance['instance_hash'] ?? '';

$instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$sharesCol = $instDb->instance_shares;
$shares = $sharesCol->find(['instance_hash' => $hash])->toArray();
$ownerId = $instance['user_id'] ?? 0;
$currentUser = Session::getUser();
$isOwner = ($currentUser->getUserId() == $ownerId);

$roleColors = [
    'owner'    => ['#2ecc71', 'rgba(46,204,113,0.15)'],
    'operator' => ['#ffa502', 'rgba(255,165,0,0.15)'],
    'manager'  => ['#5299E0', 'rgba(82,153,224,0.15)'],
    'viewer'   => ['#a9b7c6', 'rgba(169,183,198,0.15)'],
];
?>
<div class="card blur border-0 rounded-4 p-4 shadow-lg" id="sharingTab" data-hash="<?= htmlspecialchars($hash) ?>" data-owner="<?= $isOwner ? '1' : '0' ?>">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h5 class="fw-bold theme-text m-0 d-flex align-items-center gap-2">
            <i class='bx bx-share-alt fs-4'></i> Sharing & Delegation
        </h5>
        <?php if ($isOwner): ?>
        <button class="btn rounded-pill px-4 fw-bold" id="addShareBtn"
            style="background-color: #ff4b2b; border-color: #ff4b2b; color: white;">
            <i class='bx bx-user-plus'></i> Share
        </button>
        <?php endif; ?>
    </div>

    <div class="alert alert-dark border border-secondary border-opacity-25 bg-black bg-opacity-25 text-secondary mb-4 rounded-4 py-3">
        <i class='bx bx-info-circle me-2'></i>
        <strong>Roles:</strong> viewer (read-only) → manager (edit files) → operator (deploy/stop) → owner (full control + terminate)
    </div>

    <?php if (empty($shares)): ?>
    <div class="text-center py-5">
        <div class="text-secondary opacity-50 mb-2"><i class='bx bx-share-alt fs-1'></i></div>
        <div class="text-secondary small">Not shared with anyone yet.</div>
    </div>
    <?php else: ?>
    <div class="d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-25 pb-2 mb-3">
        <span class="text-secondary fw-bold small text-uppercase">SHARED WITH <span class="badge bg-primary rounded-pill px-2 py-1 fw-bold ms-2"><?= count($shares) ?></span></span>
    </div>

    <div id="sharesList">
        <?php foreach ($shares as $s):
            $role = $s['role'] ?? 'viewer';
            $roleColorMap = [
                'owner'    => 'success',
                'operator' => 'warning',
                'manager'  => 'primary',
                'viewer'   => 'info',
            ];
            $roleColor = $roleColorMap[$role] ?? 'info';
        ?>
        <div class="d-flex align-items-center justify-content-between p-3 mb-2 rounded-3" style="background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);" data-share-user="<?= htmlspecialchars($s['shared_with'] ?? '') ?>">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; background-color: rgba(255,255,255,0.05);">
                    <i class='bx bx-user text-secondary'></i>
                </div>
                <div>
                    <div class="fw-bold" style="color: rgba(255,255,255,0.9);"><?= htmlspecialchars($s['shared_with'] ?? '') ?></div>
                    <div class="text-secondary small">by <?= htmlspecialchars($s['shared_by'] ?? '') ?> · <?= date('M j', (int)($s['created_at'] ?? time())) ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-<?= $roleColor ?> rounded-pill px-2 py-1">
                    <?= $role ?>
                </span>
                <?php if ($isOwner): ?>
                <button class="btn btn-sm remove-share-btn" data-user="<?= htmlspecialchars($s['shared_with'] ?? '') ?>"
                    style="color: #ff6b6b; background: none; border: none;">
                    <i class='bx bx-x'></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
    <div id="addShareForm" class="d-none mt-4 p-4 rounded-4" style="background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
        <div class="fw-bold theme-text mb-3">Share with user</div>
        <div class="d-flex align-items-center gap-2 mb-3">
            <input type="text" class="form-control config-input flex-grow-1" id="shareUsername" placeholder="Username or email" style="max-width: 300px;">
            <select class="form-select config-input" id="shareRole" style="max-width: 150px;">
                <option value="viewer">Viewer</option>
                <option value="manager">Manager</option>
                <option value="operator">Operator</option>
            </select>
            <button class="btn rounded-pill px-3 fw-bold" id="confirmShareBtn"
                style="background-color: #ff4b2b; border-color: #ff4b2b; color: white;">
                <i class='bx bx-check'></i> Share
            </button>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="canReshare" checked>
            <label class="form-check-label text-secondary small">Allow this user to re-share (at or below their role)</label>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const hash = document.getElementById('sharingTab')?.dataset.hash;
    const isOwner = document.getElementById('sharingTab')?.dataset.owner === '1';
    if (!hash) return;

    if (isOwner) {
        document.addEventListener('click', async (e) => {
            if (e.target.closest('#addShareBtn')) {
                const form = document.getElementById('addShareForm');
                if (form) form.classList.toggle('d-none');
            }

            if (e.target.closest('#confirmShareBtn')) {
                const username = document.getElementById('shareUsername')?.value.trim();
                const role = document.getElementById('shareRole')?.value;
                const canReshare = document.getElementById('canReshare')?.checked;
                if (!username) return alert('Enter a username');

                const btn = e.target.closest('#confirmShareBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span>';
                try {
                    const res = await fetch('/api/instances/share', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ hash, username, role, can_reshare: canReshare ? '1' : '0' })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        htmx.ajax('GET', location.href, '#main-content');
                    } else {
                        alert(data.error || 'Share failed');
                    }
                } catch (err) {
                    alert('Network error');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bx bx-user-plus"></i> Share';
                }
            }

            if (e.target.closest('.remove-share-btn')) {
                const btn = e.target.closest('.remove-share-btn');
                const user = btn.dataset.user;
                if (!confirm('Remove access for ' + user + '?')) return;
                try {
                    const res = await fetch('/api/instances/remove_share', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ hash, username: user })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        btn.closest('[data-share-user]')?.remove();
                    } else {
                        alert(data.error || 'Failed');
                    }
                } catch (err) {
                    alert('Network error');
                }
            }
        });
    }
})();
</script>
