#!/usr/bin/env php
<?php
/**
 * D4: Purge soft-deleted records after retention period.
 * 
 * Permanently deletes records with status='deleted' older than $retentionDays.
 * 
 * Usage: php cron/purge_deleted.php [--days=30] [--dry-run]
 * 
 * Recommended cron schedule: 0 3 * * 0 (weekly, Sunday 3am)
 */

require_once __DIR__ . '/../htdocs/src/load.php';

$retentionDays = 30;
$dryRun = false;

// Parse CLI args
foreach ($argv as $arg) {
    if (strpos($arg, '--days=') === 0) {
        $retentionDays = (int)substr($arg, 8);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$cutoff = time() - ($retentionDays * 86400);
$cutoffDate = date('Y-m-d H:i:s', $cutoff);
$cutoffBson = new MongoDB\BSON\UTCDateTime($cutoff * 1000);

echo "=== Purge Soft-Deleted Records ===\n";
echo "Retention: {$retentionDays} days (cutoff: {$cutoffDate})\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . "\n\n";

$totalPurged = 0;

// Collections to purge (soft-deleted records)
$collections = [
    ['db' => 'tom_labs_db', 'col' => 'mysql_services', 'label' => 'MySQL Services'],
    ['db' => 'tom_labs_db', 'col' => 'mysql_users', 'label' => 'MySQL Users'],
    ['db' => 'tom_labs_db', 'col' => 'domains', 'label' => 'Domains'],
    ['db' => 'tom_labs_db', 'col' => 'ssh_keys', 'label' => 'SSH Keys'],
    ['db' => 'tom_labs_db', 'col' => 'devices', 'label' => 'VPN Devices'],
];

foreach ($collections as $item) {
    try {
        $db = DatabaseConnection::getClient()->selectDatabase($item['db']);
        $col = $db->selectCollection($item['col']);
        
        $filter = [
            'status' => 'deleted',
            'deleted_at' => ['$lte' => $cutoffBson]
        ];
        
        $count = $col->countDocuments($filter);
        
        if ($count > 0) {
            echo "{$item['label']}: {$count} records to purge\n";
            
            if (!$dryRun) {
                $result = $col->deleteMany($filter);
                echo "  Purged: {$result->getDeletedCount()} records\n";
            }
            
            $totalPurged += $count;
        } else {
            echo "{$item['Label']}: 0 records\n";
        }
    } catch (Exception $e) {
        echo "{$item['label']}: ERROR - {$e->getMessage()}\n";
    }
}

// Also purge from instance_trash
try {
    $instDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
    $filter = [
        'trashed_at' => ['$lte' => $cutoffBson]
    ];
    
    $count = $instDb->instance_trash->countDocuments($filter);
    
    if ($count > 0) {
        echo "Instance Trash: {$count} records to purge\n";
        
        if (!$dryRun) {
            $result = $instDb->instance_trash->deleteMany($filter);
            echo "  Purged: {$result->getDeletedCount()} records\n";
        }
        
        $totalPurged += $count;
    } else {
        echo "Instance Trash: 0 records\n";
    }
} catch (Exception $e) {
    echo "Instance Trash: ERROR - {$e->getMessage()}\n";
}

echo "\n=== Total: {$totalPurged} records " . ($dryRun ? "would be" : "") . " purged ===\n";
