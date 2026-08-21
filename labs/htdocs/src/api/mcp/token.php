<?php
/**
 * MCP OAuth 2.1 Token Endpoint
 * Handles token exchange (authorization_code, refresh_token) and token revocation
 */

require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Pragma: no-cache');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed', 'error_description' => 'Method not allowed']);
    exit;
}

// Parse request body (form-urlencoded or JSON)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$grantType = '';
$code = '';
$redirectUri = '';
$codeVerifier = '';
$refreshToken = '';
$clientId = '';
$clientSecret = '';

if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $grantType = $input['grant_type'] ?? '';
    $code = $input['code'] ?? '';
    $redirectUri = $input['redirect_uri'] ?? '';
    $codeVerifier = $input['code_verifier'] ?? '';
    $refreshToken = $input['refresh_token'] ?? '';
    $clientId = $input['client_id'] ?? '';
    $clientSecret = $input['client_secret'] ?? '';
} else {
    // application/x-www-form-urlencoded
    $grantType = $_POST['grant_type'] ?? '';
    $code = $_POST['code'] ?? '';
    $redirectUri = $_POST['redirect_uri'] ?? '';
    $codeVerifier = $_POST['code_verifier'] ?? '';
    $refreshToken = $_POST['refresh_token'] ?? '';
    $clientId = $_POST['client_id'] ?? '';
    $clientSecret = $_POST['client_secret'] ?? '';
}

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

try {
    if ($grantType === 'authorization_code') {
        // Authorization Code Grant (with PKCE)
        if (empty($code) || empty($clientId) || empty($redirectUri) || empty($codeVerifier)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameters: code, client_id, redirect_uri, code_verifier'
            ]);
            exit;
        }

        // Validate client
        $client = MCPOAuth::getClient($clientId);
        if (!$client) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_client', 'error_description' => 'Invalid client_id']);
            exit;
        }

        // Validate redirect URI
        if (!MCPOAuth::validateRedirectUri($clientId, $redirectUri)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Redirect URI mismatch']);
            exit;
        }

        // Validate and consume authorization code
        $result = MCPOAuth::validateAuthCode($code, $clientId, $redirectUri, $codeVerifier);

        if (!$result['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => $result['error']]);
            exit;
        }

        // Generate tokens
        $tokens = MCPOAuth::generateTokens(
            $clientId,
            $result['user_id'],
            $result['username'],
            $result['email'],
            $result['scopes']
        );

        echo json_encode($tokens);
        exit;

    } elseif ($grantType === 'refresh_token') {
        // Refresh Token Grant
        if (empty($refreshToken) || empty($clientId)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameters: refresh_token, client_id'
            ]);
            exit;
        }

        // Validate client
        $client = MCPOAuth::getClient($clientId);
        if (!$client) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_client', 'error_description' => 'Invalid client_id']);
            exit;
        }

        // Refresh access token
        $result = MCPOAuth::refreshAccessToken($refreshToken, $clientId);

        if (!$result['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => $result['error']]);
            exit;
        }

        echo json_encode($result);
        exit;

    } else {
        http_response_code(400);
        echo json_encode([
            'error' => 'unsupported_grant_type',
            'error_description' => "Grant type '$grantType' not supported. Supported: authorization_code, refresh_token"
        ]);
        exit;
    }

} catch (Exception $e) {
    error_log("MCP Token Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'error_description' => 'Internal server error']);
    exit;
}