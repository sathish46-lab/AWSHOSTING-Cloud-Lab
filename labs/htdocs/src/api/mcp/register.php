<?php
/**
 * MCP OAuth 2.1 Dynamic Client Registration Endpoint (RFC 7591)
 * Allows MCP clients (like opencode) to register dynamically and obtain
 * a client_id for the OAuth flow.
 */

require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed', 'error_description' => 'Method not allowed']);
    exit;
}

// Parse request body (JSON or form-urlencoded)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$clientName = $input['client_name'] ?? '';
$redirectUris = $input['redirect_uris'] ?? [];
$grantTypes = $input['grant_types'] ?? ['authorization_code', 'refresh_token'];
$responseTypes = $input['response_types'] ?? ['code'];
$tokenEndpointAuthMethod = $input['token_endpoint_auth_method'] ?? 'none';
$scope = $input['scope'] ?? 'labs:*';

// Validate required fields
if (empty($redirectUris)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'invalid_redirect_uri',
        'error_description' => 'redirect_uris is required'
    ]);
    exit;
}

if (is_string($redirectUris)) {
    $redirectUris = [$redirectUris];
}

// Validate redirect URIs are absolute http/https URIs
foreach ($redirectUris as $uri) {
    if (!filter_var($uri, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_redirect_uri',
            'error_description' => "Invalid redirect_uri: $uri"
        ]);
        exit;
    }
    $parts = parse_url($uri);
    if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_redirect_uri',
            'error_description' => "redirect_uri must use http or https: $uri"
        ]);
        exit;
    }
}

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

try {
    $client = MCPOAuth::registerClient(
        $clientName ?: 'MCP Client',
        $redirectUris,
        is_array($scope) ? $scope : explode(' ', $scope)
    );

    echo json_encode([
        'client_id' => $client['client_id'],
        'client_id_issued_at' => time(),
        'client_name' => $client['client_name'],
        'redirect_uris' => $client['redirect_uris'],
        'grant_types' => $grantTypes,
        'response_types' => $responseTypes,
        'token_endpoint_auth_method' => $tokenEndpointAuthMethod,
        'scope' => implode(' ', $client['scopes']),
    ]);
    exit;

} catch (Exception $e) {
    error_log("MCP Register Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'error_description' => 'Internal server error']);
    exit;
}