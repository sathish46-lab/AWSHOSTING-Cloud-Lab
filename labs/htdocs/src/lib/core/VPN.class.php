<?php

class VPN {
    
    public static function request($namespace, $method, $params = []) {
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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        
        // 3. Add the API Key to the headers for security
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // T1: Add timeouts to prevent hanging on slow/unreachable VPN API
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);  // Connection timeout: 5 seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);         // Total request timeout: 10 seconds
        
        // Disable SSL verification for self-signed certs
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        // error_log("VPN Request: URL: $url, Params: " . json_encode($params) . ", HTTP Code: $httpCode, Curl Error: $curlError, Response: $response");

        curl_close($ch);

        return json_decode($response, true);
    }
}