<?php
/**
 * Health Check — Periodic health checks for external services.
 * Checks VPN API, MongoDB, MySQL, Redis, RabbitMQ connectivity.
 */
class HealthCheck {
    
    /**
     * Run all health checks and return status.
     */
    public static function checkAll(): array {
        $results = [];
        
        $results['vpn_api'] = self::checkVpnApi();
        $results['mongodb'] = self::checkMongoDB();
        $results['mysql'] = self::checkMysql();
        $results['redis'] = self::checkRedis();
        $results['rabbitmq'] = self::checkRabbitMQ();
        
        $results['overall'] = !in_array('unhealthy', array_column($results, 'status'));
        $results['timestamp'] = time();
        
        return $results;
    }
    
    /**
     * Check VPN API connectivity.
     */
    public static function checkVpnApi(): array {
        try {
            if (!CircuitBreaker::allow('vpn_api')) {
                return ['status' => 'degraded', 'message' => 'Circuit breaker open'];
            }
            
            $response = VPN::request('wg', 'get_peers', ['device' => 'wg0'], 0);
            
            if ($response !== null) {
                CircuitBreaker::recordSuccess('vpn_api');
                return ['status' => 'healthy', 'message' => 'VPN API responding'];
            } else {
                CircuitBreaker::recordFailure('vpn_api');
                return ['status' => 'unhealthy', 'message' => 'VPN API not responding'];
            }
        } catch (Exception $e) {
            CircuitBreaker::recordFailure('vpn_api');
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Check MongoDB connectivity.
     */
    public static function checkMongoDB(): array {
        try {
            $db = DatabaseConnection::getDefaultDatabase();
            $db->command(['ping' => 1]);
            return ['status' => 'healthy', 'message' => 'MongoDB responding'];
        } catch (Exception $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Check MySQL connectivity (if configured).
     */
    public static function checkMysql(): array {
        try {
            $host = get_config('tunnel_ip') . '1';
            $port = 3306;
            
            $sock = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($sock) {
                fclose($sock);
                return ['status' => 'healthy', 'message' => 'MySQL reachable'];
            } else {
                return ['status' => 'unhealthy', 'message' => "MySQL unreachable: {$errstr}"];
            }
        } catch (Exception $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Check Redis connectivity.
     */
    public static function checkRedis(): array {
        try {
            $host = get_config('tunnel_ip') . '1';
            $port = 6379;
            
            $sock = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($sock) {
                fclose($sock);
                return ['status' => 'healthy', 'message' => 'Redis reachable'];
            } else {
                return ['status' => 'unhealthy', 'message' => "Redis unreachable: {$errstr}"];
            }
        } catch (Exception $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Check RabbitMQ connectivity.
     */
    public static function checkRabbitMQ(): array {
        try {
            $host = get_config('tunnel_ip') . '1';
            $port = 5672;
            
            $sock = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($sock) {
                fclose($sock);
                return ['status' => 'healthy', 'message' => 'RabbitMQ reachable'];
            } else {
                return ['status' => 'unhealthy', 'message' => "RabbitMQ unreachable: {$errstr}"];
            }
        } catch (Exception $e) {
            return ['status' => 'unhealthy', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get health check as JSON response.
     */
    public static function jsonResponse(): void {
        header('Content-Type: application/json');
        $results = self::checkAll();
        http_response_code($results['overall'] ? 200 : 503);
        echo json_encode($results, JSON_PRETTY_PRINT);
    }
}
