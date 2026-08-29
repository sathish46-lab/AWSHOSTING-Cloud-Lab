#!/usr/bin/env php
<?php
/**
 * Deploy Queue Manager
 * 
 * Manages a MongoDB-based deployment queue with throttling.
 * Uses labsctl as the deploy engine.
 * 
 * Commands:
 *   enqueue   - Add labs to deploy queue
 *   process   - Process queue with throttle
 *   status    - Show queue status
 *   health    - Check DB vs actual container state
 *   reconcile - Fix mismatched states
 *   cancel    - Cancel pending jobs
 */

require_once __DIR__ . '/../load.php';

class DeployQueue {
    private $db;
    private $collection;
    
    public function __construct() {
        $this->db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
        $this->collection = $this->db->deploy_queue;
    }
    
    /**
     * Enqueue labs for deployment
     */
    public function enqueue(array $filters, string $reason = 'manual', array $options = []): array {
        $labs = $this->findLabs($filters);
        $enqueued = 0;
        $skipped = 0;
        
        foreach ($labs as $lab) {
            $hash = $lab['instance_hash'];
            
            // Skip if already queued or deploying
            $existing = $this->collection->findOne([
                'instance_hash' => $hash,
                'status' => ['$in' => ['queued', 'processing']]
            ]);
            
            if ($existing) {
                $skipped++;
                continue;
            }
            
            $job = [
                'instance_hash' => $hash,
                'user_id' => $lab['user_id'] ?? null,
                'email' => $lab['email'] ?? null,
                'lab_type' => $lab['lab_type'] ?? 'essentials',
                'status' => 'queued',
                'reason' => $reason,
                'options' => $options,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'started_at' => null,
                'completed_at' => null,
                'error' => null,
                'retries' => 0,
                'max_retries' => $options['max_retries'] ?? 2
            ];
            
            $this->collection->insertOne($job);
            $enqueued++;
        }
        
        return [
            'enqueued' => $enqueued,
            'skipped' => $skipped,
            'total' => count($labs)
        ];
    }
    
    /**
     * Process queue with throttle
     */
    public function process(int $throttle = 3, int $timeout = 300): array {
        $processed = 0;
        $failed = 0;
        $started = time();
        
        while ((time() - $started) < $timeout) {
            // Get next queued job
            $job = $this->collection->findOneAndUpdate(
                ['status' => 'queued'],
                ['$set' => [
                    'status' => 'processing',
                    'started_at' => new MongoDB\BSON\UTCDateTime()
                ]],
                ['sort' => ['created_at' => 1]]
            );
            
            if (!$job) {
                // No more jobs
                break;
            }
            
            // Check throttle
            $processing = $this->collection->countDocuments(['status' => 'processing']);
            if ($processing > $throttle) {
                // Wait and retry
                sleep(2);
                // Put job back
                $this->collection->updateOne(
                    ['_id' => $job['_id']],
                    ['$set' => ['status' => 'queued']]
                );
                continue;
            }
            
            // Execute deploy
            $result = $this->executeDeploy($job);
            
            if ($result['success']) {
                $this->collection->updateOne(
                    ['_id' => $job['_id']],
                    ['$set' => [
                        'status' => 'completed',
                        'completed_at' => new MongoDB\BSON\UTCDateTime(),
                        'result' => $result
                    ]]
                );
                $processed++;
            } else {
                $retries = ($job['retries'] ?? 0) + 1;
                $maxRetries = $job['max_retries'] ?? 2;
                
                if ($retries < $maxRetries) {
                    // Retry
                    $this->collection->updateOne(
                        ['_id' => $job['_id']],
                        ['$set' => [
                            'status' => 'queued',
                            'retries' => $retries,
                            'error' => $result['error']
                        ]]
                    );
                } else {
                    // Mark as failed
                    $this->collection->updateOne(
                        ['_id' => $job['_id']],
                        ['$set' => [
                            'status' => 'failed',
                            'completed_at' => new MongoDB\BSON\UTCDateTime(),
                            'error' => $result['error']
                        ]]
                    );
                    $failed++;
                }
            }
        }
        
        return [
            'processed' => $processed,
            'failed' => $failed,
            'remaining' => $this->collection->countDocuments(['status' => 'queued'])
        ];
    }
    
    /**
     * Execute deploy using labsctl
     */
    private function executeDeploy(array $job): array {
        $hash = $job['instance_hash'];
        $user = $job['email'] ?? '';
        
        // Extract username from email
        $username = explode('@', $user)[0] ?? $hash;
        
        // Call labsctl deploy
        $cmd = "labsctl deploy --hash=" . escapeshellarg($hash) . " --user=" . escapeshellarg($username) . " 2>&1";
        $output = [];
        $exitCode = 0;
        
        exec($cmd, $output, $exitCode);
        
        $outputStr = implode("\n", $output);
        
        // Update instance status in DB
        $this->updateInstanceStatus($hash, $exitCode === 0 ? 'deploying' : 'error');
        
        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $outputStr,
            'error' => $exitCode !== 0 ? $outputStr : null
        ];
    }
    
    /**
     * Update instance status in machine_labs
     */
    private function updateInstanceStatus(string $hash, string $status): void {
        $this->db->machine_labs->updateOne(
            ['instance_hash' => $hash],
            ['$set' => [
                'status' => $status,
                'updated_at' => new MongoDB\BSON\UTCDateTime()
            ]]
        );
    }
    
    /**
     * Find labs matching filters
     */
    private function findLabs(array $filters): array {
        $query = [];
        
        if (!empty($filters['status'])) {
            $query['status'] = $filters['status'];
        }
        
        if (!empty($filters['email'])) {
            $query['email'] = ['$regex' => $filters['email'], '$options' => 'i'];
        }
        
        if (!empty($filters['user_id'])) {
            $query['user_id'] = (int)$filters['user_id'];
        }
        
        if (!empty($filters['hash'])) {
            $query['instance_hash'] = $filters['hash'];
        }
        
        return $this->db->machine_labs->find($query)->toArray();
    }
    
    /**
     * Get queue status
     */
    public function getStatus(): array {
        return [
            'queued' => $this->collection->countDocuments(['status' => 'queued']),
            'processing' => $this->collection->countDocuments(['status' => 'processing']),
            'completed' => $this->collection->countDocuments(['status' => 'completed']),
            'failed' => $this->collection->countDocuments(['status' => 'failed']),
            'recent' => $this->collection->find([], [
                'sort' => ['created_at' => -1],
                'limit' => 10
            ])->toArray()
        ];
    }
    
    /**
     * Health check: compare DB status vs actual container state
     */
    public function healthCheck(): array {
        $labs = $this->db->machine_labs->find([])->toArray();
        $results = [];
        
        foreach ($labs as $lab) {
            $hash = $lab['instance_hash'];
            $dbStatus = $lab['status'] ?? 'unknown';
            
            // Check actual container state
            $containerStatus = $this->getContainerStatus($hash);
            
            $mismatch = false;
            $expectedStatus = $dbStatus;
            
            // Determine expected status based on container state
            if ($containerStatus === 'running') {
                $expectedStatus = 'running';
            } elseif ($containerStatus === 'exited' || $containerStatus === 'dead') {
                $expectedStatus = 'stopped';
            } elseif ($containerStatus === 'not_found') {
                $expectedStatus = 'not_deployed';
            }
            
            if ($dbStatus !== $expectedStatus) {
                $mismatch = true;
            }
            
            $results[] = [
                'instance_hash' => $hash,
                'email' => $lab['email'] ?? null,
                'lab_type' => $lab['lab_type'] ?? 'essentials',
                'db_status' => $dbStatus,
                'container_status' => $containerStatus,
                'expected_status' => $expectedStatus,
                'mismatch' => $mismatch
            ];
        }
        
        return $results;
    }
    
    /**
     * Get actual Docker container status
     */
    private function getContainerStatus(string $hash): string {
        $cmd = "docker inspect --format='{{.State.Status}}' instance_{$hash} 2>/dev/null";
        exec($cmd, $output, $exitCode);
        
        if ($exitCode !== 0 || empty($output)) {
            return 'not_found';
        }
        
        return trim($output[0]);
    }
    
    /**
     * Reconcile mismatched states
     */
    public function reconcile(bool $dryRun = false): array {
        $health = $this->healthCheck();
        $fixed = 0;
        $skipped = 0;
        
        foreach ($health as $lab) {
            if (!$lab['mismatch']) {
                continue;
            }
            
            $hash = $lab['instance_hash'];
            $current = $lab['db_status'];
            $expected = $lab['expected_status'];
            
            if ($dryRun) {
                echo "[DRY RUN] {$hash}: {$current} → {$expected}\n";
                $fixed++;
                continue;
            }
            
            // Update DB to match actual state
            $this->db->machine_labs->updateOne(
                ['instance_hash' => $hash],
                ['$set' => [
                    'status' => $expected,
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                    'reconciled_at' => new MongoDB\BSON\UTCDateTime()
                ]]
            );
            
            // If container was running but DB said stopped, mark as deployed
            if ($expected === 'running') {
                $this->db->machine_labs->updateOne(
                    ['instance_hash' => $hash],
                    ['$set' => [
                        'deploy.status' => 'running',
                        'deploy.reconciled_at' => new MongoDB\BSON\UTCDateTime()
                    ]]
                );
            }
            
            $fixed++;
        }
        
        return [
            'checked' => count($health),
            'mismatches' => count(array_filter($health, fn($h) => $h['mismatch'])),
            'fixed' => $fixed
        ];
    }
    
    /**
     * Cancel pending jobs
     */
    public function cancel(array $filters = []): int {
        $query = ['status' => 'queued'];
        
        if (!empty($filters['hash'])) {
            $query['instance_hash'] = $filters['hash'];
        }
        
        $result = $this->collection->updateMany(
            $query,
            ['$set' => [
                'status' => 'cancelled',
                'cancelled_at' => new MongoDB\BSON\UTCDateTime()
            ]]
        );
        
        return $result->getModifiedCount();
    }
}

// CLI Interface
$cmd = $argv[1] ?? 'help';
$queue = new DeployQueue();

switch ($cmd) {
    case 'enqueue':
        $reason = $argv[2] ?? 'manual';
        $status = $argv[3] ?? 'running';
        $result = $queue->enqueue(['status' => $status], $reason);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;
        
    case 'process':
        $throttle = (int)($argv[2] ?? 3);
        $timeout = (int)($argv[3] ?? 300);
        $result = $queue->process($throttle, $timeout);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;
        
    case 'status':
        $status = $queue->getStatus();
        echo "Queue Status:\n";
        echo "  Queued:     {$status['queued']}\n";
        echo "  Processing: {$status['processing']}\n";
        echo "  Completed:  {$status['completed']}\n";
        echo "  Failed:     {$status['failed']}\n";
        if (!empty($status['recent'])) {
            echo "\nRecent Jobs:\n";
            foreach ($status['recent'] as $job) {
                $ts = $job['created_at']->toDateTime()->format('Y-m-d H:i:s');
                echo "  [{$job['status']}] {$job['instance_hash']} ({$job['reason']}) - {$ts}\n";
            }
        }
        break;
        
    case 'health':
        $health = $queue->healthCheck();
        $mismatches = 0;
        foreach ($health as $lab) {
            $icon = $lab['mismatch'] ? '✗' : '✓';
            $color = $lab['mismatch'] ? "\033[31m" : "\033[32m";
            $reset = "\033[0m";
            echo "{$color}{$icon}{$reset} {$lab['instance_hash']}: DB={$lab['db_status']}, Container={$lab['container_status']}\n";
            if ($lab['mismatch']) $mismatches++;
        }
        echo "\nChecked: " . count($health) . ", Mismatches: {$mismatches}\n";
        break;
        
    case 'reconcile':
        $dryRun = in_array('--dry-run', $argv);
        $result = $queue->reconcile($dryRun);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        break;
        
    case 'cancel':
        $count = $queue->cancel();
        echo "Cancelled {$count} job(s)\n";
        break;
        
    default:
        echo <<<HELP
Deploy Queue Manager

Usage: php deploy_queue.php <command> [options]

Commands:
  enqueue [reason] [status]  Add labs to queue (default: status=running)
  process [throttle] [timeout]  Process queue (default: throttle=3, timeout=300s)
  status                     Show queue status
  health                     Check DB vs container state
  reconcile [--dry-run]      Fix mismatched states
  cancel                     Cancel pending jobs

Examples:
  php deploy_queue.php enqueue security_patch running
  php deploy_queue.php process 5 600
  php deploy_queue.php health
  php deploy_queue.php reconcile --dry-run

HELP;
}
