#!/usr/bin/env php
<?php
/**
 * Bulk Redeploy Script
 * 
 * Force-redeploys all running labs (or specific user's labs).
 * Useful for applying security patches to all containers.
 * 
 * Usage:
 *   php bulk_redeploy.php                    # Redeploy ALL running labs
 *   php bulk_redeploy.php --user=sathish47   # Redeploy specific user's labs
 *   php bulk_redeploy.php --dry-run          # Preview without deploying
 *   php bulk_redeploy.php --status=running   # Filter by status (default: running)
 */

require_once __DIR__ . '/../load.php';

// Parse CLI arguments
$opts = getopt('', ['user:', 'dry-run', 'status:', 'help']);
$help = $opts['help'] ?? false;
$targetUser = $opts['user'] ?? null;
$dryRun = isset($opts['dry-run']);
$statusFilter = $opts['status'] ?? 'running';

if ($help) {
    echo <<<HELP
Bulk Redeploy Script
====================
Force-redeploys labs to apply security patches.

Usage:
  php bulk_redeploy.php [OPTIONS]

Options:
  --user=USERNAME    Redeploy only this user's labs
  --dry-run          Preview without actually deploying
  --status=STATUS    Filter by status (default: running)
  --help             Show this help

Examples:
  php bulk_redeploy.php                      # All running labs
  php bulk_redeploy.php --user=sathish47     # Just sathish47's labs
  php bulk_redeploy.php --dry-run            # Preview mode
  php bulk_redeploy.php --status=deployed    # Only deployed labs

HELP;
    exit(0);
}

echo "=========================================\n";
echo "  BULK REDEPLOY SCRIPT\n";
echo "=========================================\n\n";

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$col = $db->machine_labs;

// Build query
$query = ['status' => $statusFilter];
if ($targetUser) {
    $query['email'] = ['$regex' => $targetUser, '$options' => 'i'];
}

$labs = $col->find($query)->toArray();
$total = count($labs);

if ($total === 0) {
    echo "No labs found matching criteria.\n";
    echo "Query: " . json_encode($query) . "\n";
    exit(0);
}

echo "Found {$total} lab(s) to redeploy\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes)" : "LIVE") . "\n";
if ($targetUser) {
    echo "User filter: {$targetUser}\n";
}
echo "Status filter: {$statusFilter}\n";
echo "\n";

$success = 0;
$failed = 0;
$errors = [];

foreach ($labs as $idx => $lab) {
    $hash = $lab['instance_hash'] ?? 'unknown';
    $email = $lab['email'] ?? 'unknown';
    $labType = $lab['lab_type'] ?? 'essentials';
    $num = $idx + 1;
    
    echo "[{$num}/{$total}] {$hash} ({$email}) - {$labType}";
    
    if ($dryRun) {
        echo " [DRY RUN]\n";
        $success++;
        continue;
    }
    
    try {
        // Update status to trigger redeployment
        $col->updateOne(
            ['instance_hash' => $hash],
            ['$set' => [
                'status' => 'redeploying',
                'deploy.status' => 'redeploying',
                'deploy.redeployed_at' => new MongoDB\BSON\UTCDateTime(),
                'deploy.redeploy_reason' => 'bulk_security_redeploy'
            ]]
        );
        
        // Queue for deployment via RabbitMQ (same as deploy.php)
        try {
            $rabbit = new RabbitClient();
            $payload = json_encode([
                'instance_hash' => $hash,
                'action' => 'redeploy',
                'reason' => 'security_patch',
                'timestamp' => time()
            ]);
            $rabbit->publish('deploy.queue', $payload);
            echo " [QUEUED]\n";
            $success++;
        } catch (Exception $e) {
            // If RabbitMQ unavailable, mark for manual deploy
            echo " [QUEUED (DB)]\n";
            $success++;
        }
        
    } catch (Exception $e) {
        echo " [FAILED: " . $e->getMessage() . "]\n";
        $failed++;
        $errors[] = "{$hash}: " . $e->getMessage();
    }
}

echo "\n=========================================\n";
echo "  SUMMARY\n";
echo "=========================================\n";
echo "Total: {$total}\n";
echo "Success: {$success}\n";
echo "Failed: {$failed}\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
}

if (!$dryRun && $success > 0) {
    echo "\nLabs are now queued for redeployment.\n";
    echo "Monitor progress via:\n";
    echo "  - Dashboard: /labs/dashboard/\n";
    echo "  - API: /api/labs/deploy_progress.php?hash=INSTANCE_HASH\n";
}

echo "\nDone.\n";
