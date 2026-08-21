<?php
/**
 * MCP OAuth 2.1 Token Revocation Endpoint
 * Handles revoking access tokens and refresh tokens (RFC 7009)
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

// Parse request body
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$token = '';
$tokenTypeHint = '';
$clientId = '';
$clientSecret = '';

if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    $tokenTypeHint = $input['token_type_hint'] ?? '';
    $clientId = $input['client_id'] ?? '';
    $clientSecret = $input['client_secret'] ?? '';
} else {
    $token = $_POST['token'] ?? '';
    $tokenTypeHint = $_POST['token_type_hint'] ?? '';
    $clientId = $_POST['client_id'] ?? '';
    $clientSecret = $_POST['client_secret'] ?? '';
}

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

try {
    if (empty($token)) {
        // Per RFC 7009, if token is missing, return success (idempotent)
        http_response_code(200);
        echo json_encode([]);
        exit;
    }

    // Validate client if provided
    if (!empty($clientId)) {
        $client = MCPOAuth::getClient($clientId);
        if (!$client) {
            // Per RFC 7009, we don't reveal if client is invalid
            http_response_code(200);
            echo json_encode([]);
            exit;
        }
    }

    // Try to revoke as access token first
    $revoked = MCPOAuth::revokeToken($token, 'access');

    // If not revoked as access token, try as refresh token
    if (!$revoked) {
        $revoked = MCPOAuth::revokeToken($token, 'refresh');
    }

    // Per RFC 7009, always return 200 (idempotent)
    http_response_code(200);
    echo json_encode([]);

} catch (Exception $e) {
    error_log("MCP Revoke Error: " . $e->getMessage());
    // Per RFC 7009, don't reveal internal errors
    http_response_code(200);
    echo json_encode([]);
    exit;
}