<?php
/**
 * MCP Inspector — Activity Page
 * Route: /mcp/activity
 */
$baseUrl    = Session::get('mcp_baseUrl', '/');
$redirectUri = Session::get('mcp_redirectUri', $baseUrl . '/mcp');
$clientId   = Session::get('mcp_clientId', '');
$authUrl    = Session::get('mcp_authUrl', '');
$pkce       = Session::get('mcp_pkce', null);
$activeTab  = 'activity';

// Pre-render first 20 activity items from DB
$mcpActivityItems = [];
$mcpHasMore = false;
if (Session::getAuthStatus() === Constants::STATUS_LOGGEDIN) {
    require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';
    $user = Session::getUser();
    $db = MCPOAuth::getDb();
    $userId = $user->getUserId();

    $cursor = $db->mcp_activity->find(
        ['user_id' => $userId],
        ['sort' => ['created_at' => -1], 'limit' => 20]
    );

    foreach ($cursor as $a) {
        $clientName = null;
        $cid = $a['client_id'] ?? '';
        if (!empty($cid)) {
            $cDoc = $db->mcp_clients->findOne(['client_id' => $cid]);
            $clientName = $cDoc['client_name'] ?? null;
        }
        $mcpActivityItems[] = [
            'id' => (string)$a['_id'],
            'client_id' => $cid,
            'client_name' => $clientName,
            'username' => $a['username'] ?? '',
            'tool' => $a['tool'] ?? '',
            'status' => $a['status'] ?? 'ok',
            'duration_ms' => $a['duration_ms'] ?? 0,
            'error' => $a['error'] ?? null,
            'request' => $a['request'] ?? [],
            'response' => $a['response'] ?? [],
            'created_at' => isset($a['created_at']) && $a['created_at'] instanceof MongoDB\BSON\UTCDateTime
                ? $a['created_at']->toDateTime()->format('c')
                : '',
        ];
    }
    $totalActivity = $db->mcp_activity->countDocuments(['user_id' => $userId]);
    $mcpHasMore = count($mcpActivityItems) < $totalActivity;
}
?>
<?php include __DIR__ . '/../partials/_mcp_header.php'; ?>

<div class="mcp-page mcp-page--activity" data-mcp-ready="1">
    <div class="container-fluid px-4 mt-0 mcp-shell">
        <div class="mcp-tabs">

            <div class="mcp-pane active show">
                <div class="mcp-grid">
                    <section class="card blur mcp-panel mcp-panel--clients">
                        <div class="card-body mcp-panel__body">
                            <p class="mcp-eyebrow">Clients</p>
                            <div class="mcp-rail" id="mcp-rail" role="tablist" aria-label="Filter activity by client">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                    </section>

                    <section class="card blur mcp-panel mcp-panel--access">
                        <div class="card-body mcp-panel__body">
                            <div class="mcp-view__head">
                                <p class="mcp-eyebrow mb-0">Activity · last 30 days</p>
                                <span class="badge badge-soft-secondary mcp-tag mcp-live" id="mcp-live-tag" title="New calls appear here as they happen">
                                    <i class="mcp-live__dot" aria-hidden="true"></i>
                                    <span class="mcp-live__label">live</span>
                                </span>
                            </div>

                            <div class="mcp-view is-active" id="mcp-view-history" role="region" aria-label="MCP activity">
                                <div class="mcp-view__scroll" id="mcp-history-list">
                                    <?php if (empty($mcpActivityItems)): ?>
                                        <p class="mcp-blank" id="mcp-history-blank">Nothing in the last 30 days. Every tool call an MCP client makes with this account lands here — what it asked for, what came back, and whether it worked.</p>
                                    <?php else: ?>
                                        <p class="mcp-blank d-none" id="mcp-history-blank"></p>
                                        <?php foreach ($mcpActivityItems as $a):
                                            $status = $a['status'] === 'ok' ? 'ok' : ($a['status'] === 'refused' ? 'refused' : 'err');
                                            $statusClass = $status === 'ok' ? 'success' : ($status === 'refused' ? 'warning' : 'danger');
                                            $clientName = $a['client_name'] ?? $a['username'] ?? 'MCP';
                                            $target = $a['request']['arguments']['instance_id'] ?? $a['request']['arguments']['lab'] ?? $a['request']['arguments']['command'] ?? '';
                                            $ts = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;
                                            $ago = $ts ? ($ts > strtotime('-1 minute') ? 'just now' : ($ts > strtotime('-1 hour') ? round((time() - $ts) / 60) . 'm ago' : ($ts > strtotime('-1 day') ? round((time() - $ts) / 3600) . 'h ago' : round((time() - $ts) / 86400) . 'd ago'))) : 'never';
                                        ?>
                                        <div class="mcp-log" data-id="<?= htmlspecialchars($a['id']) ?>" data-tool="<?= htmlspecialchars($a['tool']) ?>" data-status="<?= htmlspecialchars($a['status']) ?>" data-client="<?= htmlspecialchars($clientName) ?>" data-target="<?= htmlspecialchars($target) ?>" data-request="<?= htmlspecialchars(json_encode($a['request'])) ?>" data-response="<?= htmlspecialchars(json_encode($a['error'] ? ['error' => $a['error']] : $a['response'])) ?>" data-created="<?= htmlspecialchars($a['created_at']) ?>" data-dur="<?= $a['duration_ms'] ?>">
                                            <div class="mcp-log__line">
                                                <code class="mcp-log__tool"><?= htmlspecialchars($a['tool']) ?></code>
                                                <?php if ($target): ?>
                                                    <span class="mcp-log__target"><?= htmlspecialchars($target) ?></span>
                                                <?php endif; ?>
                                                <span class="badge badge-soft-<?= $statusClass ?> mcp-tag"><?= htmlspecialchars($a['status']) ?></span>
                                            </div>
                                            <div class="mcp-log__meta"><?= htmlspecialchars($clientName) ?> · <?= $ago ?><?= $a['duration_ms'] ? ' · ' . $a['duration_ms'] . ' ms' : '' ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <div class="mcp-more <?= $mcpHasMore ? '' : 'd-none' ?>" id="mcp-scroll-sentinel">
                                        <span class="spinner-border spinner-border-sm text-secondary" role="status"></span>
                                    </div>
                                </div>
                            </div>

                            <p class="mcp-foot">Click any call to see exactly what was sent and what came back. Only you can read this — not other users, and not an admin. Deploys and stops made through MCP also appear on the lab's own Activity timeline, marked <em>via MCP</em>.</p>
                        </div>
                    </section>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="mcp-call-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content mcp-call-modal blur" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header" style="border-bottom: 2px solid rgba(var(--cui-primary-rgb), 0.1); background: linear-gradient(135deg, rgba(var(--cui-primary-rgb), 0.03) 0%, rgba(var(--cui-info-rgb), 0.03) 100%);">
                <div class="w-100">
                    <div class="mcp-call__head" id="mcp-call-modal-head">
                        <code class="mcp-call__tool" id="mcp-call-modal-tool"></code>
                        <span class="mcp-call__target" id="mcp-call-modal-target"></span>
                        <span class="badge badge-soft-success mcp-tag" id="mcp-call-modal-status">ok</span>
                    </div>
                    <div class="mcp-call__meta" id="mcp-call-modal-meta"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="line-height: 1.7;">
                <section class="mcp-call__panel">
                    <div class="mcp-call__panel-head">
                        <span class="mcp-eyebrow mb-0">Request</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary mcp-call__copy" data-copy-target="#mcp-call-modal-request">Copy</button>
                    </div>
                    <pre class="mcp-call__body"><code class="nohighlight" id="mcp-call-modal-request"></code></pre>
                </section>

                <section class="mcp-call__panel">
                    <div class="mcp-call__panel-head">
                        <span class="mcp-eyebrow mb-0">Response</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary mcp-call__copy" data-copy-target="#mcp-call-modal-response">Copy</button>
                    </div>
                    <pre class="mcp-call__body"><code class="nohighlight" id="mcp-call-modal-response"></code></pre>
                </section>

                <div class="mcp-call__foot" id="mcp-call-modal-foot"></div>
            </div>
        </div>
    </div>
</div>

<script>
window.MCP_CONFIG = {
    baseUrl: <?= json_encode($baseUrl) ?>,
    clientId: <?= json_encode($clientId) ?>,
    redirectUri: <?= json_encode($redirectUri) ?>,
    authUrl: <?= json_encode($authUrl) ?>,
    pkce: <?= $pkce === null ? 'null' : json_encode($pkce) ?>
};
</script>
<script src="<?= Session::cacheCDN('/assets/js/mcp-inspector.js') ?>"></script>
