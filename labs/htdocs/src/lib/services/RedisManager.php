<?php

class RedisManager
{
    private string $host;
    private int $port = 6379;
    private string $adminPassword;

    public function __construct()
    {
        $this->host = gethostname();
        $this->adminPassword = get_config('redis_admin_pass') ?: '';
        if (empty($this->adminPassword)) {
            throw new \RuntimeException("redis_admin_pass missing: set via environment variable or env.json");
        }
    }
    
    public function createUser(string $userName, string $password): bool
    {
        if (!$this->isValidIdentifier($userName) || empty($password)) {
            error_log("Invalid username or empty password for RedisManager::createUser.");
            return false;
        }

        try {
            // Check if user exists via ACL LIST
            $result = $this->execRedisCommand("ACL LIST");
            foreach (explode("\n", $result) as $line) {
                if (strpos($line, "user {$userName} ") !== false) {
                    error_log("Redis user {$userName} already exists.");
                    return false;
                }
            }

            // Create ACL user with full access
            $escapedUser = escapeshellarg($userName);
            $escapedPass = escapeshellarg($password);
            $this->execRedisCommand("ACL SETUSER {$userName} on >{$password} ~* +@all");
            
            // Persist ACL changes
            $this->execRedisCommand("ACL SAVE");
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to create Redis User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteUser(string $userName): bool
    {
        if (!$this->isValidIdentifier($userName)) {
            return false;
        }
        
        // Protect default user
        if ($userName === 'default') {
            error_log("Cannot delete the default Redis user.");
            return false;
        }
        
        try {
            $this->execRedisCommand("ACL DELUSER {$userName}");
            $this->execRedisCommand("ACL SAVE");
            return true;
        } catch (\Exception $e) {
            error_log("Failed to delete Redis User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function getInfo(): array
    {
        try {
            $result = $this->execRedisCommand("INFO server");
            $info = [];
            foreach (explode("\n", $result) as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') continue;
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $info[$parts[0]] = $parts[1];
                }
            }
            return $info;
        } catch (\Exception $e) {
            error_log("Failed to get Redis info: " . $e->getMessage());
            return [];
        }
    }

    private function execRedisCommand(string $command): string
    {
        $escapedHost = escapeshellarg($this->host);
        $escapedPort = escapeshellarg((string)$this->port);
        $escapedAuth = escapeshellarg($this->adminPassword);
        
        $output = [];
        $returnCode = 0;
        exec("redis-cli -h {$escapedHost} -p {$escapedPort} -a {$escapedAuth} --no-auth-warning {$command} 2>&1", $output, $returnCode);
        
        $result = implode("\n", $output);
        if ($returnCode !== 0 && strpos($result, 'ERR') !== false) {
            throw new \RuntimeException("redis-cli failed: {$result}");
        }
        return $result;
    }

    private function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && strlen($name) <= 64;
    }
}
