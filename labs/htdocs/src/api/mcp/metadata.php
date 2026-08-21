<?php
/**
 * MCP OAuth 2.1 Authorization Server Metadata
 * Discovery document per RFC 8414
 */

require_once __DIR__ . '/../../load.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../lib/core/MCPOAuth.class.php';

// Determine base URL - MCP server runs at /mcp on main domain
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

$metadata = MCPOAuth::getServerMetadata($baseUrl);

echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);