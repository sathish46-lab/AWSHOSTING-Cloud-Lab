<?php
require_once __DIR__ . '/../../../src/load.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']); exit;
}

$user = Session::getUser();
if ($user->getRole() !== 'superuser') {
    echo json_encode(['status' => 'error', 'error' => 'Forbidden']); exit;
}

$scope = $_POST['scope'] ?? 'user'; // 'global', 'master', 'matrix', 'mcp_settings', or 'user'
$email = $_POST['email'] ?? '';
$feature = $_POST['feature'] ?? '';
$setting = $_POST['setting'] ?? '';
$state = (isset($_POST['state']) && $_POST['state'] === 'true'); // boolean

if ($scope !== 'mcp_settings' && !$feature) {
    echo json_encode(['status' => 'error', 'error' => 'Feature missing']); exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();
    
    if ($scope === 'global') {
        // Toggle feature globally in global_settings collection
        $db->global_settings->updateOne(
            ['_id' => 'lab_features'],
            ['$set' => [$feature => $state]],
            ['upsert' => true]
        );
    } elseif ($scope === 'master') {
        $db->global_settings->updateOne(
            ['_id' => 'master_switches'],
            ['$set' => [$feature => $state]],
            ['upsert' => true]
        );
    } elseif ($scope === 'matrix') {
        $lab = $_POST['lab'] ?? '';
        if (!$lab) {
            echo json_encode(['status' => 'error', 'error' => 'Lab missing']); exit;
        }
        
        if ($state) {
            $db->global_settings->updateOne(
                ['_id' => 'lab_feature_matrix'],
                ['$addToSet' => [$lab => $feature]],
                ['upsert' => true]
            );
        } else {
            // First ensure the array is populated from defaults if it doesn't exist
            $doc = $db->global_settings->findOne(['_id' => 'lab_feature_matrix']);
            $matrix = ($doc && is_object($doc) && method_exists($doc, 'getArrayCopy')) ? $doc->getArrayCopy() : ((array)$doc ?: []);
            
            if (!isset($matrix[$lab])) {
                // It was never in the DB, so it's currently using the fallback. 
                // We need to initialize it with the fallback values FIRST, minus the one we are removing.
                $defaults = \TomLabs\Labs\LabFeatures::getSupportedFeatures($lab);
                $newFeatures = array_values(array_diff($defaults, [$feature]));
                
                $db->global_settings->updateOne(
                    ['_id' => 'lab_feature_matrix'],
                    ['$set' => [$lab => $newFeatures]],
                    ['upsert' => true]
                );
            } else {
                // Array exists, we can safely pull
                $db->global_settings->updateOne(
                    ['_id' => 'lab_feature_matrix'],
                    ['$pull' => [$lab => $feature]],
                    ['upsert' => true]
                );
            }
        }
    } elseif ($scope === 'mcp_settings') {
        // MCP-specific settings (admin_only mode)
        if (!$setting) {
            echo json_encode(['status' => 'error', 'error' => 'Setting name missing']); exit;
        }
        $db->global_settings->updateOne(
            ['_id' => 'mcp_settings'],
            ['$set' => [$setting => $state]],
            ['upsert' => true]
        );
    } else {
        // Toggle feature for a specific user
        if (!$email) {
            echo json_encode(['status' => 'error', 'error' => 'User email missing']); exit;
        }
        $db->users->updateOne(
            ['email' => $email],
            ['$set' => ["lab_features.{$feature}" => $state]]
        );
    }

    // Rebuild file cache so changes take effect immediately
    \TomLabs\Labs\LabFeatures::rebuildCache();

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
