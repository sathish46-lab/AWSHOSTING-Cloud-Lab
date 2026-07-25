<?php
require_once __DIR__ . '/../../load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$trashedInstances = iterator_to_array($db->instance_trash->find(['user_id' => $userId]));

function trash_relative_time($dt) {
    if (!$dt instanceof MongoDB\BSON\UTCDateTime) return 'recently';
    $ts = $dt->toDateTime()->getTimestamp();
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}

$avatarMap = [
    'essentials' => 'essentials_avatar.png',
    'minio'      => 'minio_avatar.png',
    'docker_lab' => 'docker_avatar.png',
    'docker'     => 'docker_avatar.png',
    'zephyr'     => 'zephyr_avatar.png',
    'kali'       => 'kali-background.png',
    'n8n'        => 'essentials_avatar.png',
];
?>
<?php if (empty($trashedInstances)): ?>
<div class="text-center py-5" data-templates-count="<?= count(iterator_to_array($db->instances->find(['user_id' => $userId]))) ?>" data-trash-count="0">
    <div class="text-secondary opacity-50 mb-2"><i class='bx bx-trash fs-1'></i></div>
    <div class="text-secondary small">Trash is empty.</div>
</div>
<?php else: ?>
<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-3 g-3" id="trashedGrid" data-templates-count="<?= count(iterator_to_array($db->instances->find(['user_id' => $userId]))) ?>" data-trash-count="<?= count($trashedInstances) ?>">
    <?php foreach ($trashedInstances as $item): ?>
    <?php
        $tSlug = $item['slug'] ?? 'unknown';
        $tName = $item['name'] ?? 'Unnamed Lab';
        $tType = $item['type'] ?? 'machine';
        $tStatus = $item['status'] ?? 'draft';
        $tVisibility = $item['visibility'] ?? 'private';
        $tBgColor = $item['color'] ?? 'rgba(0,0,0,0.5)';
        $tTplKey = $item['template'] ?? $tType;
        $tAvatarFile = $avatarMap[$tTplKey] ?? 'essentials_avatar.png';
        $tCover = Session::cdn3('labassets/avatar/' . $tAvatarFile);
        $tTrashedAt = trash_relative_time($item['trashed_at'] ?? null);
    ?>
    <div class="col card-entrance" id="trash-card-<?= htmlspecialchars($tSlug) ?>">
        <div class="card border-0 shadow-sm instance-template-card" style="overflow: visible;">
            <div style="border-radius: 1rem; overflow: hidden; position: relative; min-height: 200px;">
                <div class="instance-template-card-bg" style="background-image: url('<?= htmlspecialchars($tCover) ?>'), linear-gradient(135deg, <?= htmlspecialchars($tBgColor) ?> 0%, rgba(0,0,0,0.35) 100%);"></div>
                <div class="instance-template-card-overlay"></div>

                <div class="position-relative d-flex flex-column justify-content-end p-3 h-100" style="z-index: 2;">
                    <div class="d-flex align-items-center justify-content-between mb-5">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge instance-badge-tag badge-status-trashed">trashed</span>
                            <span class="badge instance-badge-tag <?= $tVisibility === 'public' ? 'badge-vis-public' : 'badge-vis-private' ?>"><?= htmlspecialchars($tVisibility) ?></span>
                            <span class="badge instance-badge-tag badge-type-<?= htmlspecialchars($tType) ?>"><?= htmlspecialchars($tType) ?></span>
                            <span class="badge instance-badge-tag badge-status-<?= htmlspecialchars($tStatus) ?>"><?= htmlspecialchars($tStatus) ?></span>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-link text-white p-0 border-0 bg-transparent" data-coreui-toggle="dropdown" aria-expanded="false" style="text-shadow: 0 1px 3px rgba(0,0,0,0.5);">
                                <i class='bx bx-dots-vertical-rounded fs-5'></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end border-0">
                                <li><a class="dropdown-item rounded-3 mb-1 px-2 py-1 text-success" href="javascript:void(0)" onclick="restoreInstance('<?= htmlspecialchars($tSlug) ?>')"><i class='bx bx-reset me-2'></i>Restore</a></li>
                                <li><hr class="dropdown-divider border-secondary border-opacity-25 my-1"></li>
                                <li><a class="dropdown-item text-danger rounded-3 px-2 py-1" href="javascript:void(0)" onclick="permanentDelete('<?= htmlspecialchars($tSlug) ?>')"><i class='bx bx-trash me-2'></i>Permanent Delete</a></li>
                            </ul>
                        </div>
                    </div>

                    <h6 class="fw-bold theme-text mb-0">
                        <?= htmlspecialchars($tName) ?><?= !empty($item['forked_from']) ? ' (fork)' : '' ?>
                    </h6>

                    <div class="d-flex align-items-center justify-content-between gap-2 small mb-1 mt-3">
                        <div class="d-flex align-items-center gap-2">
                            <span><?= htmlspecialchars($item['image'] ?? 'ubuntu:24.04') ?></span>
                            <span><?= htmlspecialchars($tTplKey) ?></span>
                        </div>
                        <span class="text-nowrap">trashed <?= htmlspecialchars($tTrashedAt) ?></span>
                    </div>

                    <div class="d-flex gap-2">
                        <a href="javascript:void(0)" onclick="restoreInstance('<?= htmlspecialchars($tSlug) ?>')" class="btn instance-action-btn btn-sm rounded-pill px-2 fw-bold flex-fill text-center">
                            <i class='bx bx-reset'></i> Restore
                        </a>
                        <a href="javascript:void(0)" onclick="permanentDelete('<?= htmlspecialchars($tSlug) ?>')" class="btn instance-action-btn instance-action-primary btn-sm rounded-pill px-2 fw-bold flex-fill text-center">
                            <i class='bx bx-trash'></i> Delete
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
