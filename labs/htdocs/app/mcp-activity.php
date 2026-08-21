<?php
require_once __DIR__ . '/../src/load.php';
require_once __DIR__ . '/../src/lib/core/MCPOAuth.class.php';

// Feature gate: MCP must be enabled
if (!\TomLabs\Labs\LabFeatures::canAccessMcp()) {
    header('Location: /account');
    exit;
}

Session::$pageTitle = 'MCP Inspector — Activity';
Session::addMetaTag(['name' => 'robots', 'content' => 'noindex, nofollow']);
Session::addMetaTag(['name' => 'description', 'content' => 'MCP Inspector - Activity timeline']);

$isLoggedIn = (Session::getAuthStatus() === Constants::STATUS_LOGGEDIN);
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$redirectUri = $baseUrl . '/mcp';
$clientId = '';
$authUrl = $isLoggedIn ? '' : '/signin?redirect=' . urlencode('/mcp');
$pkce = null;

if ($isLoggedIn) {
    $currentUser = Session::getUser();
    $client = MCPOAuth::getOrCreateClient('Tom Labs Inspector', [$redirectUri], $currentUser->getUserId(), $currentUser->getUsername(), $currentUser->getEmail(), true);
    $clientId = $client['client_id'];

    if (empty($_SESSION['mcp_inspector_pkce'])) {
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = base64_encode(hash('sha256', $codeVerifier, true));
        $_SESSION['mcp_inspector_pkce'] = [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
            'state' => bin2hex(random_bytes(16)),
            'created_at' => time()
        ];
    }
    $pkce = $_SESSION['mcp_inspector_pkce'];
    $authUrl = $baseUrl . '/mcp/authorize?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'labs:*',
        'state' => $pkce['state'],
        'code_challenge' => $pkce['code_challenge'],
        'code_challenge_method' => 'S256'
    ]);
}

Session::set('mcp_baseUrl', $baseUrl);
Session::set('mcp_redirectUri', $redirectUri);
Session::set('mcp_clientId', $clientId);
Session::set('mcp_authUrl', $authUrl);
Session::set('mcp_pkce', $pkce);

Session::loadMaster();
