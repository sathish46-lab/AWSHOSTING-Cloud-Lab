<?php
/**
 * Test D3: Soft delete for service users, databases, VPN devices, SSH keys, domains.
 * 
 * Tests:
 * 1. MySQL service delete uses soft delete (updateOne with status=deleted)
 * 2. MySQL user delete uses soft delete
 * 3. VPN device delete uses soft delete
 * 4. Domain remove uses soft delete
 * 5. SSH key delete uses soft delete
 * 6. MySQL service count excludes deleted records
 * 7. VPN stats excludes deleted devices
 * 8. SSH keys listing excludes deleted keys
 * 9. Device add excludes deleted devices
 * 10. Dashboard domains excludes deleted domains
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

echo "=== D3: Soft Delete Tests ===\n\n";

// 1. MySQL service delete uses soft delete
$mysqlDelete = file_get_contents("$base/htdocs/src/api/services/mysql/delete.php");
test("MySQL service delete uses updateOne (soft delete)", strpos($mysqlDelete, 'updateOne') !== false && strpos($mysqlDelete, "'status' => 'deleted'") !== false);
test("MySQL service delete sets deleted_at", strpos($mysqlDelete, "'deleted_at'") !== false);
test("MySQL service delete sets deleted_by", strpos($mysqlDelete, "'deleted_by'") !== false);

// 2. MySQL user delete uses soft delete
$mysqlUserDelete = file_get_contents("$base/htdocs/src/api/services/mysql/user_delete.php");
test("MySQL user delete uses updateOne (soft delete)", strpos($mysqlUserDelete, 'updateOne') !== false && strpos($mysqlUserDelete, "'status' => 'deleted'") !== false);

// 3. VPN device delete uses soft delete
$vpnDelete = file_get_contents("$base/htdocs/src/api/vpn/delete.php");
test("VPN device delete uses updateOne (soft delete)", strpos($vpnDelete, 'updateOne') !== false && strpos($vpnDelete, "'status' => 'deleted'") !== false);

// 4. Domain remove uses soft delete
$domainRemove = file_get_contents("$base/htdocs/src/api/domain/remove_domain.php");
test("Domain remove uses updateOne (soft delete)", strpos($domainRemove, 'updateOne') !== false && strpos($domainRemove, "'status' => 'deleted'") !== false);

// 5. SSH key delete uses soft delete
$sshDelete = file_get_contents("$base/htdocs/src/api/account/ssh_delete.php");
test("SSH key delete uses updateOne (soft delete)", strpos($sshDelete, 'updateOne') !== false && strpos($sshDelete, "'status' => 'deleted'") !== false);

// 6. MySQL service count excludes deleted
$mysqlCreate = file_get_contents("$base/htdocs/src/api/services/mysql/create.php");
test("MySQL service count excludes deleted records", strpos($mysqlCreate, "'status' => ['\$ne' => 'deleted']") !== false);

// 7. VPN stats excludes deleted devices
$vpnStats = file_get_contents("$base/htdocs/src/api/vpn/stats.php");
test("VPN stats excludes deleted devices", strpos($vpnStats, "'status' => ['\$ne' => 'deleted']") !== false);

// 8. SSH keys listing excludes deleted
$settings = file_get_contents("$base/htdocs/src/api/account/settings.php");
test("SSH keys listing excludes deleted keys", strpos($settings, "'status' => ['\$ne' => 'deleted']") !== false);

// 9. Device add excludes deleted devices
$deviceAdd = file_get_contents("$base/htdocs/src/api/device/add.php");
test("Device add excludes deleted devices", strpos($deviceAdd, "'status' => ['\$ne' => 'deleted']") !== false);

// 10. Dashboard domains excludes deleted
$dashboardStats = file_get_contents("$base/htdocs/src/api/dashboard/stats.php");
test("Dashboard domains excludes deleted domains", strpos($dashboardStats, "'status' => ['\$ne' => 'deleted']") !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
