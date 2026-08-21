<?php
/**
 * MCP OAuth Consent Page
 * Renders the approval page for MCP client authorization
 */

$clientId = $_GET['client_id'] ?? '';
$redirectUri = $_GET['redirect_uri'] ?? '';
$scope = $_GET['scope'] ?? 'labs:*';
$state = $_GET['state'] ?? '';
$codeChallenge = $_GET['code_challenge'] ?? '';
$codeChallengeMethod = $_GET['code_challenge_method'] ?? 'S256';

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';
$client = MCPOAuth::getClient($clientId);
$clientName = $client ? $client['client_name'] : 'Unknown Client';

Session::$pageTitle = 'Authorize ' . htmlspecialchars($clientName);
Session::addMetaTag(['name' => 'robots', 'content' => 'noindex, nofollow']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(Session::$pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.0.0/dist/css/coreui.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f0f23 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .consent-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            backdrop-filter: blur(20px);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .app-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 28px;
            color: white;
        }
        h1 { font-size: 24px; font-weight: 600; color: #fff; margin-bottom: 8px; text-align: center; }
        .client-name { color: #667eea; font-weight: 500; }
        .description { color: rgba(255,255,255,0.6); font-size: 14px; text-align: center; margin-bottom: 32px; }

        .permissions-list {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .permission-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }
        .permission-item:last-child { border-bottom: none; }
        .permission-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(102, 126, 234, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 16px;
        }
        .permission-info { flex: 1; }
        .permission-title { color: #fff; font-weight: 500; font-size: 14px; }
        .permission-desc { color: rgba(255,255,255,0.5); font-size: 12px; margin-top: 2px; }

        .btn-group {
            display: flex;
            gap: 12px;
        }
        .btn-consent {
            flex: 1;
            padding: 14px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-allow {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-allow:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.4);
        }
        .btn-deny {
            background: rgba(255, 255, 255, 0.05);
            color: rgba(255,255,255,0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-deny:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .footer-note {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: rgba(255,255,255,0.4);
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .user-details { flex: 1; }
        .user-name { color: #fff; font-weight: 500; font-size: 14px; }
        .user-email { color: rgba(255,255,255,0.5); font-size: 12px; }
    </style>
</head>
<body>
    <div class="consent-card">
        <div class="app-icon">
            <i class='bx bx-terminal'></i>
        </div>

        <h1>Authorize <span class="client-name"><?= htmlspecialchars($clientName) ?></span></h1>
        <p class="description">This application is requesting access to your Tom Labs account. Review the permissions below.</p>

        <div class="user-info">
            <?php
            $user = Session::getUser();
            $avatar = Session::getAvatar();
            ?>
            <img src="<?= htmlspecialchars($avatar) ?>" class="user-avatar" alt="User avatar">
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($user->getFirstName() . ' ' . $user->getLastName() ?: $user->getUsername()) ?></div>
                <div class="user-email"><?= htmlspecialchars($user->getEmail()) ?></div>
            </div>
        </div>

        <div class="permissions-list">
            <div class="permission-item">
                <div class="permission-icon">
                    <i class='bx bx-cube'></i>
                </div>
                <div class="permission-info">
                    <div class="permission-title">Manage Labs</div>
                    <div class="permission-desc">Deploy, start, stop, restart, and terminate lab instances</div>
                </div>
            </div>
            <div class="permission-item">
                <div class="permission-icon">
                    <i class='bx bx-folder'></i>
                </div>
                <div class="permission-info">
                    <div class="permission-title">File System Access</div>
                    <div class="permission-desc">Read, write, edit, and manage files in your lab workspace</div>
                </div>
            </div>
            <div class="permission-item">
                <div class="permission-icon">
                    <i class='bx bx-terminal'></i>
                </div>
                <div class="permission-info">
                    <div class="permission-title">Command Execution</div>
                    <div class="permission-desc">Run shell commands and scripts inside your lab containers</div>
                </div>
            </div>
            <div class="permission-item">
                <div class="permission-icon">
                    <i class='bx bx-code'></i>
                </div>
                <div class="permission-info">
                    <div class="permission-title">Code IDE (code-server)</div>
                    <div class="permission-desc">Launch and manage VS Code in the browser</div>
                </div>
            </div>
            <div class="permission-item">
                <div class="permission-icon">
                    <i class='bx bx-network-chart'></i>
                </div>
                <div class="permission-info">
                    <div class="permission-title">Network & Services</div>
                    <div class="permission-desc">Manage networks, domains, databases, and service credentials</div>
                </div>
            </div>
        </div>

        <form method="POST" action="/mcp/consent">
            <input type="hidden" name="txn_id" value="<?= htmlspecialchars($txnIdForForm ?? '') ?>">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(Session::csrfToken()) ?>">

            <div class="btn-group">
                <button type="submit" name="action" value="allow" class="btn-consent btn-allow">
                    <i class='bx bx-check me-2'></i>Allow Access
                </button>
                <button type="submit" name="action" value="deny" class="btn-consent btn-deny">
                    <i class='bx bx-x me-2'></i>Deny
                </button>
            </div>
        </form>

        <p class="footer-note">
            You can revoke this access at any time from your <a href="/account/settings" style="color: #667eea;">Account Settings</a>.
        </p>
    </div>
</body>
</html>