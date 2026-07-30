<?php

class MongoDbManager
{
    private $adminClient;
    private $adminDb;

    public function __construct()
    {
        $host = gethostname();
        $mongo_user = get_config('mongo_user') ?: 'admin';
        $mongo_pass = get_config('mongo_pass') ?: '';
        if (empty($mongo_pass)) {
            throw new \RuntimeException("mongo_pass missing in env.json");
        }
        $uri = "mongodb://{$mongo_user}:{$mongo_pass}@{$host}:27017/?authSource=admin";
        
        $this->adminClient = new MongoDB\Client($uri);
        $this->adminDb = $this->adminClient->admin;
    }
    
    public function createUser(string $userName, string $password, string $dbName = 'admin'): bool
    {
        if (!$this->isValidIdentifier($userName) || empty($password)) {
            error_log("Invalid username or empty password for MongoDbManager::createUser.");
            return false;
        }

        try {
            // Check if user exists
            $result = $this->adminDb->command([
                'usersInfo' => ['user' => $userName, 'db' => $dbName]
            ])->toArray();
            
            if (!empty($result[0]->users) && count($result[0]->users) > 0) {
                error_log("MongoDB user {$userName} already exists.");
                return false;
            }

            // Create user with readWrite on their databases
            $this->adminDb->command([
                'createUser' => $userName,
                'pwd' => $password,
                'roles' => [
                    ['role' => 'readWriteAnyDatabase', 'db' => 'admin'],
                    ['role' => 'dbAdminAnyDatabase', 'db' => 'admin']
                ]
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to create MongoDB User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteUser(string $userName): bool
    {
        if (!$this->isValidIdentifier($userName)) {
            return false;
        }
        
        try {
            $this->adminDb->command(['dropUser' => $userName]);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to drop MongoDB User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function createDatabase(string $dbName, string $ownerName): bool
    {
        if (!$this->isValidIdentifier($dbName) || !$this->isValidIdentifier($ownerName)) {
            return false;
        }
        
        try {
            // Check if DB already has collections (exists)
            $db = $this->adminClient->selectDatabase($dbName);
            $collections = iterator_to_array($db->listCollections());
            if (!empty($collections)) {
                error_log("MongoDB Database {$dbName} already exists with collections.");
                return false;
            }

            // Create a bootstrap collection to materialize the database
            $db->createCollection('_init');
            
            // Grant the user specific access to this database
            $this->adminDb->command([
                'grantRolesToUser' => $ownerName,
                'roles' => [
                    ['role' => 'dbOwner', 'db' => $dbName]
                ]
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to create MongoDB DB '{$dbName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteDatabase(string $dbName): bool
    {
        if (!$this->isValidIdentifier($dbName)) {
            return false;
        }
        
        // Protect critical databases
        $protected = ['admin', 'local', 'config', 'tom_labs_db'];
        if (in_array($dbName, $protected)) {
            error_log("Cannot drop protected MongoDB database: {$dbName}");
            return false;
        }
        
        try {
            $this->adminClient->dropDatabase($dbName);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to drop MongoDB DB '{$dbName}': " . $e->getMessage());
            return false;
        }
    }

    private function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && strlen($name) <= 64;
    }
}
