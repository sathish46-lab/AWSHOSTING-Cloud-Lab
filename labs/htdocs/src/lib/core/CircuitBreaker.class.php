<?php
/**
 * Circuit Breaker — Prevents cascading failures by stopping requests to failing services.
 * 
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Too many failures, requests are blocked
 * - HALF_OPEN: After cooldown, allow one probe request
 */
class CircuitBreaker {
    
    private static $states = [];
    private static $failureCounts = [];
    
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';
    
    const FAILURE_THRESHOLD = 5;      // Failures before opening circuit
    const COOLDOWN_SECONDS = 60;      // Seconds before trying again
    const SUCCESS_THRESHOLD = 2;      // Successes to close circuit from half-open
    
    /**
     * Check if a request to the given service is allowed.
     */
    public static function allow(string $service): bool {
        $state = self::getState($service);
        
        switch ($state) {
            case self::STATE_CLOSED:
                return true;
                
            case self::STATE_OPEN:
                $openedAt = self::$states[$service]['opened_at'] ?? 0;
                if (time() - $openedAt >= self::COOLDOWN_SECONDS) {
                    self::$states[$service]['state'] = self::STATE_HALF_OPEN;
                    return true;
                }
                return false;
                
            case self::STATE_HALF_OPEN:
                return true;
                
            default:
                return true;
        }
    }
    
    /**
     * Record a successful request to the service.
     */
    public static function recordSuccess(string $service): void {
        if (!isset(self::$states[$service])) {
            self::$states[$service] = ['state' => self::STATE_CLOSED, 'failures' => 0, 'successes' => 0];
        }
        
        $state = self::$states[$service]['state'] ?? self::STATE_CLOSED;
        
        if ($state === self::STATE_HALF_OPEN) {
            self::$states[$service]['successes'] = (self::$states[$service]['successes'] ?? 0) + 1;
            if (self::$states[$service]['successes'] >= self::SUCCESS_THRESHOLD) {
                self::$states[$service]['state'] = self::STATE_CLOSED;
                self::$states[$service]['failures'] = 0;
                self::$states[$service]['successes'] = 0;
            }
        } else {
            self::$states[$service]['failures'] = 0;
            self::$states[$service]['successes'] = 0;
        }
    }
    
    /**
     * Record a failed request to the service.
     */
    public static function recordFailure(string $service): void {
        if (!isset(self::$states[$service])) {
            self::$states[$service] = ['state' => self::STATE_CLOSED, 'failures' => 0, 'successes' => 0];
        }
        
        self::$states[$service]['failures'] = (self::$states[$service]['failures'] ?? 0) + 1;
        self::$states[$service]['successes'] = 0;
        
        if (self::$states[$service]['failures'] >= self::FAILURE_THRESHOLD) {
            self::$states[$service]['state'] = self::STATE_OPEN;
            self::$states[$service]['opened_at'] = time();
            error_log("Circuit breaker OPENED for service: {$service}");
        }
    }
    
    /**
     * Get current state for a service.
     */
    public static function getState(string $service): string {
        return self::$states[$service]['state'] ?? self::STATE_CLOSED;
    }
    
    /**
     * Get all circuit states (for health check reporting).
     */
    public static function getAllStates(): array {
        $result = [];
        foreach (self::$states as $service => $data) {
            $result[$service] = [
                'state' => $data['state'] ?? self::STATE_CLOSED,
                'failures' => $data['failures'] ?? 0,
                'opened_at' => $data['opened_at'] ?? null,
            ];
        }
        return $result;
    }
}
