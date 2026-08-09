<?php

require_once __DIR__ . '/../../vendor/autoload.php';

class Database {
    static $db;
    
    public static function getConnection(){
        // Use an absolute path to the centralized config
        $config_json = file_get_contents('/var/www/env.json');
        $config = json_decode($config_json, true);
        
        if (self::$db != NULL) {
            return self::$db;
        } else {
            try {
                // Use the URI from your env.json
                $mongoClient = new MongoDB\Client($config['database_file']);
                
                // Use the unified labs database (single source of truth for IPs)
                self::$db = $mongoClient->selectDatabase('tom_labs_db');
                return self::$db;
            } catch (Exception $e) {
                error_log("MongoDB Connection failed: " . $e->getMessage());
                http_response_code(503);
                die(json_encode(['error' => 'Database connection failed']));
            }
        }
    }

    public static function getArray($doc){
        return json_decode(json_encode($doc), true);
    }
}