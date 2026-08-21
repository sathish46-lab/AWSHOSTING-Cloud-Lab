<?php
/**
 * Backfill legacy session tokens into the hashed format.
 *
 * Context: the remember-me / session-token flow was changed so session tokens
 * are stored ONLY as:
 *   - token_hash : bcrypt hash of the bearer token (password_verify)
 *   - token_id   : sha256 of the bearer token  (deterministic, for indexed lookup)
 *
 * Before this change, Google OAuth and 2FA logins stored the raw bearer token in
 * a plaintext `token` field. Those tokens were never usable for auto-login, and
 * they exposed secrets at rest. This script rewrites any remaining raw `token`
 * entries into the hashed pair, and ensures the lookup index exists.
 *
 * Usage (run inside the container):
 *   php backfill_session_tokens.php            # migrate + create index
 *   php backfill_session_tokens.php --dry-run  # report only, change nothing
 */

require_once __DIR__ . '/../load.php';

$dryRun = in_array('--dry-run', $argv, true);

$db = DatabaseConnection::getDefaultDatabase();
$users = $db->users;

$converted = 0;
$skipped = 0;

// Index: keeps initSession()/logout() lookups fast (single indexed query).
$indexName = 'session_tokens.token_id_1';
if (!$dryRun) {
    $users->createIndex(['session_tokens.token_id' => 1], ['name' => $indexName]);
    echo "[index] ensured: $indexName\n";
} else {
    echo "[index] would ensure: $indexName\n";
}

// Find users that still carry at least one raw plaintext `token` entry.
$cursor = $users->find(['session_tokens.token' => ['$exists' => true]]);

foreach ($cursor as $user) {
    $newTokens = [];
    $changed = false;
    $convertedThisDoc = 0;

    foreach (($user['session_tokens'] ?? []) as $tokenData) {
        // Emit non-token entries (or already-hashed entries) unchanged.
        if (!is_array($tokenData)) {
            $newTokens[] = $tokenData;
            continue;
        }

        $rawToken = isset($tokenData['token']) && is_string($tokenData['token']) ? $tokenData['token'] : null;

        // Already hashed (token_hash present) — nothing to do, drop any stale raw token.
        if (!empty($tokenData['token_hash']) || empty($rawToken)) {
            unset($tokenData['token']);
            $newTokens[] = $tokenData;
            continue;
        }

        // Legacy plaintext token → convert to hash + token_id.
        $tokenData['token_hash'] = password_hash($rawToken, PASSWORD_DEFAULT);
        $tokenData['token_id']   = hash('sha256', $rawToken);
        unset($tokenData['token']);
        $newTokens[] = $tokenData;
        $changed = true;
        $convertedThisDoc++;
    }

    if ($changed && !$dryRun) {
        $users->updateOne(
            ['_id' => $user['_id']],
            ['$set' => ['session_tokens' => $newTokens]]
        );
    }
    if ($changed) {
        printf("[%s] %-40s -> %d token(s) upgraded\n", $dryRun ? 'DRY' : 'mig', $user['email'] ?? $user['_id'], $convertedThisDoc);
        $converted += $convertedThisDoc;
    } else {
        $skipped++;
    }
}

echo "\nDone.\n";
echo ($dryRun ? "(dry run) " : "") . "Legacy plaintext tokens converted: $converted\n";
echo "Documents unchanged: $skipped\n";