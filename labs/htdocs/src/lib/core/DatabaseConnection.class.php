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
        
        try {
            self::$client = new MongoDB\Client($uri);
            
            // PROFESSIONAL CONNECTION TEST: 
            // Avoids deprecated Driver constants by using a raw command
            self::$client->selectDatabase('admin')->command(['ping' => 1]);
            
        } catch (Exception $e) {
            error_log("DB CONNECTION ERROR: " . $e->getMessage());
            http_response_code(500);
            die("Critical: Database connection failed. Please check logs.");
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
     * Returns the machine_labs collection (replaces old deployed_labs)
     * All deploy data lives inside machine_labs.deploy subdocument
     */
    public static function getInstancesCollection() {
        return self::getDefaultDatabase()->selectCollection('machine_labs');
    }

    /**
     * Convenience: find a single instance by deploy.instance_hash
     * Returns the flattened deploy data with lab_type merged in
     */
    public static function findInstanceByHash(string $hash): ?array {
        $inst = self::getInstancesCollection()->findOne(
            ['deploy.instance_hash' => $hash],
            ['projection' => ['deploy' => 1, 'lab_type' => 1, 'lab_name' => 1, 'user_id' => 1, 'email' => 1]]
        );
        if (!$inst) return null;
        $data = $inst['deploy'] ?? [];
        $data['lab_type'] = $inst['lab_type'] ?? $data['lab_type'] ?? 'essentials';
        $data['lab_name'] = $inst['lab_name'] ?? $data['lab_name'] ?? '';
        $data['user_id'] = $inst['user_id'] ?? $data['user_id'] ?? null;
        $data['email'] = $inst['email'] ?? $data['email'] ?? null;
        return $data;
    }

    /**
     * Convenience: find instance document by deploy.instance_hash
     * Returns the full machine_labs document (not flattened)
     */
    public static function findInstanceDocByHash(string $hash): ?array {
        return self::getInstancesCollection()->findOne(
            ['deploy.instance_hash' => $hash],
            ['projection' => ['deploy' => 1, 'lab_type' => 1, 'lab_name' => 1, 'user_id' => 1, 'email' => 1]]
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