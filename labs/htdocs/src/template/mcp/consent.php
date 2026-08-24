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

$user = Session::getUser();
$avatar = Session::getAvatar();
$displayName = $user ? ($user->getFirstName() . ' ' . $user->getLastName() ?: $user->getUsername()) : 'User';
$userEmail = $user ? $user->getEmail() : '';

Session::$pageTitle = 'Approve Access - Tom Labs';
Session::addMetaTag(['name' => 'robots', 'content' => 'noindex, nofollow']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(Session::$pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1a1a1a;
            color: #e5e5e5;
            overflow: hidden;
            background-image: radial-gradient(ellipse at 50% 0%, rgba(255,152,0,.12), transparent 60%),
                              radial-gradient(ellipse at 80% 100%, rgba(255,87,34,.08), transparent 50%);
        }
        .card {
            width: 100%;
            max-width: 460px;
            padding: 32px;
            background: rgba(40, 40, 40, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(16px);
        }
        .brand {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 12px;
        }
        .desc {
            font-size: 14px;
            color: #999;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .desc strong { color: #fff; font-weight: 600; }

        .user-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        .user-name { font-weight: 600; font-size: 14px; color: #fff; }
        .user-email { font-size: 12px; color: #888; }

        .callback-box {
            border: 1px solid rgba(255, 152, 0, 0.3);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
            background: rgba(255, 152, 0, 0.04);
        }
        .callback-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 6px;
        }
        .callback-url {
            font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
            font-size: 13px;
            color: #fff;
            word-break: break-all;
        }

        .warning {
            font-size: 12px;
            color: #777;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .details-toggle {
            font-size: 13px;
            color: #ff9800;
            cursor: pointer;
            border: none;
            background: none;
            font-family: inherit;
            padding: 0;
            margin-bottom: 20px;
            display: inline-block;
        }
        .details-toggle:hover { color: #ffb74d; }

        .details-content {
            display: none;
            margin-bottom: 20px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }
        .details-content.show { display: block; }
        .detail-label {
            font-size: 12px;
            color: #888;
            font-weight: 500;
        }
        .detail-value {
            font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
            font-size: 14px;
            color: #fff;
            margin-top: 4px;
            word-break: break-all;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 8px;
        }
        .btn {
            flex: 1;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
        }
        .btn-allow {
            background: #ff9800;
            color: #000;
        }
        .btn-allow:hover { background: #ffb74d; }
        .btn-deny {
            background: rgba(255, 255, 255, 0.08);
            color: #999;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-deny:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ccc;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Tom Labs</div>

        <h1>Approve access</h1>
        <p class="desc"><strong><?= htmlspecialchars($clientName) ?></strong> is asking to use your labs. If you allow it, it acts as you, with exactly the permissions your account already has.</p>

        <div class="user-row">
            <img src="<?= htmlspecialchars($avatar) ?>" class="user-avatar" alt="Avatar">
            <div>
                <div class="user-name"><?= htmlspecialchars($displayName) ?></div>
                <div class="user-email"><?= htmlspecialchars($userEmail) ?></div>
            </div>
        </div>

        <div class="callback-box">
            <div class="callback-label">Your credentials go to</div>
            <div class="callback-url"><?= htmlspecialchars($redirectUri) ?></div>
        </div>

        <p class="warning">Allow this only if you started <?= htmlspecialchars($clientName) ?> yourself and you recognise the address above. Nobody from Tom Labs will ever ask you to approve this screen.</p>

        <button class="details-toggle" onclick="var el=document.getElementById('details');el.classList.toggle('show');this.textContent=el.classList.contains('show')?'− Connection details':'+ Connection details'">+ Connection details</button>
        <div class="details-content" id="details">
            <div class="detail-label">Client ID</div>
            <div class="detail-value"><?= htmlspecialchars($clientId) ?></div>
            <div class="detail-label" style="margin-top:16px">Scopes requested</div>
            <div class="detail-value"><?php
                $scopeMap = [
                    'labs:*' => 'labs instances files domains deployment network credentials',
                ];
                echo htmlspecialchars($scopeMap[$scope] ?? str_replace(':', ' ', $scope));
            ?></div>
        </div>

        <form method="POST" action="/mcp/consent">
            <input type="hidden" name="txn_id" value="<?= htmlspecialchars($txnIdForForm ?? '') ?>">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(Session::csrfToken()) ?>">

            <div class="btn-row">
                <button type="submit" name="action" value="allow" class="btn btn-allow">Allow access</button>
                <button type="submit" name="action" value="deny" class="btn btn-deny">Deny</button>
            </div>
        </form>
    </div>
</body>
</html>
