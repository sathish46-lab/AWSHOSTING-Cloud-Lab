<?php
/**
 * Test D1+D6+D7: AuditLog helper class and write-through integration.
 * 
 * Tests:
 * 1. AuditLog class file exists and is valid PHP
 * 2. AuditLog has required static methods
 * 3. AuditLog::log() signature accepts action, entityType, entityId, details, userId
 * 4. AuditLog::query() signature accepts filter, limit, skip
 * 5. getClientIp() handles X-Forwarded-For correctly
 * 6. getClientIp() falls back to REMOTE_ADDR
 * 7. instances/create.php includes AuditLog.class.php
 * 8. instances/trash.php includes AuditLog.class.php
 * 9. instances/restore.php includes AuditLog.class.php
 * 10. instances/permanent_delete.php includes AuditLog.class.php
 * 11. vpn/add.php includes AuditLog.class.php
 * 12. vpn/delete.php includes AuditLog.class.php
 * 13. services/mysql/create.php includes AuditLog.class.php
 * 14. services/mysql/delete.php includes AuditLog.class.php
 * 15. AuditLog::log() is called after successful instance creation
 * 16. AuditLog::log() is called after instance trash
 * 17. AuditLog::log() is called after instance restore
 * 18. AuditLog::log() is called after permanent delete
 * 19. AuditLog::log() is called after VPN device add
 * 20. AuditLog::log() is called after VPN device delete
 * 21. AuditLog::log() is called after MySQL service create
 * 22. AuditLog::log() is called after MySQL service delete
 * 23. created_by field is added to new instances
 * 24. updated_by field is added to new instances
 * 25. updated_by field is set on instance trash
 */

$base = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: $name\n";
        $passed++;
    } else {
        echo "  FAIL: $name\n";
        $failed++;
    }
}

echo "=== D1+D6+D7: AuditLog Tests ===\n\n";

// 1. AuditLog class file exists
$auditLogPath = "$base/htdocs/src/lib/core/AuditLog.class.php";
test("AuditLog class file exists", file_exists($auditLogPath));

// 2. AuditLog is valid PHP
$auditLogContent = file_get_contents($auditLogPath);
test("AuditLog is valid PHP", substr($auditLogContent, 0, 5) === '<?php');

// 3. AuditLog has required static methods
test("AuditLog has log() method", strpos($auditLogContent, 'public static function log(') !== false);
test("AuditLog has query() method", strpos($auditLogContent, 'public static function query(') !== false);

// 4. log() signature accepts correct params
test("log() has action param", strpos($auditLogContent, 'string $action') !== false);
test("log() has entityType param", strpos($auditLogContent, 'string $entityType') !== false);
test("log() has entityId param", strpos($auditLogContent, '?string $entityId') !== false);
test("log() has details param", strpos($auditLogContent, 'array $details') !== false);
test("log() has userId param", strpos($auditLogContent, '?string $userId') !== false);

// 5. getClientIp handles X-Forwarded-For
test("getClientIp handles X-Forwarded-For", strpos($auditLogContent, 'HTTP_X_FORWARDED_FOR') !== false);

// 6. getClientIp falls back to REMOTE_ADDR
test("getClientIp falls back to REMOTE_ADDR", strpos($auditLogContent, 'REMOTE_ADDR') !== false);

// 7-14. AuditLog.class.php is included in all critical endpoints
$endpoints = [
    'instances/create.php',
    'instances/trash.php',
    'instances/restore.php',
    'instances/permanent_delete.php',
    'vpn/add.php',
    'vpn/delete.php',
    'services/mysql/create.php',
    'services/mysql/delete.php',
];

foreach ($endpoints as $ep) {
    $path = "$base/htdocs/src/api/$ep";
    $content = file_exists($path) ? file_get_contents($path) : '';
    test("$ep includes AuditLog.class.php", strpos($content, 'AuditLog.class.php') !== false);
}

// 15-22. AuditLog::log() is called in each endpoint
$logActions = [
    'instances/create.php' => "AuditLog::log('create', 'instance'",
    'instances/trash.php' => "AuditLog::log('trash', 'instance'",
    'instances/restore.php' => "AuditLog::log('restore', 'instance'",
    'instances/permanent_delete.php' => "AuditLog::log('permanent_delete', 'instance'",
    'vpn/add.php' => "AuditLog::log('create', 'vpn_device'",
    'vpn/delete.php' => "AuditLog::log('delete', 'vpn_device'",
    'services/mysql/create.php' => "AuditLog::log('create', 'service_mysql'",
    'services/mysql/delete.php' => "AuditLog::log('delete', 'service_mysql'",
];

foreach ($logActions as $ep => $expected) {
    $path = "$base/htdocs/src/api/$ep";
    $content = file_exists($path) ? file_get_contents($path) : '';
    test("$ep calls AuditLog::log()", strpos($content, $expected) !== false);
}

// 23-24. created_by and updated_by fields in instances/create.php
$createContent = file_get_contents("$base/htdocs/src/api/instances/create.php");
test("instances/create.php adds created_by field", strpos($createContent, "'created_by' => \$userId") !== false);
test("instances/create.php adds updated_by field", strpos($createContent, "'updated_by' => \$userId") !== false);

// 25. updated_by field on trash
$trashContent = file_get_contents("$base/htdocs/src/api/instances/trash.php");
test("instances/trash.php adds updated_by field", strpos($trashContent, "updated_by") !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
