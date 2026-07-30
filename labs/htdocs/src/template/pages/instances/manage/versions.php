<?php
$hash = $instance['instance_hash'] ?? '';

$instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$versionsCol = $instDb->instance_versions;
$versions = $versionsCol->find(['instance_hash' => $hash], ['sort' => ['created_at' => -1]])->toArray();
?>
<div class="card blur border-0 rounded-4 p-4 shadow-lg" id="versionsTab" data-hash="<?= htmlspecialchars($hash) ?>">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h5 class="fw-bold theme-text m-0 d-flex align-items-center gap-2">
            <i class='bx bx-history fs-4'></i> Version History
        </h5>
        <button class="btn rounded-pill px-4 fw-bold" id="saveVersionBtn"
            style="background-color: #ff4b2b; border-color: #ff4b2b; color: white;">
            <i class='bx bx-save'></i> Save Version
        </button>
    </div>

    <div class="alert alert-dark border border-secondary border-opacity-25 bg-black bg-opacity-25 text-secondary mb-4 rounded-4 py-3">
        <i class='bx bx-info-circle me-2'></i>
        Snapshots capture your current files and config. Save before major changes to enable rollback.
    </div>

    <?php if (empty($versions)): ?>
    <div class="text-center py-5">
        <div class="text-secondary opacity-50 mb-2"><i class='bx bx-history fs-1'></i></div>
        <div class="text-secondary small">No versions saved yet.</div>
    </div>
    <?php else: ?>
    <div class="d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-25 pb-2 mb-3">
        <span class="text-secondary fw-bold small text-uppercase">VERSIONS <span class="badge bg-primary rounded-pill px-2 py-1 fw-bold ms-2"><?= count($versions) ?></span></span>
    </div>

    <div class="table-responsive">
        <table class="table table-borderless mb-0" style="color: rgba(255,255,255,0.7);">
            <thead>
                <tr class="text-secondary small text-uppercase" style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <th class="fw-bold">Version</th>
                    <th class="fw-bold">Saved</th>
                    <th class="fw-bold">Files</th>
                    <th class="fw-bold">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($versions as $v): ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <td>
                        <span class="fw-bold text-info"><?= htmlspecialchars($v['label'] ?? 'untitled') ?></span>
                    </td>
                    <td class="text-secondary small">
                        <?= date('M j, Y g:i A', (int)($v['created_at'] ?? time())) ?>
                    </td>
                    <td class="text-secondary small">
                        <?= count($v['files_snapshot'] ?? []) ?> files
                    </td>
                    <td>
                        <button class="btn btn-sm rounded-pill px-3 fw-bold restore-version-btn" data-version-id="<?= htmlspecialchars((string)($v['_id'])) ?>"
                            style="background-color: rgba(255,165,0,0.15); border: 1px solid rgba(255,165,0,0.3); color: #ffa502; font-size: 0.75rem;">
                            <i class='bx bx-undo'></i> Restore
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function() {

    document.addEventListener('click', async (e) => {
        const hash = document.getElementById('versionsTab')?.dataset.hash;
        if (!hash) return;

        if (e.target.closest('#saveVersionBtn')) {
            const btn = e.target.closest('#saveVersionBtn');
            const label = prompt('Version label (e.g. v1.0, before refactor):');
            if (label === null) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Saving...';
            try {
                const res = await fetch('/api/instances/save_version', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'hash=' + encodeURIComponent(hash) + '&label=' + encodeURIComponent(label || 'untitled')
                });
                const data = await res.json();
                if (data.status === 'success') {
                    htmx.ajax('GET', location.href, '#main-content');
                } else {
                    alert(data.error || 'Save failed');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bx bx-save"></i> Save Version';
                }
            } catch (err) {
                alert('Network error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bx bx-save"></i> Save Version';
            }
        }

        if (e.target.closest('.restore-version-btn')) {
            const btn = e.target.closest('.restore-version-btn');
            const versionId = btn.dataset.versionId;
            if (!confirm('Restore this version? Current files will be overwritten.')) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span>';
            try {
                const res = await fetch('/api/instances/restore_version', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'hash=' + encodeURIComponent(hash) + '&version_id=' + encodeURIComponent(versionId)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    htmx.ajax('GET', location.href, '#main-content');
                } else {
                    alert(data.error || 'Restore failed');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bx bx-undo"></i> Restore';
                }
            } catch (err) {
                alert('Network error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bx bx-undo"></i> Restore';
            }
        }
    });
})();
</script>
