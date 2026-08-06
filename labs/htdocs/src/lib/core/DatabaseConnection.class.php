<?php

class DatabaseConnection {
    public static $client = null; 
    private static $db = null;

    /**
     * Centralized place for the connection string and client instantiation
     */
    public static function getClient() {
    if (self::$client === null) {
        $uri = get_config('database_file'); 
        
        // T3: Add connection timeout to prevent hanging on unreachable MongoDB
        // Append timeout options to URI if not already present
        if (strpos($uri, 'serverSelectionTimeoutMS') === false) {
            $separator = (strpos($uri, '?') !== false) ? '&' : '?';
            $uri .= $separator . 'serverSelectionTimeoutMS=5000&connectTimeoutMS=5000';
        }
        
        try {
            self::$client = new MongoDB\Client($uri);
            
            // PROFESSIONAL CONNECTION TEST: 
            // Avoids deprecated Driver constants by using a raw command
            self::$client->selectDatabase('admin')->command(['ping' => 1]);
            
        } catch (Exception $e) {
            error_log("DB CONNECTION ERROR: " . $e->getMessage());
            // T3: Return graceful error instead of die()
            if (php_sapi_name() !== 'cli') {
                http_response_code(503);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'error' => 'Database connection failed. Please try again later.']);
            }
            exit;
        }
    }
    return self::$client;
}

    /**
     * The primary method to get the database instance
     */
    public static function getDefaultDatabase() {
        if (self::$db === null) {
            // Pull the database name from the centralized config
            $dbName = get_config('main_db') ?? 'tom_labs_db';
            self::$db = self::getClient()->selectDatabase($dbName);
        }
        return self::$db;
    }
    /**
     * Returns the machine_labs collection
     */
    public static function getInstancesCollection() {
        return self::getDefaultDatabase()->selectCollection('machine_labs');
    }

    /**
     * Convenience: find a single instance by instance_hash
     * Returns the flattened deploy data with lab_type merged in
     */
    public static function findInstanceByHash(string $hash): ?array {
        $inst = self::getInstancesCollection()->findOne(
            ['instance_hash' => $hash],
            ['projection' => ['lab_type' => 1, 'lab_name' => 1, 'user_id' => 1, 'email' => 1, 'internal_ip' => 1, 'credentials' => 1, 'status' => 1, 'code_domain' => 1, 'gui_domain' => 1, 'domains' => 1, 'expose_web' => 1, 'http_proxies' => 1]]
        );
        return $inst ?: null;
    }

    /**
     * Convenience: find instance document by instance_hash
     * Returns the full machine_labs document (not flattened)
     */
    public static function findInstanceDocByHash(string $hash): ?array {
        return self::getInstancesCollection()->findOne(
            ['instance_hash' => $hash],
            ['projection' => ['lab_type' => 1, 'lab_name' => 1, 'user_id' => 1, 'email' => 1, 'internal_ip' => 1, 'credentials' => 1, 'status' => 1, 'code_domain' => 1, 'gui_domain' => 1, 'domains' => 1, 'expose_web' => 1, 'http_proxies' => 1]]
        );
    }

    /**
     * @deprecated Use getInstancesCollection() instead
     */
    public static function getDeploymentsCollection() {
        return self::getInstancesCollection();
    }
    public static function getStatsDatabase() {
        return self::getClient()->selectDatabase('tom_labs_stats_db');
    }
    public static function getPassiveDatabase() {
        return self::getClient()->selectDatabase('tom_labs_passive_db');
    }
    public static function getFilesDatabase() {
        return self::getClient()->selectDatabase('tom_labs_instances_db');
    }
    public static function getNextSequence($name) {
    $db = self::getDefaultDatabase();
    
    // Initialize if it doesn't exist
    $db->counters->updateOne(
        ['_id' => $name],
        ['$setOnInsert' => ['sequence_value' => 1000]],
        ['upsert' => true]
    );

    $result = $db->counters->findOneAndUpdate(
        ['_id' => $name],
        ['$inc' => ['sequence_value' => 1]],
        ['returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    return $result->sequence_value;
}
}