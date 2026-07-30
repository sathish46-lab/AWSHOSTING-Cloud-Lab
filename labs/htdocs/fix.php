<?php
require_once __DIR__ . "/src/load.php";
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$result = $db->machine_labs->deleteMany(["deploy.lab_type" => ['$exists' => false]]);
echo "Deleted: " . $result->getDeletedCount();
