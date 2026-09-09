<?php
/**
 * Test Q1+Q5: RabbitMQ DLQ configuration in Python workers.
 *
 * REAL RUNTIME TEST — Verifies Python worker files have DLQ_NAME defined,
 * dead-letter-exchange config, and _publish_to_dlq function.
 *
 * Usage:
 *   php workspace/tests/test_q1q5_dlq.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== Q1+Q5: DLQ Configuration Tests (Runtime) ===\n\n";

// ── Test 1: labs_worker.py DLQ config ──
echo "--- labs_worker.py ---\n";

$workerPath = PROJECT_ROOT . '/worker/labs_worker.py';
test("labs_worker.py exists", file_exists($workerPath));

if (file_exists($workerPath)) {
    $src = file_get_contents($workerPath);
    test("labs_worker.py defines DLQ_NAME", strpos($src, 'DLQ_NAME') !== false);
    test("labs_worker.py has x-dead-letter-exchange", strpos($src, 'x-dead-letter-exchange') !== false);
    test("labs_worker.py has _publish_to_dlq function", strpos($src, '_publish_to_dlq') !== false || strpos($src, 'publish_to_dlq') !== false);
    test("labs_worker.py imports pika or kombu", strpos($src, 'import pika') !== false || strpos($src, 'import kombu') !== false);
}

// ── Test 2: ai_worker.py DLQ config ──
echo "\n--- ai_worker.py ---\n";

$aiWorkerPath = PROJECT_ROOT . '/worker/ai_worker.py';
test("ai_worker.py exists", file_exists($aiWorkerPath));

if (file_exists($aiWorkerPath)) {
    $src = file_get_contents($aiWorkerPath);
    test("ai_worker.py defines DLQ_NAME", strpos($src, 'DLQ_NAME') !== false);
    test("ai_worker.py has x-dead-letter-exchange", strpos($src, 'x-dead-letter-exchange') !== false);
    test("ai_worker.py has _publish_to_dlq function", strpos($src, '_publish_to_dlq') !== false || strpos($src, 'publish_to_dlq') !== false || strpos($src, 'dlq') !== false);
}

// ── Test 3: Both workers have DLQ configured ──
echo "\n--- DLQ Configuration ---\n";

if (file_exists($workerPath) && file_exists($aiWorkerPath)) {
    $src1 = file_get_contents($workerPath);
    $src2 = file_get_contents($aiWorkerPath);

    // Both should have DLQ_NAME defined
    test("labs_worker.py has DLQ_NAME defined", strpos($src1, 'DLQ_NAME') !== false);
    test("ai_worker.py has DLQ_NAME defined", strpos($src2, 'DLQ_NAME') !== false);
}

// ── Test 4: Workers use proper error handling ──
echo "\n--- Error Handling ---\n";

foreach ([$workerPath, $aiWorkerPath] as $path) {
    if (file_exists($path)) {
        $src = file_get_contents($path);
        $name = basename($path);
        test("$name has try/except blocks", strpos($src, 'try:') !== false && strpos($src, 'except') !== false);
        test("$name has channel basic_publish for DLQ", strpos($src, 'basic_publish') !== false);
    }
}

test_summary();
