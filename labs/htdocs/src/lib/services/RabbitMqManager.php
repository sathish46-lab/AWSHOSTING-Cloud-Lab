<?php

class RabbitMqManager
{
    private string $adminUser = '';
    private string $adminPassword = '';
    private string $host;

    public function __construct()
    {
        $this->host = gethostname();
        $this->adminUser = get_config('amqp_user') ?: 'admin';
        $this->adminPassword = get_config('amqp_pass') ?: '';
        if (empty($this->adminPassword)) {
            throw new \RuntimeException("amqp_pass missing in env.json");
        }
    }
    
    public function createUser(string $userName, string $password): bool
    {
        if (!$this->isValidIdentifier($userName) || empty($password)) {
            error_log("Invalid username or empty password for RabbitMqManager::createUser.");
            return false;
        }

        try {
            // Check if user exists
            $output = $this->execRabbitmqctl("list_users --formatter json");
            $users = json_decode($output, true);
            if (is_array($users)) {
                foreach ($users as $u) {
                    if (($u['user'] ?? '') === $userName) {
                        error_log("RabbitMQ user {$userName} already exists.");
                        return false;
                    }
                }
            }

            // Create the user
            $escapedUser = escapeshellarg($userName);
            $escapedPass = escapeshellarg($password);
            $this->execRabbitmqctl("add_user {$escapedUser} {$escapedPass}");
            
            // Set management tag so they can access the management UI
            $this->execRabbitmqctl("set_user_tags {$escapedUser} management");
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to create RabbitMQ User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteUser(string $userName): bool
    {
        if (!$this->isValidIdentifier($userName)) {
            return false;
        }
        
        // Protect admin user
        if ($userName === 'admin' || $userName === 'guest') {
            error_log("Cannot delete protected RabbitMQ user: {$userName}");
            return false;
        }
        
        try {
            $escapedUser = escapeshellarg($userName);
            $this->execRabbitmqctl("delete_user {$escapedUser}");
            return true;
        } catch (\Exception $e) {
            error_log("Failed to delete RabbitMQ User '{$userName}': " . $e->getMessage());
            return false;
        }
    }

    public function createVhost(string $vhostName, string $ownerName): bool
    {
        if (!$this->isValidVhostName($vhostName) || !$this->isValidIdentifier($ownerName)) {
            return false;
        }
        
        try {
            // Check if vhost exists
            $output = $this->execRabbitmqctl("list_vhosts --formatter json");
            $vhosts = json_decode($output, true);
            if (is_array($vhosts)) {
                foreach ($vhosts as $v) {
                    if (($v['name'] ?? '') === $vhostName) {
                        error_log("RabbitMQ vhost {$vhostName} already exists.");
                        return false;
                    }
                }
            }

            $escapedVhost = escapeshellarg($vhostName);
            $escapedUser = escapeshellarg($ownerName);
            
            // Create vhost
            $this->execRabbitmqctl("add_vhost {$escapedVhost}");
            
            // Grant full permissions to the owner
            $this->execRabbitmqctl("set_permissions -p {$escapedVhost} {$escapedUser} '.*' '.*' '.*'");
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to create RabbitMQ vhost '{$vhostName}': " . $e->getMessage());
            return false;
        }
    }

    public function deleteVhost(string $vhostName): bool
    {
        if (!$this->isValidVhostName($vhostName)) {
            return false;
        }
        
        // Protect default vhost
        if ($vhostName === '/') {
            error_log("Cannot delete the default RabbitMQ vhost.");
            return false;
        }
        
        try {
            $escapedVhost = escapeshellarg($vhostName);
            $this->execRabbitmqctl("delete_vhost {$escapedVhost}");
            return true;
        } catch (\Exception $e) {
            error_log("Failed to delete RabbitMQ vhost '{$vhostName}': " . $e->getMessage());
            return false;
        }
    }

    private function execRabbitmqctl(string $command): string
    {
        $output = [];
        $returnCode = 0;
        exec("rabbitmqctl {$command} 2>&1", $output, $returnCode);
        
        $result = implode("\n", $output);
        if ($returnCode !== 0) {
            throw new \RuntimeException("rabbitmqctl failed: {$result}");
        }
        return $result;
    }

    private function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) && strlen($name) <= 64;
    }

    private function isValidVhostName(string $name): bool
    {
        // Vhost names can contain alphanumeric, underscores, hyphens, and dots
        return (bool) preg_match('/^[a-zA-Z0-9_.\-]+$/', $name) && strlen($name) <= 64;
    }
}
