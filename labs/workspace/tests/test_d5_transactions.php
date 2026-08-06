<?php
/**
 * Test D5: Compensating transactions for trash/restore multi-collection operations.
 * 
 * Tests:
 * 1. trash.php uses compensating transaction pattern (insert then delete)
 * 2. trash.php rolls back trash insert if instance delete fails
 * 3. restore.php uses compensating transaction pattern (insert then delete)
 * 4. restore.php rolls back instances insert if trash delete fails
 * 5. trash.php has rollback logic (deleteOne on instance_trash)
 * 6. restore.php has rollback logic (deleteOne on instances)
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

echo "=== D5: Compensating Transaction Tests ===\n\n";

// Read the files
$trashContent = file_get_contents("$base/htdocs/src/api/instances/trash.php");
$restoreContent = file_get_contents("$base/htdocs/src/api/instances/restore.php");

// 1. trash.php uses compensating transaction pattern
test("trash.php inserts to trash first", strpos($trashContent, 'instance_trash->insertOne') < strpos($trashContent, 'instances->deleteOne'));

// 2. trash.php has rollback logic
test("trash.php has rollback (delete from trash on failure)", strpos($trashContent, 'Rollback') !== false && strpos($trashContent, 'instance_trash->deleteOne') !== false);

// 3. restore.php uses compensating transaction pattern
test("restore.php inserts to instances first", strpos($restoreContent, 'instances->insertOne') < strpos($restoreContent, 'instance_trash->deleteOne'));

// 4. restore.php has rollback logic
test("restore.php has rollback (delete from instances on failure)", strpos($restoreContent, 'Rollback') !== false && strpos($restoreContent, 'instances->deleteOne') !== false);

// 5. Both use insertResult/deleteResult variable tracking
test("trash.php tracks insert result", strpos($trashContent, 'insertResult') !== false);
test("trash.php tracks delete result", strpos($trashContent, 'deleteResult') !== false);
test("restore.php tracks insert result", strpos($restoreContent, 'insertResult') !== false);
test("restore.php tracks delete result", strpos($restoreContent, 'deleteResult') !== false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
