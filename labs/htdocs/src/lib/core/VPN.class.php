<?php
require_once __DIR__ . '/CircuitBreaker.class.php';

class VPN {
    
    /**
     * Make a request to the VPN API with retry logic and circuit breaker.
     * @param int $maxRetries Maximum number of retry attempts (default: 2, total 3 attempts)
     * @return array|null Decoded JSON response, or null on failure
     */
    public static function request($namespace, $method, $params = [], $maxRetries = 2) {
        // Circuit breaker: check if VPN API is available
        if (!CircuitBreaker::allow('vpn_api')) {
            error_log("VPN API circuit breaker OPEN — request blocked");
            return null;
        }

        // Fetch config from multiple possible locations
        $paths = [
            '/var/www/env.json',
            __DIR__ . '/../../../../env.json', // Relative to htdocs
            __DIR__ . '/../../../../../env.json', // Relative to labs root
            dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/env.json'
        ];

        $config = [];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $config = json_decode(file_get_contents($path), true);
                break;
            }
        }
        
        $api_url = $config['vpn_url'] ?? "https://vpns.tomweb.fun/api";
        $url = $api_url . "/$namespace/$method";
        
        $apiKey = $config['api_secret'] ?? '';

        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            // Exponential backoff: 0s, 1s, 2s
            if ($attempt > 0) {
                sleep(min($attempt, 2));
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-API-KEY: ' . $apiKey,
                'Content-Type: application/x-www-form-urlencoded'
            ]);
            
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            // T1: Timeouts
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            // Disable SSL verification for self-signed certs
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Success: 2xx response
            if ($httpCode >= 200 && $httpCode < 300) {
                CircuitBreaker::recordSuccess('vpn_api');
                return json_decode($response, true);
            }

            // Don't retry on 4xx client errors (except 429 Too Many Requests)
            if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
                error_log("VPN API Client Error: HTTP {$httpCode} on {$url}");
                return json_decode($response, true);
            }

            // Retry on 5xx or connection errors
            $lastError = "HTTP {$httpCode}: {$curlError}";
            error_log("VPN API attempt {$attempt} failed: {$lastError}");
        }

        error_log("VPN API exhausted all retries for {$url}: {$lastError}");
        CircuitBreaker::recordFailure('vpn_api');
        return null;
    }
}
