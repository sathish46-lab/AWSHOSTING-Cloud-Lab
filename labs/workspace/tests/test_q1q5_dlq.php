<?php
/**
 * Test: Q1-Q5 — Dead Letter Queue configuration
 * 
 * Verifies:
 * 1. labs_worker.py declares DLQ queue
 * 2. labs_worker.py configures DLQ routing on main queue
 * 3. labs_worker.py has _publish_to_dlq function
 * 4. ai_worker.py declares DLQ queues
 * 5. ai_worker.py configures DLQ routing on main queues
 */

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

// Read files
$labsContent = file_get_contents(__DIR__ . '/../../worker/labs_worker.py');
$aiContent = file_get_contents(__DIR__ . '/../../worker/ai_worker.py');

// labs_worker.py DLQ
test('labs_worker: DLQ_NAME defined', strpos($labsContent, "DLQ_NAME = 'labs_jobs_dlq'") !== false);
test('labs_worker: declares DLQ queue', strpos($labsContent, 'queue_declare(') !== false && strpos($labsContent, 'labs_jobs_dlq') !== false);
test('labs_worker: configures dead-letter-exchange', strpos($labsContent, 'x-dead-letter-exchange') !== false);
test('labs_worker: configures dead-letter-routing-key', strpos($labsContent, 'x-dead-letter-routing-key') !== false);
test('labs_worker: has _publish_to_dlq function', strpos($labsContent, 'def _publish_to_dlq') !== false);
test('labs_worker: calls _publish_to_dlq on error', strpos($labsContent, '_publish_to_dlq(job_data') !== false);

// ai_worker.py DLQ
test('ai_worker: DLQ_NAME defined', strpos($aiContent, "DLQ_NAME = 'ai_jobs_dlq'") !== false);
test('ai_worker: CONTENT_DLQ_NAME defined', strpos($aiContent, "CONTENT_DLQ_NAME = 'ai_content_jobs_dlq'") !== false);
test('ai_worker: declares DLQ queues', strpos($aiContent, 'x-message-ttl') !== false);
test('ai_worker: configures dead-letter-exchange', substr_count($aiContent, 'x-dead-letter-exchange') >= 2);

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
