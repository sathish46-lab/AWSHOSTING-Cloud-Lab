<?php
$slug = $_GET['slug'] ?? '';
$hash = $instance['instance_hash'] ?? '';
$status = $instance['status'] ?? 'draft';
$isActive = in_array($status, ['building', 'deploying', 'starting']);
?>
<div class="card blur border-0 rounded-4 p-4 shadow-lg" id="buildTab" data-hash="<?= htmlspecialchars($hash) ?>">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold theme-text m-0 d-flex align-items-center gap-2">
            <i class='bx bx-hammer fs-4'></i> Build & Validate
        </h5>
        <div class="d-flex gap-2">
            <button class="btn rounded-pill px-4 fw-bold" id="startBuildBtn"
                style="background-color: #ff4b2b; border-color: #ff4b2b; color: white; font-size: 0.8rem;"
                data-coreui-toggle="loading-button" data-coreui-spinner-type="grow">
                <i class='bx bx-play-circle'></i> Start Build
            </button>
        </div>
    </div>

    <div class="alert alert-dark border border-secondary border-opacity-25 bg-black bg-opacity-25 text-secondary mb-3 rounded-4 py-2 small">
        <i class='bx bx-info-circle me-1'></i>
        Build compiles your files into a Docker image. Only built images can be deployed.
    </div>

    <!-- Status Row -->
    <div class="d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-25 pb-2 mb-3">
        <span class="text-secondary fw-bold small text-uppercase">Build Status</span>
        <?php
            $buildStatusColor = 'primary';
            if ($status === 'built') $buildStatusColor = 'success';
            elseif (in_array($status, ['building', 'deploying'])) $buildStatusColor = 'warning';
            elseif ($status === 'error') $buildStatusColor = 'danger';
        ?>
        <span class="badge bg-<?= $buildStatusColor ?> rounded-pill px-3 py-1" id="buildStatusBadge">
            <?= htmlspecialchars($status) ?>
        </span>
    </div>

    <!-- Build Details Card (hidden until built) -->
    <div id="buildDetailsCard" class="d-none">
        <div class="rounded-3 p-3 mb-3" style="background-color: rgba(46,204,113,0.06); border: 1px solid rgba(46,204,113,0.15);">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class='bx bx-check-circle text-success fs-5'></i>
                <span class="text-success fw-bold small">Image Built Successfully</span>
            </div>
            <div class="row g-3 mt-0">
                <div class="col-md-6">
                    <div class="text-secondary small fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px; text-transform: uppercase;">Image Name</div>
                    <div class="theme-text font-monospace small" id="buildImageTag">-</div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px; text-transform: uppercase;">Template</div>
                    <div class="theme-text small" id="buildTemplate">-</div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px; text-transform: uppercase;">Image Size</div>
                    <div class="theme-text small fw-bold" id="buildImageSize">-</div>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-6">
                    <div class="text-secondary small fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px; text-transform: uppercase;">Built At</div>
                    <div class="theme-text small" id="buildBuiltAt">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Building Progress (hidden until building) -->
    <div id="buildProgress" class="d-none">
        <div class="rounded-3 p-3 mb-3" style="background-color: rgba(255,165,0,0.06); border: 1px solid rgba(255,165,0,0.15);">
            <div class="d-flex align-items-center gap-2">
                <div class="spinner-grow spinner-grow-sm text-warning" role="status"></div>
                <span class="text-warning fw-bold small">Building image...</span>
                <span class="text-secondary ms-auto small" id="buildElapsed">0s</span>
            </div>
        </div>
    </div>

    <!-- Build Error (hidden until error) -->
    <div id="buildError" class="d-none">
        <div class="rounded-3 p-3 mb-3" style="background-color: rgba(255,107,107,0.06); border: 1px solid rgba(255,107,107,0.15);">
            <div class="d-flex align-items-center gap-2">
                <i class='bx bx-error-circle text-danger fs-5'></i>
                <span class="text-danger fw-bold small">Build Failed</span>
            </div>
            <div class="text-secondary small mt-1" id="buildErrorMsg">Check server logs for details.</div>
        </div>
    </div>

    <div id="buildResult" class="d-none"></div>
</div>

<script>
(function() {
    const btn = document.getElementById('startBuildBtn');
    const statusBadge = document.getElementById('buildStatusBadge');
    const resultEl = document.getElementById('buildResult');
    const detailsCard = document.getElementById('buildDetailsCard');
    const buildProgress = document.getElementById('buildProgress');
    const buildError = document.getElementById('buildError');
    const hash = document.getElementById('buildTab')?.dataset.hash;
    if (!btn || !hash) return;

    let pollTimer = null;
    let buildStartTime = null;
    let elapsedTimer = null;

    function setBtnLoading(loading) {
        if (loading) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" role="status" aria-hidden="true"></span> Processing';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bx bx-play-circle"></i> Start Build';
        }
    }

    function formatTimestamp(ts) {
        if (!ts) return '-';
        const d = new Date(typeof ts === 'number' ? ts * 1000 : ts);
        if (isNaN(d.getTime())) return '-';
        return d.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    }

    function showState(state) {
        detailsCard.classList.add('d-none');
        buildProgress.classList.add('d-none');
        buildError.classList.add('d-none');
        resultEl.classList.add('d-none');
        if (state === 'built') detailsCard.classList.remove('d-none');
        if (state === 'building') buildProgress.classList.remove('d-none');
        if (state === 'error') buildError.classList.remove('d-none');
    }

    function setStatusBadge(text, color, bg) {
        statusBadge.textContent = text;
        statusBadge.style.backgroundColor = bg;
        statusBadge.style.color = color;
    }

    btn.addEventListener('click', async () => {
        setBtnLoading(true);
        showState('building');
        buildStartTime = Date.now();
        startElapsedTimer();
        setStatusBadge('building', '#ffa502', 'rgba(255,165,0,0.15)');
        if (window.appendInstanceLog) window.appendInstanceLog('[*] Build queued. Streaming logs...');

        try {
            const res = await fetch('/api/instances/build', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'hash=' + encodeURIComponent(hash)
            });
            const data = await res.json();

            if (data.status === 'success') {
                startPolling();
            } else {
                showState('error');
                document.getElementById('buildErrorMsg').textContent = data.error || 'Failed to queue build';
                setStatusBadge('error', '#ff6b6b', 'rgba(255,107,107,0.15)');
                setBtnLoading(false);
                stopElapsedTimer();
            }
        } catch (e) {
            showState('error');
            document.getElementById('buildErrorMsg').textContent = 'Network error';
            setStatusBadge('error', '#ff6b6b', 'rgba(255,107,107,0.15)');
            setBtnLoading(false);
            stopElapsedTimer();
        }
    });

    function startElapsedTimer() {
        stopElapsedTimer();
        const el = document.getElementById('buildElapsed');
        elapsedTimer = setInterval(() => {
            if (!buildStartTime) return;
            const secs = Math.floor((Date.now() - buildStartTime) / 1000);
            const m = Math.floor(secs / 60);
            const s = secs % 60;
            el.textContent = m > 0 ? `${m}m ${s}s` : `${s}s`;
        }, 1000);
    }

    function stopElapsedTimer() {
        if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollStatus, 2000);
    }

    async function pollStatus() {
        try {
            const res = await fetch('/api/instances/build_status?hash=' + encodeURIComponent(hash), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data.status !== 'success') return;

            const inst = data.instance_status;
            const b = data.build || {};

            if (inst === 'built') {
                clearInterval(pollTimer);
                pollTimer = null;
                stopElapsedTimer();
                setStatusBadge('built', '#2ecc71', 'rgba(46,204,113,0.15)');
                setBtnLoading(false);

                // Populate details card
                document.getElementById('buildImageTag').textContent = b.image_tag || '-';
                document.getElementById('buildTemplate').textContent = b.template || '-';
                document.getElementById('buildImageSize').textContent = b.image_size?.human || '-';
                document.getElementById('buildBuiltAt').textContent = formatTimestamp(b.built_at);
                showState('built');
                if (window.appendInstanceLog) window.appendInstanceLog('[✓] Build complete. Image ready for deployment.');

            } else if (inst === 'error') {
                clearInterval(pollTimer);
                pollTimer = null;
                stopElapsedTimer();
                setStatusBadge('error', '#ff6b6b', 'rgba(255,107,107,0.15)');
                showState('error');
                setBtnLoading(false);

            } else {
                // Still building
                setStatusBadge(inst || 'building', '#ffa502', 'rgba(255,165,0,0.15)');
                showState('building');
            }
        } catch (e) {}
    }

    // Initial load: fetch current build status
    async function loadInitialStatus() {
        try {
            const res = await fetch('/api/instances/build_status?hash=' + encodeURIComponent(hash), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data.status !== 'success') return;

            const inst = data.instance_status;
            const b = data.build || {};
            const hasImage = b && b.image_tag;

            if (hasImage && (inst === 'built' || inst === 'running' || inst === 'deploying' || inst === 'stopped' || inst === 'error')) {
                setStatusBadge('built', '#2ecc71', 'rgba(46,204,113,0.15)');
                document.getElementById('buildImageTag').textContent = b.image_tag || '-';
                document.getElementById('buildTemplate').textContent = b.template || '-';
                document.getElementById('buildImageSize').textContent = b.image_size?.human || '-';
                document.getElementById('buildBuiltAt').textContent = formatTimestamp(b.built_at);
                showState('built');
            } else if (inst === 'error' && !hasImage) {
                setStatusBadge('error', '#ff6b6b', 'rgba(255,107,107,0.15)');
                showState('error');
            } else if (inst === 'building') {
                setStatusBadge('building', '#ffa502', 'rgba(255,165,0,0.15)');
                showState('building');
                buildStartTime = Date.now();
                startElapsedTimer();
                startPolling();
                setBtnLoading(true);
            }
        } catch (e) {}
    }

    loadInitialStatus();

    if (<?= $isActive ? 'true' : 'false' ?>) {
        startPolling();
        setBtnLoading(true);
    }
})();
</script>
