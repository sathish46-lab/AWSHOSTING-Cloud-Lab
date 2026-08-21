<?php
// /var/www/labs/htdocs/src/worker/DeployLog.worker.php
require_once __DIR__ . '/../load.php';
require_once __DIR__ . '/../lib/core/RabbitClient.class.php';
use TomLabs\Labs\IPManager;

// 1. Force unbuffered output
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
putenv('PYTHONUNBUFFERED=1');

// 2. Decode Input
$username = base64_decode($argv[1] ?? 'admin');
$taskData  = json_decode(base64_decode($argv[2] ?? '{}'), true);

$instanceHash = $taskData['hash'] ?? 'unknown';
$action = $taskData['action'] ?? 'deploy';

// Publish to the default amq.topic exchange with routing key logs.<hash> so the
// browser's STOMP /topic/logs.<hash> subscription receives the live log stream
// (previously this published to a separate fanout exchange and the UI never saw it).
$rabbit = new RabbitClient();
$logRoutingKey = "logs." . $instanceHash;

// 3. Wait for Browser WebSocket
sleep(2); 

$python = "/usr/bin/python3";
$script = "/opt/labs-control-panel/labsctl.py";

$isChallenge = !empty($taskData['is_challenge']);

if ($isChallenge) {
    $challengeId = $taskData['challenge_id'] ?? 'unknown';
    $cmd = "sudo $python $script challenge $action --user=$username --hash=$instanceHash --challenge=$challengeId";
} elseif ($taskData['lab'] === 'instance') {
    // New format: labsctl instance deploy --hash=HASH --user=USER
    $cmd = "sudo $python $script instance $action --user=$username --hash=$instanceHash";
} else {
    // New format: labsctl lab deploy --hash=HASH --user=USER
    $cmd = "sudo $python $script lab $action --user=$username --hash=$instanceHash";

    // Append MinIO flags if present
    if (!empty($taskData['minio_console_domain'])) {
        $cmd .= " --minio-console-domain=" . escapeshellarg($taskData['minio_console_domain']);
    }
    if (!empty($taskData['minio_api_domain'])) {
        $cmd .= " --minio-api-domain=" . escapeshellarg($taskData['minio_api_domain']);
    }

    if (!empty($taskData['n8n_domain'])) {
        $cmd .= " --n8n-domain=" . escapeshellarg($taskData['n8n_domain']);
    }
}


// Redirect stderr to stdout
$cmd .= " 2>&1";

$logDir = '/var/log/labsctl';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . '/labsctl.log';
$logHandle = fopen($logFile, 'a');
$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTime('now', $tz);
$istTime = $now->format('d M Y h:i:s A');
fwrite($logHandle, "\n=== " . $istTime . " IST | Action: $action | Hash: $instanceHash ===\n");

$handle = popen($cmd, 'r');
$success = false;

if (is_resource($handle)) {
    while (!feof($handle)) {
        $line = fgets($handle); 
        if ($line) {
            $trimmed = trim($line);
            fwrite($logHandle, $trimmed . "\n");
            fflush($logHandle);
            
            // Attempt to parse as JSON from our structured logger
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && isset($decoded['msg'])) {
                // It's structured
                $msgText = $decoded['msg'];
                $level = $decoded['level'] ?? 'info';
                
                // Add legacy prefixes for the UI if it expects them
                $prefixes = ["info" => "[*]", "success" => "[✓]", "error" => "[!]", "warn" => "[!]"];
                $prefix = $prefixes[$level] ?? "[*]";
                $rabbit->sendMessage(['log' => "$prefix $msgText"], $logRoutingKey);
                
                if (strpos($msgText, 'Deployment Complete') !== false || 
                    strpos($msgText, 'Code-server started successfully') !== false ||
                    strpos($msgText, 'Code-server is already running') !== false) {
                    $success = true;
                }
            } else {
                // Fallback for raw output (e.g., docker build output, raw errors)
                $rabbit->sendMessage(['log' => $trimmed], $logRoutingKey);
                
                if (strpos($trimmed, '[✓] Deployment Complete') !== false || 
                    strpos($trimmed, 'Deployment Complete') !== false ||
                    strpos($trimmed, 'Code-server started successfully') !== false) {
                    $success = true;
                }
            }
        }
    }
    pclose($handle);
    fclose($logHandle);
}

if (!$success && $action === 'deploy') {
    $db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
    $db->instances->updateOne(
        ['instance_hash' => $instanceHash],
        ['$set' => [
            'deploy.status' => 'failed',
            'status' => 'error',
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ]]
    );
    $rabbit->sendMessage(['log' => '[!] Deployment failed. Reverting system state...'], $logRoutingKey);
}
?>