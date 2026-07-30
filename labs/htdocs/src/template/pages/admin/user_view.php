<?php
require_once __DIR__ . '/../../../../src/load.php';

$email = $_GET['email'] ?? '';
$db = DatabaseConnection::getDefaultDatabase();
$userData = $db->users->findOne(['email' => $email]);

if (!$userData) {
    echo "User not found.";
    return;
}

$user = new User($email);
$gravatarHash = md5(strtolower(trim($email)));
$avatar = $userData['avatar'] ?? "https://www.gravatar.com/avatar/{$gravatarHash}?d=identicon&s=200";

// Get user labs and domains
$deployedLabs = iterator_to_array($db->machine_labs->find(['email' => $email]));
$domains = iterator_to_array($db->domains->find(['email' => $email]));
$quizzes = $userData['quizzes_completed'] ?? [];

$lastLoginTs = isset($userData['last_login']) ? (is_numeric($userData['last_login']) ? (int)$userData['last_login'] : strtotime($userData['last_login'])) : 0;
$createdTs = isset($userData['created_at']) ? (is_numeric($userData['created_at']) ? (int)$userData['created_at'] : strtotime($userData['created_at'])) : 0;
?>
<div class="container-fluid px-4 py-4">
    <div class="d-flex align-items-center mb-4">
        <a href="/admin/users" class="btn btn-sm btn-outline-secondary border-secondary border-opacity-25 rounded-pill me-3 px-2">
            <i class='bx bx-left-arrow-alt'></i>
        </a>
        <div>
            <h2 class="fw-bold mb-0">User Profile</h2>
            <p class="text-body-secondary mb-0 mt-1 small"><?= htmlspecialchars($email) ?></p>
        </div>
    </div>

    <div class="row">
        <!-- Overview Card -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 rounded-4 blur shadow-sm h-100">
                <div class="card-body text-center py-5">
                    <img src="<?= $avatar ?>" class="rounded-circle mb-3 shadow-lg border border-body-secondary border-opacity-10" style="width: 100px; height: 100px; object-fit: cover;">
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($user->getFullName() ?? 'Unknown') ?></h4>
                    <span class="badge <?= (($userData['role'] ?? '') === 'superuser') ? 'bg-warning text-dark' : 'bg-body-secondary' ?> mb-3">
                        <?= ucfirst($userData['role'] ?? 'User') ?>
                    </span>
                    <hr class="border-body-secondary border-opacity-10 mx-4">
                    <div class="d-flex justify-content-between px-4 text-start mt-3">
                        <span class="text-body-secondary small">Last Login</span>
                        <span class="small fw-semibold"><?= $lastLoginTs ? date('M j, Y h:i A', $lastLoginTs) : 'Never' ?></span>
                    </div>
                    <div class="d-flex justify-content-between px-4 text-start mt-2">
                        <span class="text-body-secondary small">Created</span>
                        <span class="small fw-semibold"><?= $createdTs ? date('M j, Y h:i A', $createdTs) : 'Unknown' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Specific Feature Overrides -->
        <div class="col-md-8 mb-4">
            <div class="card border-0 rounded-4 blur shadow-sm h-100">
                <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3">
                    <h5 class="mb-0 fw-bold"><i class='bx bx-slider-alt text-success me-2'></i>Feature Overrides</h5>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary small mb-4">Override specific lab features exclusively for this user. These settings bypass standard lab restrictions.</p>
                    
                    <div class="list-group list-group-flush bg-transparent">
                        <?php 
                        $userFeaturesDoc = $user->getLabFeatures(); 
                        $userFeatures = ($userFeaturesDoc && is_object($userFeaturesDoc) && method_exists($userFeaturesDoc, 'getArrayCopy')) ? $userFeaturesDoc->getArrayCopy() : ((array)$userFeaturesDoc ?: []);
                        $featuresList = [
                            'http_proxies' => 'HTTP Proxies (Reverse Proxy Domains)',
                            'expose_web' => 'Expose Web Publicly',
                            'startup_script' => 'Custom Startup Bash Scripts',
                            'always_on' => 'Always On (No Auto-Expiration)'
                        ];
                        foreach($featuresList as $key => $label):
                            $isChecked = !empty($userFeatures[$key]);
                        ?>
                        <div class="list-group-item bg-transparent border-body-secondary border-opacity-10 d-flex justify-content-between align-items-center py-3 px-0">
                            <div>
                                <h6 class="mb-1 fw-semibold"><?= $label ?></h6>
                                <small class="text-body-secondary font-monospace"><?= $key ?></small>
                            </div>
                            <div class="form-check form-switch fs-4 mb-0">
                                <input class="form-check-input pointer user-feature-toggle" type="checkbox" role="switch" data-feature="<?= $key ?>" <?= $isChecked ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Labs & Domains -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card border-0 rounded-4 blur shadow-sm h-100">
                <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold"><i class='bx bx-server text-primary me-2'></i>Active Labs</h5>
                    <span class="badge bg-primary rounded-pill"><?= count($deployedLabs) ?></span>
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <ul class="list-group list-group-flush bg-transparent">
                        <?php if (empty($deployedLabs)): ?>
                            <li class="list-group-item bg-transparent text-body-secondary py-4 text-center border-0">No active labs found.</li>
                        <?php else: ?>
                            <?php foreach($deployedLabs as $lab): ?>
                            <li class="list-group-item bg-transparent border-body-secondary border-opacity-10 py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="fw-bold mb-1"><?= htmlspecialchars($lab['lab_type'] ?? 'Unknown') ?></h6>
                                        <span class="text-body-secondary small font-monospace"><?= htmlspecialchars($lab['instance_hash'] ?? '') ?></span>
                                    </div>
                                    <span class="badge rounded-pill bg-success">Running</span>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card border-0 rounded-4 blur shadow-sm h-100">
                <div class="card-header border-bottom border-body-secondary border-opacity-10 bg-transparent py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold"><i class='bx bx-globe text-info me-2'></i>Custom Domains</h5>
                    <span class="badge bg-info text-dark rounded-pill"><?= count($domains) ?></span>
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <ul class="list-group list-group-flush bg-transparent">
                        <?php if (empty($domains)): ?>
                            <li class="list-group-item bg-transparent text-body-secondary py-4 text-center border-0">No domains configured.</li>
                        <?php else: ?>
                            <?php foreach($domains as $domain): ?>
                            <li class="list-group-item bg-transparent border-body-secondary border-opacity-10 py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex flex-column">
                                        <a href="http://<?= htmlspecialchars($domain['domain']) ?>" target="_blank" class="text-decoration-none fw-bold mb-1">
                                            <?= htmlspecialchars($domain['domain']) ?> <i class='bx bx-link-external small opacity-50'></i>
                                        </a>
                                        <span class="text-body-secondary small">Port: <?= htmlspecialchars($domain['port']) ?> | Target: <?= htmlspecialchars($domain['container_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <span class="badge rounded-pill bg-body-secondary">Custom</span>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.user-feature-toggle').forEach(toggle => {
    toggle.addEventListener('change', async function() {
        const feature = this.getAttribute('data-feature');
        const state = this.checked;
        const email = '<?= addslashes($email) ?>';
        
        const formData = new FormData();
        formData.append('scope', 'user');
        formData.append('email', email);
        formData.append('feature', feature);
        formData.append('state', state);

        try {
            const response = await fetch('/api/admin/toggle_feature', {
                method: 'POST',
                body: formData
            });
            const res = await response.json();
            if (res.status === 'success') {
                TomNotify.show(`${feature} is now ${state ? 'ENABLED' : 'DISABLED'} for this user.`, 'Override Saved', 'success', 3000);
            } else {
                TomNotify.show(res.error || 'Failed to update', 'Error', 'error', 4000);
                this.checked = !state;
            }
        } catch(e) {
            TomNotify.show('Network error', 'Error', 'error', 4000);
            this.checked = !state;
        }
    });
});
</script>
