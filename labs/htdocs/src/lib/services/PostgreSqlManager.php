<?php

class PostgreSqlManager
{
    private PDO $adminConnection;

    public function __construct()
    {
        $host = gethostname();
        $dsn = "pgsql:host={$host};port=5432";
        $user = get_config('postgres_admin_user') ?: 'tomlabs_admin';
        $password = get_config('mysql_root_pass');
        if (empty($password)) {
            throw new \RuntimeException("mysql_root_pass missing: set via environment variable or env.json");
        }
        
        $this->adminConnection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    public function createUser(string $userName, string $password): bool
    {
        if (!$this->isValidIdentifier($userName) || empty($password)) {
            error_log("Invalid username or empty password for PostgreSqlManager::createUser.");
            return false;
        }

        try {
            // Check if user exists
            $stmt = $this->adminConnection->prepare("SELECT 1 FROM pg_roles WHERE rolname = :username");
            $stmt->execute([':username' => $userName]);
            if ($stmt->fetch()) {
                error_log("PostgreSQL user {$userName} already exists.");
                return false;
            }

            // PostgreSQL doesn't support parameterized DDL, so we quote carefully
            $quotedPassword = $this->adminConnection->quote($password);
            $this->adminConnection->exec("CREATE ROLE \"{$userName}\" WITH LOGIN PASSWORD {$quotedPassword}");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to create PostgreSQL User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteUser(string $userName): bool
    {
        if (!$this->isValidIdentifier($userName)) {
            return false;
        }
        
        try {
            // Revoke connections first
            $this->adminConnection->exec("REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM \"{$userName}\"");
            $this->adminConnection->exec("DROP ROLE IF EXISTS \"{$userName}\"");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to drop PostgreSQL User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function createDatabase(string $dbName, string $ownerName): bool
    {
        if (!$this->isValidIdentifier($dbName) || !$this->isValidIdentifier($ownerName)) {
            return false;
        }
        
        try {
            // Check if DB exists
            $stmt = $this->adminConnection->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
            $stmt->execute([':dbname' => $dbName]);
            if ($stmt->fetch()) {
                error_log("PostgreSQL Database {$dbName} already exists.");
                return false;
            }

            // Check if owner role exists
            $stmtUser = $this->adminConnection->prepare("SELECT 1 FROM pg_roles WHERE rolname = :username");
            $stmtUser->execute([':username' => $ownerName]);
            if (!$stmtUser->fetch()) {
                error_log("Owner role {$ownerName} does not exist in PostgreSQL.");
                return false;
            }

            $this->adminConnection->exec("CREATE DATABASE \"{$dbName}\" OWNER \"{$ownerName}\"");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to create PostgreSQL DB '{$dbName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteDatabase(string $dbName): bool
    {
        if (!$this->isValidIdentifier($dbName)) {
            return false;
        }
        
        try {
            // Terminate active connections to the database before dropping
            $stmt = $this->adminConnection->prepare("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :dbname AND pid <> pg_backend_pid()");
            $stmt->execute([':dbname' => $dbName]);
            
            $this->adminConnection->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to drop PostgreSQL DB '{$dbName}': " . $e->getMessage());
            return false;
        }
    }

    private function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && strlen($name) <= 63;
    }

    public function getEncodings(): array
    {
        try {
            $stmt = $this->adminConnection->query("SELECT pg_encoding_to_char(conforencoding) AS encoding FROM pg_conversion GROUP BY conforencoding ORDER BY encoding");
            $results = $stmt->fetchAll();
            return array_column($results, 'encoding');
        } catch (PDOException $e) {
            error_log("Failed to get PostgreSQL encodings: " . $e->getMessage());
            return ['UTF8'];
        }
    }
}
