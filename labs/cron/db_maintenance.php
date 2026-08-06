#!/usr/bin/env php
<?php
/**
 * D10: Periodic DB maintenance.
 * 
 * - Purges expired rate limit files
 * - Cleans up expired session tokens from user documents
 * - Logs maintenance results
 * 
 * Usage: php cron/db_maintenance.php [--dry-run]
 * 
 * Recommended cron schedule: 0 2 * * * (daily, 2am)
 */

require_once __DIR__ . '/../htdocs/src/load.php';

$dryRun = in_array('--dry-run', $argv);

echo "=== DB Maintenance ===\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$cleaned = 0;

// 1. Clean expired rate limit files
echo "--- Rate Limit Cleanup ---\n";
$rateLimitDirs = ['/dev/shm/ratelimit', '/dev/shm/ratelimit_actions', '/tmp/ratelimit', '/tmp/ratelimit_actions'];

foreach ($rateLimitDirs as $dir) {
    if (!is_dir($dir)) continue;
    
    $files = glob($dir . '/*.count');
    $expired = 0;
    
    foreach ($files as $file) {
        $mtime = filemtime($file);
        if ($mtime < time() - 3600) { // Older than 1 hour
            if (!$dryRun) {
                @unlink($file);
            }
            $expired++;
        }
    }
    
    if ($expired > 0) {
        echo "  {$dir}: {$expired} expired files\n";
        $cleaned += $expired;
    }
}

// 2. Clean expired session tokens from users
echo "\n--- Session Token Cleanup ---\n";
try {
    $db = DatabaseConnection::getDefaultDatabase();
    $thirtyDaysAgo = time() - (30 * 86400);
    
    $users = $db->users->find([
        'session_tokens' => ['$exists' => true, '$ne' => []]
    ]);
    
    foreach ($users as $user) {
        $tokens = $user['session_tokens'] ?? [];
        $validTokens = [];
        $removed = 0;
        
        foreach ($tokens as $token) {
            $createdAt = $token['created_at'] ?? 0;
            $lastActivity = $token['last_activity'] ?? 0;
            
            // Keep token if created or active in last 30 days
            if ($createdAt > $thirtyDaysAgo || $lastActivity > $thirtyDaysAgo) {
                $validTokens[] = $token;
            } else {
                $removed++;
            }
        }
        
        if ($removed > 0) {
            echo "  User {$user['email']}: {$removed} expired tokens\n";
            
            if (!$dryRun) {
                $db->users->updateOne(
                    ['_id' => $user['_id']],
                    ['$set' => ['session_tokens' => $validTokens]]
                );
            }
            
            $cleaned += $removed;
        }
    }
} catch (Exception $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

// 3. Clean expired 2FA OTPs
echo "\n--- 2FA OTP Cleanup ---\n";
try {
    $db = DatabaseConnection::getDefaultDatabase();
    $now = time();
    
    if (!$dryRun) {
        $result = $db->users->updateMany(
            [
                'two_factor_expires' => ['$exists' => true, '$lt' => $now]
            ],
            [
                '$unset' => ['two_factor_otp' => '', 'two_factor_expires' => '']
            ]
        );
        echo "  Cleaned: {$result->getModifiedCount()} expired OTPs\n";
        $cleaned += $result->getModifiedCount();
    } else {
        $count = $db->users->countDocuments([
            'two_factor_expires' => ['$exists' => true, '$lt' => $now]
        ]);
        echo "  Would clean: {$count} expired OTPs\n";
        $cleaned += $count;
    }
} catch (Exception $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

// 4. Clean expired password reset tokens
echo "\n--- Password Reset Token Cleanup ---\n";
try {
    $db = DatabaseConnection::getDefaultDatabase();
    $now = time();
    
    if (!$dryRun) {
        $result = $db->users->updateMany(
            [
                'password_reset_expires' => ['$exists' => true, '$lt' => $now]
            ],
            [
                '$unset' => ['password_reset_token' => '', 'password_reset_expires' => '']
            ]
        );
        echo "  Cleaned: {$result->getModifiedCount()} expired reset tokens\n";
        $cleaned += $result->getModifiedCount();
    } else {
        $count = $db->users->countDocuments([
            'password_reset_expires' => ['$exists' => true, '$lt' => $now]
        ]);
        echo "  Would clean: {$count} expired reset tokens\n";
        $cleaned += $count;
    }
} catch (Exception $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

echo "\n=== Total: {$cleaned} items cleaned ===\n";
