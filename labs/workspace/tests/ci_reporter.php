<?php
/**
 * CI Test Reporter — Generates JUnit XML from test results.
 * 
 * Usage:
 *   Include this in test files to generate JUnit XML output:
 *     require_once __DIR__ . '/ci_reporter.php';
 *     ci_reporter_init('test_s5_csrf');
 *     // ... run tests ...
 *     ci_reporter_final();
 * 
 * Or wrap existing tests:
 *   ci_reporter_run('test_s5_csrf', function() {
 *       // ... test code ...
 *   });
 */

$ci_results = [];
$ci_suite_name = 'dev-lab';
$ci_start_time = 0;

/**
 * Initialize CI reporter.
 */
function ci_reporter_init(string $test_name): void {
    global $ci_results, $ci_start_time;
    $ci_results = [];
    $ci_start_time = microtime(true);
    
    // Override the test() function to capture results
    $GLOBALS['_ci_original_test'] = $GLOBALS['test'] ?? null;
    $GLOBALS['test'] = function(string $name, bool $condition, string $detail = '') use ($test_name) {
        global $ci_results, $passed, $failed;
        
        $class = $condition ? 'PASS' : 'FAIL';
        $message = $condition ? $name : "$name $detail";
        
        $ci_results[] = [
            'name' => $test_name . '::' . $name,
            'class' => $class,
            'message' => $message,
            'time' => 0,
            'type' => $condition ? null : 'assertion',
        ];
        
        // Also call original test() if it exists
        if (isset($GLOBALS['_ci_original_test']) && is_callable($GLOBALS['_ci_original_test'])) {
            ($GLOBALS['_ci_original_test'])($name, $condition, $detail);
        } else {
            // Default test() behavior
            if ($condition) {
                echo "  PASS: $name\n";
                $GLOBALS['test_passed']++;
            } else {
                echo "  FAIL: $name" . ($detail ? " — $detail" : '') . "\n";
                $GLOBALS['test_failed']++;
            }
        }
    };
}

/**
 * Finalize and write JUnit XML.
 */
function ci_reporter_final(): void {
    global $ci_results, $ci_suite_name, $ci_start_time;
    
    $output_dir = getenv('CI_ARTIFACTS_DIR') ?: sys_get_temp_dir();
    $output_file = $output_dir . '/junit.xml';
    
    if (!is_dir($output_dir)) {
        @mkdir($output_dir, 0775, true);
    }
    
    $total_time = microtime(true) - $ci_start_time;
    $total_tests = count($ci_results);
    $failures = count(array_filter($ci_results, fn($r) => $r['class'] === 'FAIL'));
    
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><testsuites/>');
    $suite = $xml->addChild('testsuite');
    $suite->addAttribute('name', $ci_suite_name);
    $suite->addAttribute('tests', $total_tests);
    $suite->addAttribute('failures', $failures);
    $suite->addAttribute('time', number_format($total_time, 3));
    
    foreach ($ci_results as $result) {
        $test = $suite->addChild('testcase');
        $test->addAttribute('name', $result['name']);
        $test->addAttribute('classname', $result['class']);
        $test->addAttribute('time', number_format($result['time'] ?? 0, 3));
        
        if ($result['class'] === 'FAIL') {
            $failure = $test->addChild('failure');
            $failure->addAttribute('type', $result['type'] ?? 'assertion');
            $failure->addAttribute('message', $result['message']);
        }
    }
    
    $xml->asXML($output_file);
    echo "\nJUnit XML written to: $output_file\n";
}

/**
 * Run a test function with CI reporting.
 */
function ci_reporter_run(string $test_name, callable $fn): void {
    ci_reporter_init($test_name);
    $fn();
    ci_reporter_final();
}
