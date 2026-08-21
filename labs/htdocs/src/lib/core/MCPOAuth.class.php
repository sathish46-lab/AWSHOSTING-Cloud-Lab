<?php
/**
 * MCPOAuth - Core OAuth 2.1 logic for MCP Authorization Server
 * Handles authorization codes, access tokens, refresh tokens, and client management.
 */

require_once __DIR__ . '/DatabaseConnection.class.php';
require_once __DIR__ . '/Session.class.php';

class MCPOAuth {
    private static $db = null;
    private static $accessTokenTtlDays = 7;
    private static $refreshTokenTtlDays = 30;
    private static $authCodeTtlMinutes = 10;
    private static $salt = "8b51626f3a468904e8b6f83747f2fcf1";

    /**
     * Get database connection
     */
    public static function getDb() {
        if (self::$db === null) {
            self::$db = DatabaseConnection::getDefaultDatabase();
        }
        return self::$db;
    }

    /**
     * Generate a secure random string
     */
    private static function generateSecureToken($bytes = 32) {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Hash a token with SHA256 (for indexed lookup)
     */
    private static function hashToken($token) {
        return hash('sha256', $token);
    }

    /**
     * Encrypt token with bcrypt (for verification)
     */
    private static function encryptToken($token) {
        return password_hash($token, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify token against bcrypt hash
     */
    private static function verifyToken($token, $hash) {
        return password_verify($token, $hash);
    }

    /**
     * Generate authorization code
     */
    public static function generateAuthCode($clientId, $userId, $username, $email, $redirectUri, $scopes, $codeChallenge, $codeChallengeMethod) {
        $db = self::getDb();

        $code = self::generateSecureToken(32);
        $codeHash = self::hashToken($code);
        $codeEnc = self::encryptToken($code);

        $expiresAt = new MongoDB\BSON\UTCDateTime((time() + self::$authCodeTtlMinutes * 60) * 1000);

        $doc = [
            'code_hash' => $codeHash,
            'code_enc' => $codeEnc,
            'client_id' => $clientId,
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => $expiresAt,
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'used' => false
        ];

        $db->mcp_auth_codes->insertOne($doc);

        return $code;
    }

    /**
     * Validate and consume authorization code
     */
    public static function validateAuthCode($code, $clientId, $redirectUri, $codeVerifier) {
        $db = self::getDb();

        $codeHash = self::hashToken($code);
        $doc = $db->mcp_auth_codes->findOne([
            'code_hash' => $codeHash,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'used' => ['$ne' => true],
            'expires_at' => ['$gte' => new MongoDB\BSON\UTCDateTime(time() * 1000)]
        ]);

        if (!$doc) {
            return ['valid' => false, 'error' => 'Invalid or expired authorization code'];
        }

        // Verify PKCE code challenge
        if (!empty($doc['code_challenge']) && !empty($doc['code_challenge_method'])) {
            if ($doc['code_challenge_method'] === 'S256') {
                $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                if (!hash_equals($expectedChallenge, $doc['code_challenge'])) {
                    return ['valid' => false, 'error' => 'PKCE verification failed'];
                }
            } elseif ($doc['code_challenge_method'] === 'plain') {
                if (!hash_equals($codeVerifier, $doc['code_challenge'])) {
                    return ['valid' => false, 'error' => 'PKCE verification failed'];
                }
            }
        }

        // Mark code as used
        $db->mcp_auth_codes->updateOne(
            ['_id' => $doc['_id']],
            ['$set' => ['used' => true, 'used_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
        );

        return [
            'valid' => true,
            'user_id' => $doc['user_id'],
            'username' => $doc['username'],
            'email' => $doc['email'],
            'scopes' => ($doc['scopes'] instanceof MongoDB\Model\BSONArray)
                ? $doc['scopes']->getArrayCopy()
                : (array) ($doc['scopes'] ?? [])
        ];
    }

    /**
     * Generate access token and refresh token
     */
    public static function generateTokens($clientId, $userId, $username, $email, $scopes) {
        $db = self::getDb();

        if ($scopes instanceof MongoDB\Model\BSONArray) {
            $scopes = $scopes->getArrayCopy();
        }
        $scopes = (array) $scopes;
        $scopeStr = implode(' ', $scopes);

        $accessToken = self::generateSecureToken(32);
        $refreshToken = self::generateSecureToken(32);

        $accessTokenHash = self::hashToken($accessToken);
        $accessTokenEnc = self::encryptToken($accessToken);
        $refreshTokenHash = self::hashToken($refreshToken);
        $refreshTokenEnc = self::encryptToken($refreshToken);

        $accessExpiresAt = new MongoDB\BSON\UTCDateTime((time() + self::$accessTokenTtlDays * 86400) * 1000);
        $refreshExpiresAt = new MongoDB\BSON\UTCDateTime((time() + self::$refreshTokenTtlDays * 86400) * 1000);

        $doc = [
            'client_id' => $clientId,
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'access_token_hash' => $accessTokenHash,
            'access_token_enc' => $accessTokenEnc,
            'refresh_token_hash' => $refreshTokenHash,
            'refresh_token_enc' => $refreshTokenEnc,
            'scopes' => $scopes,
            'access_expires_at' => $accessExpiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'last_used_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'revoked' => false
        ];

        $db->mcp_tokens->insertOne($doc);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::$accessTokenTtlDays * 86400,
            'scope' => $scopeStr
        ];
    }

    /**
     * Validate access token
     */
    public static function validateAccessToken($token) {
        $db = self::getDb();

        $tokenHash = self::hashToken($token);
        $doc = $db->mcp_tokens->findOne([
            'access_token_hash' => $tokenHash,
            'access_expires_at' => ['$gte' => new MongoDB\BSON\UTCDateTime(time() * 1000)],
            'revoked' => ['$ne' => true]
        ]);

        if (!$doc) {
            return ['valid' => false, 'error' => 'Invalid or expired access token'];
        }

        if (!self::verifyToken($token, $doc['access_token_enc'])) {
            return ['valid' => false, 'error' => 'Token verification failed'];
        }

        // Update last_used_at (non-blocking)
        $db->mcp_tokens->updateOne(
            ['_id' => $doc['_id']],
            ['$set' => ['last_used_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
        );

        return [
            'valid' => true,
            'user_id' => $doc['user_id'],
            'username' => $doc['username'],
            'email' => $doc['email'],
            'scopes' => $doc['scopes'],
            'client_id' => $doc['client_id']
        ];
    }

    /**
     * Refresh access token using refresh token
     */
    public static function refreshAccessToken($refreshToken, $clientId) {
        $db = self::getDb();

        $refreshTokenHash = self::hashToken($refreshToken);
        $doc = $db->mcp_tokens->findOne([
            'refresh_token_hash' => $refreshTokenHash,
            'client_id' => $clientId,
            'refresh_expires_at' => ['$gte' => new MongoDB\BSON\UTCDateTime(time() * 1000)],
            'revoked' => ['$ne' => true]
        ]);

        if (!$doc) {
            return ['valid' => false, 'error' => 'Invalid or expired refresh token'];
        }

        if (!self::verifyToken($refreshToken, $doc['refresh_token_enc'])) {
            return ['valid' => false, 'error' => 'Refresh token verification failed'];
        }

        // Generate new tokens (rotate refresh token)
        $newTokens = self::generateTokens(
            $clientId,
            $doc['user_id'],
            $doc['username'],
            $doc['email'],
            $doc['scopes']
        );

        // Revoke old tokens
        $db->mcp_tokens->updateOne(
            ['_id' => $doc['_id']],
            ['$set' => ['revoked' => true, 'revoked_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
        );

        return $newTokens;
    }

    /**
     * Revoke token (access or refresh)
     */
    public static function revokeToken($token, $tokenType = 'access') {
        $db = self::getDb();

        $tokenHash = self::hashToken($token);

        if ($tokenType === 'access') {
            $result = $db->mcp_tokens->updateOne(
                ['access_token_hash' => $tokenHash],
                ['$set' => ['revoked' => true, 'revoked_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
            );
        } else {
            $result = $db->mcp_tokens->updateOne(
                ['refresh_token_hash' => $tokenHash],
                ['$set' => ['revoked' => true, 'revoked_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
            );
        }

        return $result->getModifiedCount() > 0;
    }

    /**
     * Get or create MCP client
     */
    public static function getOrCreateClient($clientName, $redirectUris, $userId, $username, $email, $auto = false) {
        $db = self::getDb();

        // Check if client already exists for this user with same redirect URIs
        $existing = $db->mcp_clients->findOne([
            'user_id' => $userId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'revoked' => ['$ne' => true]
        ]);

        if ($existing) {
            // Update last_used_at
            $db->mcp_clients->updateOne(
                ['_id' => $existing['_id']],
                ['$set' => ['last_used_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
            );
            return $existing;
        }

        // Create new client
        $clientId = 'labs-mcp-' . self::generateSecureToken(16);

        $doc = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'redirect_uris' => $redirectUris,
            'scopes' => ['labs:*'],
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'last_used_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'revoked' => false
        ];

        if ($auto) {
            $doc['auto'] = true;
        }

        $db->mcp_clients->insertOne($doc);
        return $doc;
    }

    /**
     * Register a new MCP client via dynamic client registration (RFC 7591).
     * Not tied to a user — created before the user authenticates.
     */
    public static function registerClient($clientName, $redirectUris, $scopes = ['labs:*']) {
        $db = self::getDb();

        $clientId = 'labs-mcp-' . self::generateSecureToken(16);

        $doc = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'user_id' => null,
            'username' => null,
            'email' => null,
            'redirect_uris' => $redirectUris,
            'scopes' => $scopes,
            'registration_uri' => null,
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'last_used_at' => null,
            'revoked' => false
        ];

        $db->mcp_clients->insertOne($doc);
        return $doc;
    }

    /**
     * Get client by ID
     */
    public static function getClient($clientId) {
        $db = self::getDb();
        return $db->mcp_clients->findOne([
            'client_id' => $clientId,
            'revoked' => ['$ne' => true]
        ]);
    }

    /**
     * Validate redirect URI against client's registered URIs
     */
    public static function validateRedirectUri($clientId, $redirectUri) {
        $client = self::getClient($clientId);
        if (!$client) return false;

        $uris = $client['redirect_uris'];
        if ($uris instanceof MongoDB\Model\BSONArray) {
            $uris = $uris->getArrayCopy();
        }
        $uris = (array) $uris;

        return in_array($redirectUri, $uris);
    }

    /**
     * Create an OAuth authorization transaction for the /mcp/consent flow.
     * Returns a short-lived txn_id used in the consent page URL.
     */
    public static function createTransaction($clientId, $redirectUri, $scope, $state, $codeChallenge, $codeChallengeMethod) {
        $db = self::getDb();
        $txnId = self::generateSecureToken(24);
        $doc = [
            'txn_id' => $txnId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            'expires_at' => new MongoDB\BSON\UTCDateTime((time() + 600) * 1000),
            'consumed' => false
        ];
        $db->mcp_transactions->insertOne($doc);
        return $txnId;
    }

    /**
     * Fetch a valid (unconsumed, unexpired) transaction by txn_id.
     */
    public static function getTransaction($txnId) {
        $db = self::getDb();
        return $db->mcp_transactions->findOne([
            'txn_id' => $txnId,
            'consumed' => ['$ne' => true],
            'expires_at' => ['$gte' => new MongoDB\BSON\UTCDateTime(time() * 1000)]
        ]);
    }

    /**
     * Mark a transaction as consumed (single use).
     */
    public static function consumeTransaction($txnId) {
        $db = self::getDb();
        $db->mcp_transactions->updateOne(
            ['txn_id' => $txnId],
            ['$set' => ['consumed' => true, 'consumed_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
        );
    }

    /**
     * List MCP clients for a user
     */
    public static function getUserClients($userId) {
        $db = self::getDb();
        return iterator_to_array($db->mcp_clients->find([
            'user_id' => $userId,
            'auto' => ['$ne' => true],
            'revoked' => ['$ne' => true]
        ]));
    }

    /**
     * Revoke MCP client
     */
    public static function revokeClient($clientId, $userId) {
        $db = self::getDb();

        // Revoke client
        $result = $db->mcp_clients->updateOne(
            ['client_id' => $clientId, 'user_id' => $userId],
            ['$set' => ['revoked' => true, 'revoked_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
        );

        // Also revoke all associated tokens
        $db->mcp_tokens->updateMany(
            ['client_id' => $clientId],
            ['$set' => ['revoked' => true, 'revoked_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)]]
        );

        return $result->getModifiedCount() > 0;
    }

    /**
     * Generate lab instance hash for a user + lab name
     */
    public static function getLabHash($email, $labName) {
        if (!$email) return md5("guest" . $labName . self::$salt);
        return md5($email . $labName . self::$salt);
    }

    /**
     * Get OAuth server metadata for discovery
     */
    public static function getServerMetadata($baseUrl) {
        return [
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . '/mcp/authorize',
            'token_endpoint' => $baseUrl . '/mcp/token',
            'revocation_endpoint' => $baseUrl . '/mcp/revoke',
            'registration_endpoint' => $baseUrl . '/mcp/register',
            'scopes_supported' => ['labs:*'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'service_documentation' => $baseUrl . '/mcp/docs'
        ];
    }
}