<?php

class MySqlManager
{
    private PDO $adminConnection;

    public function __construct()
    {
        // Connect to the shared MySQL instance inside the VPS container
        // We use the docker container hostname so that it authenticates against 'root'@'%' 
        // instead of 'root'@'localhost' which might be locked down by auth_socket.
        $host = gethostname();
        $dsn = "mysql:host={$host};port=3306;charset=utf8mb4";
        $user = get_config('mysql_root_user') ?: 'root';
        $password = get_config('mysql_root_pass');
        if (empty($password)) {
            throw new \RuntimeException("mysql_root_pass missing: set via environment variable or env.json");
        }
        
        $this->adminConnection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    /**
     * Creates a new standalone MySQL user.
     */
    public function createUser(string $userName, string $password): bool
    {
        if (!$this->isValidIdentifier($userName) || empty($password)) {
            error_log("Invalid username or empty password for MySqlManager::createUser.");
            return false;
        }

        try {
            // Check if user exists
            $stmt = $this->adminConnection->prepare("SELECT 1 FROM mysql.user WHERE user = :username AND host = '%'");
            $stmt->execute([':username' => $userName]);
            if ($stmt->fetch()) {
                error_log("MySQL user {$userName}@'%' already exists.");
                return false;
            }

            $quotedPassword = $this->adminConnection->quote($password);
            $this->adminConnection->exec("CREATE USER '{$userName}'@'%' IDENTIFIED BY {$quotedPassword}");
                
            $this->adminConnection->exec("FLUSH PRIVILEGES");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to create MySQL User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes a MySQL user.
     */
    public function deleteUser(string $userName): bool
    {
        if (!$this->isValidIdentifier($userName)) {
            return false;
        }
        
        try {
            $this->adminConnection->exec("DROP USER IF EXISTS '{$userName}'@'%'");
            $this->adminConnection->exec("FLUSH PRIVILEGES");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to drop MySQL User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Creates a new MySQL database and grants privileges to the owner.
     */
    public function createDatabase(string $dbName, string $ownerName, string $collation = 'utf8mb4_0900_ai_ci'): bool
    {
        if (!$this->isValidIdentifier($dbName) || !$this->isValidIdentifier($ownerName)) {
            return false;
        }
        
        try {
            // Check if DB exists
            $stmt = $this->adminConnection->prepare("SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
            $stmt->execute([':dbname' => $dbName]);
            if ($stmt->fetch()) {
                error_log("Database {$dbName} already exists.");
                return false;
            }

            // Check if user exists
            $stmtUser = $this->adminConnection->prepare("SELECT 1 FROM mysql.user WHERE user = :username AND host = '%'");
            $stmtUser->execute([':username' => $ownerName]);
            if (!$stmtUser->fetch()) {
                error_log("Owner user {$ownerName}@'%' does not exist.");
                return false;
            }

            $this->adminConnection->exec("CREATE DATABASE `{$dbName}` COLLATE `{$collation}`");
            $this->adminConnection->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$ownerName}'@'%'");
            $this->adminConnection->exec("FLUSH PRIVILEGES");
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to create MySQL DB '{$dbName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes a MySQL database.
     */
    public function deleteDatabase(string $dbName): bool
    {
        if (!$this->isValidIdentifier($dbName)) {
            return false;
        }
        
        try {
            $this->adminConnection->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to drop MySQL DB '{$dbName}': " . $e->getMessage());
            return false;
        }
    }

    private function isValidIdentifier(string $name): bool
    {
        // Basic validation for MySQL identifiers
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && strlen($name) <= 64;
    }

    /**
     * Fetches a list of all available collations grouped by charset from the MySQL server.
     */
    public function getCollations(): array
    {
        try {
            $stmt = $this->adminConnection->query("SHOW COLLATION");
            $results = $stmt->fetchAll();
            $collations = [];
            foreach ($results as $row) {
                $charset = $row['Charset'];
                if (!isset($collations[$charset])) {
                    $collations[$charset] = [];
                }
                $collations[$charset][] = $row['Collation'];
            }
            return $collations;
        } catch (PDOException $e) {
            error_log("Failed to get collations: " . $e->getMessage());
            return ['utf8mb4' => ['utf8mb4_0900_ai_ci']];
        }
    }
}
