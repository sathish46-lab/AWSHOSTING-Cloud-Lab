<?php
/**
 * MCP Inspector — Shared Header (banner + tabs)
 * Used by both mcp.php and mcp-activity.php
 */
$activeTab = $activeTab ?? 'setup';

// Pre-render counts from DB (no JS fetch needed)
$mcpToolCount = 0;
$mcpCallCount = 0;
if (Session::getAuthStatus() === Constants::STATUS_LOGGEDIN) {
    require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';
    $user = Session::getUser();
    $db = MCPOAuth::getDb();

    // Count active MCP client tabs (Setup badge)
    $mcpToolCount = 8; // Claude Code, Claude Desktop, Codex, Gemini, Antigravity, Cursor, VS Code, OpenCode

    // Count total tool calls (Activity badge)
    $mcpCallCount = $db->mcp_activity->countDocuments(['user_id' => $user->getUserId()]);
}
?>
<div class="blur banner mb-3 rounded-0 border-bottom border-secondary border-opacity-10">
    <div class="card-body p-0" style="margin-left: 1rem; margin-right: 1rem;">
        <div class="container-fluid pt-3 pb-1">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3 class="fw-bold mb-0 ls-tight lab-header-title">MCP</h3>
                    <p class="small lab-header-desc mb-0">
                        Connect your editor to your labs, then watch what your agent does with
                        them — every call, live.
                    </p>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs lab-nav-tabs border-0" role="tablist" aria-label="MCP">
                <li class="nav-item" role="presentation">
                    <a href="/mcp" class="nav-link <?= $activeTab === 'setup' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'setup' ? 'true' : 'false' ?>">
                        <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-terminal"></use></svg>
                        Setup
                        <span class="badge badge-soft-secondary ms-1" id="mcp-tool-count"><?= $mcpToolCount ?></span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a href="/mcp/activity" class="nav-link <?= $activeTab === 'activity' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'activity' ? 'true' : 'false' ?>">
                        <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-list"></use></svg>
                        Activity
                        <span class="badge badge-soft-secondary ms-1" id="mcp-call-count"><?= $mcpCallCount ?></span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
