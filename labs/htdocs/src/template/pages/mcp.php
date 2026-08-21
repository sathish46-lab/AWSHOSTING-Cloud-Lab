<?php
/**
 * MCP Inspector — Setup Page
 * Route: /mcp
 */
$baseUrl    = Session::get('mcp_baseUrl', '/');
$redirectUri = Session::get('mcp_redirectUri', $baseUrl . '/mcp');
$clientId   = Session::get('mcp_clientId', '');
$authUrl    = Session::get('mcp_authUrl', '');
$pkce       = Session::get('mcp_pkce', null);
$activeTab  = 'setup';
?>
<?php include __DIR__ . '/../partials/_mcp_header.php'; ?>

<div class="mcp-page" data-mcp-ready="1">
    <div class="container-fluid px-4 mt-0 mcp-shell">
        <div class="mcp-tabs">

            <div class="mcp-pane active show">
                <div class="row g-3 mcp-setup-row">
                    <div class="col-lg-8">
                        <section class="card blur mcp-panel mcp-panel--connect h-100">
                            <div class="card-body mcp-panel__body">
                                <p class="mcp-eyebrow">Your client</p>
                                <ul class="nav nav-pills nav-pills-glass mcp-clients" role="tablist" aria-label="MCP client">
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client active" id="mcp-tab-claude-code" data-client="claude-code" aria-selected="true" aria-controls="mcp-panel-claude-code">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-terminal"></use></svg>
                                            Claude Code
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-claude-desktop" data-client="claude-desktop" aria-selected="false" aria-controls="mcp-panel-claude-desktop">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-screen-desktop"></use></svg>
                                            Claude Desktop
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-codex" data-client="codex" aria-selected="false" aria-controls="mcp-panel-codex">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-code"></use></svg>
                                            Codex
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-gemini" data-client="gemini" aria-selected="false" aria-controls="mcp-panel-gemini">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-terminal"></use></svg>
                                            Gemini CLI
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-antigravity" data-client="antigravity" aria-selected="false" aria-controls="mcp-panel-antigravity">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-star"></use></svg>
                                            Antigravity
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-cursor" data-client="cursor" aria-selected="false" aria-controls="mcp-panel-cursor">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-cursor"></use></svg>
                                            Cursor
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-vscode" data-client="vscode" aria-selected="false" aria-controls="mcp-panel-vscode">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-code"></use></svg>
                                            VS Code
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button type="button" role="tab" class="nav-link mcp-client" id="mcp-tab-opencode" data-client="opencode" aria-selected="false" aria-controls="mcp-panel-opencode">
                                            <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-terminal"></use></svg>
                                            OpenCode
                                        </button>
                                    </li>
                                </ul>

                                <div class="mcp-client-panel is-active" id="mcp-panel-claude-code" role="tabpanel" aria-labelledby="mcp-tab-claude-code">
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Run this in your terminal:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--shell">
                                        <span class="mcp-cmd__prompt" aria-hidden="true">$</span>
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-claude-code">claude mcp add --transport http labs https://<?= $_SERVER['HTTP_HOST'] ?>/mcp</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-claude-code" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Type <code>/mcp</code>, pick <strong>labs</strong>, then choose <strong>Authenticate</strong>.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-claude-desktop" role="tabpanel" aria-labelledby="mcp-tab-claude-desktop" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Settings → Connectors → Add custom connector, then paste:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--url">
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-claude-desktop">https://<?= $_SERVER['HTTP_HOST'] ?>/mcp</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-claude-desktop" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Press <strong>Connect</strong> on the <strong>labs</strong> connector.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-codex" role="tabpanel" aria-labelledby="mcp-tab-codex" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Run this in your terminal:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--shell">
                                        <span class="mcp-cmd__prompt" aria-hidden="true">$</span>
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-codex">codex mcp add labs --url https://<?= $_SERVER['HTTP_HOST'] ?>/mcp</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-codex" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Run <code>codex mcp login labs</code>.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-gemini" role="tabpanel" aria-labelledby="mcp-tab-gemini" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Run this in your terminal:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--shell">
                                        <span class="mcp-cmd__prompt" aria-hidden="true">$</span>
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-gemini">gemini mcp add --transport http labs https://<?= $_SERVER['HTTP_HOST'] ?>/mcp</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-gemini" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Type <code>/mcp auth labs</code>.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-antigravity" role="tabpanel" aria-labelledby="mcp-tab-antigravity" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Settings → Customizations → Open MCP Config, then add to <code>~/.gemini/config/mcp_config.json</code>:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--config">
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-antigravity">{
  "mcpServers": {
    "labs": {
      "serverUrl": "https://<?= $_SERVER['HTTP_HOST'] ?>/mcp"
    }
  }
}</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-antigravity" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Reload the server list, then press <strong>Login</strong> on <strong>labs</strong>.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-cursor" role="tabpanel" aria-labelledby="mcp-tab-cursor" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Add to <code>~/.cursor/mcp.json</code>:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--config">
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-cursor">{
  "mcpServers": {
    "labs": {
      "url": "https://<?= $_SERVER['HTTP_HOST'] ?>/mcp"
    }
  }
}</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-cursor" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Settings → MCP shows <strong>labs</strong> as <em>Needs login</em> — press it.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-vscode" role="tabpanel" aria-labelledby="mcp-tab-vscode" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Run this in your terminal:</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--shell">
                                        <span class="mcp-cmd__prompt" aria-hidden="true">$</span>
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-vscode">code --add-mcp '{"name":"labs","type":"http","url":"https://<?= $_SERVER['HTTP_HOST'] ?>/mcp"}'</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-vscode" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Open the MCP panel in Copilot Chat and press <strong>Sign in</strong> on <strong>labs</strong>.</p>
                                </div>

                                <div class="mcp-client-panel" id="mcp-panel-opencode" role="tabpanel" aria-labelledby="mcp-tab-opencode" hidden>
                                    <p class="mcp-step">1 · Add the server</p>
                                    <p class="mcp-note">Add to <code>~/.config/opencode/opencode.json</code> (or <code>opencode.json</code> in your project):</p>
                                    <div class="liquid-rim mcp-cmd mcp-cmd--config">
                                        <pre class="mcp-cmd__text"><code class="nohighlight" id="mcp-cmd-opencode">{
  "mcp": {
    "labs": {
      "type": "remote",
      "url": "https://<?= $_SERVER['HTTP_HOST'] ?>/mcp",
      "enabled": true
    }
  }
}</code></pre>
                                        <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-target="#mcp-cmd-opencode" aria-label="Copy to clipboard" title="Copy">
                                            <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                        </button>
                                    </div>
                                    <p class="mcp-step">2 · Sign in</p>
                                    <p class="mcp-note mcp-note--login">Restart opencode, then run <code>opencode mcp auth labs</code>. A browser opens for you to approve.</p>
                                </div>

                                <div class="mcp-signin">
                                    <p class="mb-2">A browser opens and signs you in through your account — the same account you are using right now. You will be asked to approve the client by name. <strong>Approve only clients you started yourself.</strong></p>
                                    <p class="mb-0 mcp-signin__fine">Nothing to copy, paste or store. Your client never sees a credential, and this page never holds your MCP token.</p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="col-lg-4">
                        <aside class="card blur mcp-panel mcp-side h-100" id="mcp-connected-card" aria-label="Connected clients">
                            <div class="card-body">
                                <div class="mcp-side__head d-flex align-items-center gap-2 mb-3">
                                    <span class="mcp-status-dot mcp-status-ok" title="Server reachable" role="img" aria-label="Server reachable"></span>
                                    <span class="mcp-side__title flex-grow-1">Connected clients</span>
                                    <span class="mcp-live-pill" title="Signed in and active in the last few minutes">
                                        <span class="mcp-live-dot"></span>
                                        <span id="mcp-live-count">0</span>
                                    </span>
                                </div>

                                <div class="mcp-side__url liquid-rim d-flex align-items-center gap-2 mb-3">
                                    <code class="flex-grow-1" id="mcp-server-url">https://<?= $_SERVER['HTTP_HOST'] ?>/mcp</code>
                                    <button type="button" class="btn btn-sm btn-outline-primary mcp-copy clipboard" data-clipboard-text="https://<?= $_SERVER['HTTP_HOST'] ?>/mcp" aria-label="Copy the server URL" title="Copy the server URL">
                                        <svg class="mcp-copy__icon" aria-hidden="true"><use xlink:href="/assets/icons/free.svg#cil-copy"></use></svg>
                                    </button>
                                </div>

                                <ul class="mcp-side__list list-unstyled mb-0" id="mcp-connected-list" data-empty-text="No clients signed in yet. Follow the steps to connect one — it appears here the moment it authenticates.">
                                    <li class="mcp-side__empty text-body-secondary" id="mcp-connected-empty">No clients signed in yet. Follow the steps to connect one — it appears here the moment it authenticates.</li>
                                </ul>

                                <a class="mcp-side__manage acct-settings-open d-inline-flex align-items-center mt-3" data-settings-tab="security" role="button" tabindex="0">
                                    <svg class="icon icon-sm me-1"><use xlink:href="/assets/icons/free.svg#cil-shield-alt"></use></svg>
                                    Manage in Account security
                                </a>
                            </div>
                        </aside>
                    </div>
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
