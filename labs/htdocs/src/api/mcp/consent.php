<?php
/**
 * MCP OAuth 2.1 Consent Endpoint
 * Renders the consent page for a pending authorization transaction
 * (created by /mcp/authorize) and processes the allow/deny decision.
 *
 * URL: /mcp/consent?txn_id=...
 */

require_once __DIR__ . '/../../load.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

$txnId = $_GET['txn_id'] ?? ($_POST['txn_id'] ?? '');

if (empty($txnId)) {
    http_response_code(400);
    echo 'Missing transaction ID.';
    exit;
}

$txn = MCPOAuth::getTransaction($txnId);
if (!$txn) {
    http_response_code(400);
    echo 'Invalid or expired authorization request. Please restart the sign-in flow.';
    exit;
}

// Ensure the user is authenticated (the main site session)
if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    header('Location: /signin?redirect=' . urlencode('/mcp/consent?txn_id=' . $txnId));
    exit;
}

// The 302 back to the client callback after allow/deny must be permitted
// by the CSP form-action directive. Build a page-specific CSP that allows
// the client's registered redirect origin (validated server-side above).
$redirectParts = parse_url($txn['redirect_uri']);
$redirectOrigin = '';
if (!empty($redirectParts['scheme']) && !empty($redirectParts['host'])) {
    $redirectOrigin = $redirectParts['scheme'] . '://' . $redirectParts['host'];
    if (!empty($redirectParts['port'])) {
        $redirectOrigin .= ':' . $redirectParts['port'];
    }
}
$pageCsp = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
    . "img-src 'self' data: blob: https:; "
    . "connect-src 'self' ws: wss: https:; "
    . "frame-ancestors 'self'; "
    . "base-uri 'self'; "
    . "form-action 'self' " . $redirectOrigin;
header('Content-Security-Policy: ' . $pageCsp);

$user = Session::getUser();
$userId = $user->getUserId();
$username = $user->getUsername();
$email = $user->getEmail();

// Handle the consent decision (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Session::validateCsrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'deny') {
        $params = ['error' => 'access_denied', 'error_description' => 'User denied access'];
        if (!empty($txn['state'])) {
            $params['state'] = $txn['state'];
        }
        header('Location: ' . $txn['redirect_uri'] . '?' . http_build_query($params));
        exit;
    }

    if ($action === 'allow') {
        // Record/refresh the grant so future requests can be auto-approved
        $existingGrant = MCPOAuth::getDb()->mcp_grants->findOne([
            'client_id' => $txn['client_id'],
            'user_id' => $userId,
            'revoked' => ['$ne' => true]
        ]);

        if (!$existingGrant) {
            MCPOAuth::getDb()->mcp_grants->insertOne([
                'client_id' => $txn['client_id'],
                'user_id' => $userId,
                'username' => $username,
                'email' => $email,
                'scopes' => explode(' ', $txn['scope']),
                'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
                'revoked' => false
            ]);
        }

        // Generate the authorization code
        $code = MCPOAuth::generateAuthCode(
            $txn['client_id'],
            $userId,
            $username,
            $email,
            $txn['redirect_uri'],
            explode(' ', $txn['scope']),
            $txn['code_challenge'],
            $txn['code_challenge_method']
        );

        // Single-use transaction
        MCPOAuth::consumeTransaction($txnId);

        $params = ['code' => $code];
        if (!empty($txn['state'])) {
            $params['state'] = $txn['state'];
        }
        header('Location: ' . $txn['redirect_uri'] . '?' . http_build_query($params));
        exit;
    }
}

// GET — populate the template inputs from the transaction and render the page
$_GET['client_id'] = $txn['client_id'];
$_GET['redirect_uri'] = $txn['redirect_uri'];
$_GET['scope'] = $txn['scope'];
$_GET['state'] = $txn['state'];
$_GET['code_challenge'] = $txn['code_challenge'];
$_GET['code_challenge_method'] = $txn['code_challenge_method'];
$txnIdForForm = $txnId;

require_once __DIR__ . '/../../template/mcp/consent.php';
