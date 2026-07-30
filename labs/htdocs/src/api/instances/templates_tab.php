<?php
require_once __DIR__ . '/../../load.php';

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$dbInstances = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$instances = iterator_to_array($dbInstances->instances->find(['user_id' => $userId]));

function templates_relative_time($dt) {
    if (!$dt instanceof MongoDB\BSON\UTCDateTime) return 'recently';
    $ts = $dt->toDateTime()->getTimestamp();
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}

function templates_fork_source($dbInstances, $forkedFrom) {
    if (empty($forkedFrom)) return '';
    try {
        $oid = new MongoDB\BSON\ObjectId($forkedFrom);
    } catch (Exception $e) {
        return '';
    }
    $src = $dbInstances->instances->findOne(['_id' => $oid]);
    if (!$src) return '';
    $name = $src['name'] ?? '';
    if (empty($name)) {
        $name = $src['template'] ?? ($src['instance_hash'] ?? 'source');
    }
    return $name;
}
?>
<?php if (empty($instances)): ?>
<div class="text-center py-5" data-templates-count="0" data-trash-count="<?= count(iterator_to_array($dbInstances->instance_trash->find(['user_id' => $userId]))) ?>">
    <div class="text-secondary opacity-50 mb-2"><i class='bx bx-layer fs-1'></i></div>
    <div class="text-secondary small">No templates yet. Create your first lab template!</div>
</div>
<?php else: ?>
<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-3 g-3" id="templatesGrid" data-templates-count="<?= count($instances) ?>" data-trash-count="<?= count(iterator_to_array($dbInstances->instance_trash->find(['user_id' => $userId]))) ?>">
    <?php foreach ($instances as $instance): ?>
    <?php
        $slug = $instance['slug'] ?? 'unknown';
        $instanceHash = $instance['instance_hash'] ?? md5((string)$instance['_id'] . '8b51626f3a468904e8b6f83747f2fcf1');
        $name = $instance['name'] ?? 'Unnamed Lab';
        $type = $instance['type'] ?? 'machine';
        $status = $instance['status'] ?? 'draft';
        $visibility = $instance['visibility'] ?? 'private';
        $image = $instance['image'] ?? 'ubuntu:24.04';
        $tplKey = $instance['template'] ?? $type;
        $iconMap = [
            'essentials'   => 'bxl-tux',
            'minio'        => 'bx-cube',
            'docker_lab'   => 'bxl-docker',
            'docker'       => 'bxl-docker',
            'n8n'          => 'bx-git-repo-forked',
            'zephyr'       => 'bx-chip',
            'kali'         => 'bx-terminal',
        ];
        $cardIcon = $iconMap[$tplKey] ?? 'bx-cube';
        $forked_from = !empty($instance['forked_from']);
        $forkSource = templates_fork_source($dbInstances, $instance['forked_from'] ?? null);
        $updatedLabel = templates_relative_time($instance['updated_at'] ?? null);
    ?>
    <?php include __DIR__ . '/../../template/partials/_instance_card.php'; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>
