<?php
// /src/template/partials/instances/manage/configuration.php
// Reads from nested config object with fallback to flat fields
$slug = $instance['slug'] ?? '';
$cfg = $instance['config'] ?? [];

// Helper: read from config.nested, then flat field, then default
function cfg($path, $flatField = null, $default = null) {
    global $cfg, $instance;
    // Try nested path like 'general.name'
    $val = $cfg;
    foreach (explode('.', $path) as $part) {
        if (!is_array($val) || !array_key_exists($part, $val)) { $val = null; break; }
        $val = $val[$part];
    }
    if ($val !== null && $val !== '') return $val;
    // Fallback to flat field
    if ($flatField && isset($instance[$flatField])) return $instance[$flatField];
    return $default;
}

$name        = cfg('general.name', 'name', '');
$description = cfg('general.description', 'description', '');
$stability   = cfg('general.stability', 'stability', 'alpha');
$cpu         = cfg('resources.cpu', 'cpu', '2');
$memory      = cfg('resources.memory', 'memory', '512m');
$portsRaw    = cfg('resources.ports', 'ports', []);
$network     = cfg('resources.network', 'network', 'Default (wg0)');
$sshEnabled  = cfg('users.ssh_enabled', 'ssh_enabled', false);
$runAsUser   = cfg('users.run_as_signed_in_user', 'run_as_signed_in_user', false);
$usersRaw    = cfg('users.list', 'users', []);
$homeEnabled = cfg('storage.home_mount_enabled', 'home_mount_enabled', false);
$homePath    = cfg('storage.home_mount_path', 'home_mount_path', '/home');
$bindRaw     = cfg('storage.bind_mounts', 'bind_mounts', []);

// Normalize ports
if ($portsRaw instanceof MongoDB\Model\BSONArray) {
    $ports = implode(' ', iterator_to_array($portsRaw));
} elseif (is_array($portsRaw)) {
    $ports = implode(' ', $portsRaw);
} else {
    $ports = (string)($portsRaw ?? '');
}

$users = ($usersRaw instanceof MongoDB\Model\BSONArray) ? iterator_to_array($usersRaw) : (is_array($usersRaw) ? $usersRaw : []);
$bindMounts = ($bindRaw instanceof MongoDB\Model\BSONArray) ? iterator_to_array($bindRaw) : (is_array($bindRaw) ? $bindRaw : []);
?>
<div class="card blur border-0 rounded-4 p-3 p-md-4">
    <div class="d-flex flex-column gap-4">
        <!-- Top row: General + Resources side by side -->
        <div class="row g-4">
            <!-- General -->
            <div class="col-md-6">
                <div class="card liquid-rim border-0 rounded-4 p-4 h-100">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class='bx bx-cog text-secondary'></i>
                        <span class="fw-bold theme-text">General</span>
                    </div>
                    
                    <div class="mb-3 d-flex align-items-center">
                        <div style="width: 100px;" class="text-secondary fw-bold small flex-shrink-0">Name</div>
                        <input type="text" class="form-control config-input flex-grow-1" data-field="name" value="<?= htmlspecialchars($name) ?>">
                    </div>
                    <div class="mb-3 d-flex align-items-start">
                        <div style="width: 100px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Slug</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <input type="text" class="form-control config-input" value="<?= htmlspecialchars($slug) ?>" style="max-width: 200px;" disabled>
                                <span class="bg-secondary bg-opacity-25 text-secondary rounded-3 px-2 py-1 small"><?= strlen($slug) ?>/15</span>
                            </div>
                            <div class="text-secondary opacity-75" style="font-size: 0.7rem; line-height: 1.5;">
                                Friendly identifier — becomes the deployed lab id. <strong>Locked after first deploy.</strong> Internal id: <span class="text-info"><?= htmlspecialchars(substr($instance['_id'] ?? 'N/A', 0, 12)) ?></span>.
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 d-flex align-items-center">
                        <div style="width: 100px;" class="text-secondary fw-bold small flex-shrink-0">Description</div>
                        <input type="text" class="form-control config-input flex-grow-1" data-field="description" value="<?= htmlspecialchars($description) ?>">
                    </div>
                    <div class="mb-0 d-flex align-items-start">
                        <div style="width: 100px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Stability</div>
                        <div class="flex-grow-1">
                            <select class="form-select config-input w-100" data-field="stability">
                                <option value="alpha" <?= $stability === 'alpha' ? 'selected' : '' ?>>alpha</option>
                                <option value="beta" <?= $stability === 'beta' ? 'selected' : '' ?>>beta</option>
                                <option value="stable" <?= $stability === 'stable' ? 'selected' : '' ?>>stable</option>
                            </select>
                            <div class="text-secondary opacity-75 mt-1" style="font-size: 0.7rem;">
                                alpha = private. Move to <strong>beta</strong> or <strong>stable</strong> to share.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resources -->
            <div class="col-md-6">
                <div class="card liquid-rim border-0 rounded-4 p-4 h-100">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class='bx bx-server text-secondary'></i>
                        <span class="fw-bold theme-text">Resources</span>
                    </div>
                    
                    <div class="mb-3 d-flex align-items-center">
                        <div style="width: 100px;" class="text-secondary fw-bold small flex-shrink-0">CPU</div>
                        <input type="text" class="form-control config-input" data-field="cpu" value="<?= htmlspecialchars($cpu) ?>" style="max-width: 150px;">
                    </div>
                    <div class="mb-3 d-flex align-items-start">
                        <div style="width: 100px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Memory</div>
                        <div class="flex-grow-1">
                            <input type="text" class="form-control config-input mb-1" data-field="memory" value="<?= htmlspecialchars($memory) ?>" style="max-width: 200px;">
                            <div class="text-secondary opacity-75" style="font-size: 0.7rem;">e.g. <span class="text-info">512m, 2g</span></div>
                        </div>
                    </div>
                    <div class="mb-3 d-flex align-items-start">
                        <div style="width: 100px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Ports</div>
                        <div class="flex-grow-1">
                            <input type="text" class="form-control config-input mb-1" data-field="ports" value="<?= htmlspecialchars($ports) ?>" style="max-width: 200px;">
                            <div class="text-secondary opacity-75" style="font-size: 0.7rem;">Space-separated, e.g. <span class="text-info">22 8080</span></div>
                        </div>
                    </div>
                    <div class="mb-0 d-flex align-items-start">
                        <div style="width: 100px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Network</div>
                        <div class="flex-grow-1">
                            <select class="form-select config-input mb-1" data-field="network" style="max-width: 200px;">
                                <option value="Default (wg0)" <?= $network === 'Default (wg0)' ? 'selected' : '' ?>>Default (wg0)</option>
                                <option value="Internal only" <?= $network === 'Internal only' ? 'selected' : '' ?>>Internal only</option>
                            </select>
                            <div class="text-secondary opacity-75" style="font-size: 0.7rem;">Default joins shared VPN. Internal only = no WireGuard.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom: User Management full width -->
        <div class="card liquid-rim border-0 rounded-4 p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class='bx bx-group text-secondary'></i>
                <span class="fw-bold theme-text">User Management</span>
            </div>
            
            <!-- SSH -->
            <div class="form-check form-switch mb-3 d-flex align-items-center gap-3 ps-0">
                <div style="width: 120px;" class="text-secondary fw-bold small m-0 flex-shrink-0">SSH</div>
                <input class="form-check-input m-0 fs-5" type="checkbox" role="switch" data-field="ssh_enabled" <?= !empty($sshEnabled) ? 'checked' : '' ?> style=" border-color: #ff4b2b;">
                <label class="form-check-label text-white small m-0">Enable SSH access</label>
            </div>

            <!-- Signed-in user -->
            <div class="mb-3 d-flex align-items-start gap-3 ps-0">
                <div style="width: 120px;" class="text-secondary fw-bold small pt-1 flex-shrink-0">Signed-in user</div>
                <div class="flex-grow-1">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" data-field="run_as_signed_in_user" <?= !empty($runAsUser) ? 'checked' : '' ?> style=" border-color: #ff4b2b;">
                        <label class="form-check-label text-white small">Run as the signed-in user (shared home)</label>
                    </div>
                    <div class="text-secondary opacity-75" style="font-size: 0.7rem;">Provisions the deployer's platform username at uid 1000 on deploy, with their <strong>shared home</strong> mounted. Coexists with any custom users below.</div>
                </div>
            </div>

            <!-- Home mount -->
            <div class="mb-3 d-flex align-items-start gap-3 ps-0">
                <div style="width: 120px;" class="text-secondary fw-bold small pt-1 flex-shrink-0">Home mount</div>
                <div class="flex-grow-1">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" data-field="home_mount_enabled" <?= !empty($homeEnabled) ? 'checked' : '' ?> style="border-color: #ff4b2b;">
                        <label class="form-check-label text-white small">Mount a persistent home volume</label>
                    </div>
                    <input type="text" class="form-control config-input mb-1" data-field="home_mount_path" value="<?= htmlspecialchars($homePath) ?>" style="max-width: 300px;">
                    <div class="text-secondary opacity-75" style="font-size: 0.7rem;">In-container path where the per-user volume mounts. Off = ephemeral.</div>
                </div>
            </div>

            <!-- Bind mounts -->
            <div class="mb-3 d-flex align-items-start gap-3 ps-0">
                <div style="width: 120px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Bind mounts</div>
                <div class="flex-grow-1" id="configBindMountsList">
                    <?php if (empty($bindMounts)): ?>
                    <div class="text-secondary small opacity-50 mb-2">No bind mounts configured.</div>
                    <?php else: foreach ($bindMounts as $idx => $mount): ?>
                    <div class="d-flex align-items-center gap-2 mb-2" data-mount-index="<?= $idx ?>">
                        <input type="text" class="form-control form-control-sm config-input" value="<?= htmlspecialchars((string)$mount) ?>" data-field="bind_mounts.<?= $idx ?>" style="font-size: 0.8rem;">
                        <i class='bx bx-trash text-danger pointer small remove-mount' data-mount-index="<?= $idx ?>"></i>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="ps-0 mb-3">
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill fw-bold border-opacity-50 px-3" id="addBindMount"><i class='bx bx-plus'></i> Add bind mount</button>
                <div class="text-secondary opacity-75 mt-2" style="font-size: 0.7rem;">Format: <span class="text-info">src:dst</span>. Placeholders: <span class="text-info">{labstorage}</span>, <span class="text-info">{lab_id}</span>, <span class="text-info">{username}</span>. e.g. <span class="text-info">{labstorage}/home:/home</span>.</div>
            </div>

            <hr class="border-secondary border-opacity-25 my-3">

            <!-- Users -->
            <div class="mb-3 d-flex align-items-start gap-3 ps-0">
                <div style="width: 120px;" class="text-secondary fw-bold small pt-2 flex-shrink-0">Users</div>
                <div class="flex-grow-1" id="configUsersList">
                    <?php if (empty($users)): ?>
                    <div class="text-secondary small opacity-50">No users configured.</div>
                    <?php else: foreach ($users as $idx => $u): ?>
                    <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded-3 border border-secondary border-opacity-25 bg-black bg-opacity-50" data-user-index="<?= $idx ?>">
                        <div class="bg-secondary bg-opacity-25 p-1 rounded d-flex"><i class='bx bx-user text-secondary'></i></div>
                        <input type="text" class="form-control form-control-sm config-input bg-transparent border-0 text-white fw-bold" style="max-width:120px;" value="<?= htmlspecialchars($u['username'] ?? '') ?>" data-field="users.<?= $idx ?>.username">
                        <select class="form-select form-select-sm config-input bg-transparent border-0 text-secondary" style="max-width:120px;" data-field="users.<?= $idx ?>.shell">
                            <option value="/bin/bash" <?= ($u['shell'] ?? '/bin/bash') === '/bin/bash' ? 'selected' : '' ?>>/bin/bash</option>
                            <option value="/bin/sh" <?= ($u['shell'] ?? '') === '/bin/sh' ? 'selected' : '' ?>>/bin/sh</option>
                            <option value="/bin/zsh" <?= ($u['shell'] ?? '') === '/bin/zsh' ? 'selected' : '' ?>>/bin/zsh</option>
                        </select>
                        <div class="ms-auto d-flex align-items-center gap-3 pe-2">
                            <div class="form-check form-switch m-0 d-flex align-items-center gap-2">
                                <input class="form-check-input m-0" type="checkbox" role="switch" <?= !empty($u['sudo']) ? 'checked' : '' ?> data-field="users.<?= $idx ?>.sudo">
                                <label class="form-check-label text-secondary small">sudo</label>
                            </div>
                            <i class='bx bx-trash text-danger pointer small remove-user' data-user-index="<?= $idx ?>"></i>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="ps-0 pb-2">
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill fw-bold border-opacity-50 px-3" id="addConfigUser"><i class='bx bx-plus'></i> Add user</button>
                <div class="text-secondary opacity-75 mt-2" style="font-size: 0.7rem;">Each becomes a <span class="text-info">useradd</span> line in the Dockerfile (the app-provisioned uid-1000 user is managed separately, do not add it here).</div>
            </div>

            <div class="mt-3 text-end">
                <button class="btn instance-action-btn instance-action-success rounded-pill px-3 py-2 fw-bold" id="saveConfigBtn" title="Save configuration">
                    <i class='bx bx-save' style="font-size: 1.25rem;"></i>
                </button>
            </div>
        </div>
    </div>
</div>
