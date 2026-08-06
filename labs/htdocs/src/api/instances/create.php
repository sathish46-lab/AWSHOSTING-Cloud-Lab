<?php
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/AuditLog.class.php';
require_once __DIR__ . '/../../lib/core/CsrfProtection.class.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

CsrfProtection::require();

header('Content-Type: application/json');
$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername();
$email = $user->getEmail();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');

$name = trim($_POST['name'] ?? '');
$visibility = $_POST['visibility'] ?? 'private';
$type = $_POST['type'] ?? 'machine';
$template = trim($_POST['template'] ?? 'essentials');

if (empty($name)) {
    echo json_encode(['status' => 'error', 'error' => 'Name is required']);
    exit;
}

// Generate slug
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'));
if (empty($slug)) $slug = 'lab-' . time();

// Ensure unique slug
$existing = $db->instances->findOne(['slug' => $slug]);
if ($existing) {
    $slug .= '-' . rand(1000, 9999);
}

// Generate unique instance hash (email + slug + salt)
$instanceHash = md5($email . $slug . '8b51626f3a468904e8b6f83747f2fcf1');

$instance = [
    'user_id' => $userId,
    'username' => $username,
    'email' => $email,
    'name' => $name,
    'slug' => $slug,
    'instance_hash' => $instanceHash,
    'visibility' => $visibility,
    'type' => $type,
    'template' => $template,
    'status' => 'draft',
    'image' => 'ubuntu:24.04',
    'color' => 'rgba(0,0,0,0.5)',
    'icon' => 'bx-cube-alt',
    'created_by' => $userId,
    'updated_by' => $userId,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
];

$result = $db->instances->insertOne($instance);

if ($result->getInsertedCount() > 0) {
    AuditLog::log('create', 'instance', $instanceHash, [
        'name' => $name,
        'slug' => $slug,
        'type' => $type,
        'visibility' => $visibility,
        'template' => $template,
    ]);
    // Seed the instance's file store (base layer from lab-templates/<template>/)
    try {
        $newId = $result->getInsertedId();
        $instance['_id'] = $newId;
        InstanceFileStore::ensureBaseForInstance($instance);
    } catch (Exception $e) {
        error_log('Instance file seed failed: ' . $e->getMessage());
    }
    ob_start();
    ?>
    <div class="col" id="instance-<?= htmlspecialchars($slug) ?>">
        <a href="/instances/<?= htmlspecialchars($instanceHash) ?>" class="card h-100 blur border-0 shadow-lg rounded-4 overflow-hidden text-decoration-none group device-card">
            <div class="card-body p-4 d-flex flex-column justify-content-between h-100">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0" 
                                 style="width: 36px; height: 36px; background: <?= htmlspecialchars($instance['color']) ?>; border: 1px solid rgba(255, 255, 255, 0.15); box-shadow: 0 3px 8px rgba(0,0,0,0.15);">
                                <i class="bx <?= htmlspecialchars($instance['icon']) ?> text-white fs-5"></i>
                            </div>
                            <h5 class="fw-bold theme-text mb-0 group-hover-text-primary transition-all"><?= htmlspecialchars($name) ?></h5>
                        </div>
                    </div>
                    
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="badge bg-secondary bg-opacity-25 text-secondary rounded-pill px-2 fw-normal"><?= htmlspecialchars($type) ?></span>
                        <span class="badge bg-secondary bg-opacity-25 text-secondary rounded-pill px-2 fw-normal">draft</span>
                        <span class="badge <?php echo $visibility === 'public' ? 'bg-info bg-opacity-25 text-info' : 'bg-secondary bg-opacity-25 text-secondary'; ?> rounded-pill px-2 fw-normal"><?= htmlspecialchars($visibility) ?></span>
                    </div>
                    
                    <div class="d-flex align-items-center text-info font-monospace small mb-3">
                        <span class="text-success"><?= htmlspecialchars($slug) ?></span> - <?= htmlspecialchars($instance['image']) ?>
                    </div>
                </div>
                
                <div class="text-secondary small mt-2">just now</div>
            </div>
        </a>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['status' => 'success', 'html' => $html]);
} else {
    echo json_encode(['status' => 'error', 'error' => 'Database insert failed']);
}
