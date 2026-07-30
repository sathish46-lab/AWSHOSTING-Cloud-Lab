<?php
class DomainManager {
    private $db;
    private $config;
    
    public function __construct() {
        $this->db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
        $this->loadConfig();
    }
    
    /**
     * Load configuration from config file
     */
    private function loadConfig() {
        $configPath = __DIR__ . '/../../config/available_domains.php';
        
        if (file_exists($configPath)) {
            $this->config = require $configPath;
        } else {
            // Fallback config
            $this->config = [
                'server_ip' => \TomLabs\Core\Env::get('SERVER_IP', '106.51.76.75'),
                'domains' => ['*.tomweb.shop']
            ];
        }
    }
    
    /**
     * Get available system domains for user selection
     * Returns array of domain patterns (e.g. ['*.tomweb.shop', '*.example.com'])
     * 
     * Domains are loaded from /src/config/available_domains.php
     * Edit that file to add new domains - no database interaction needed!
     * 
     * @return array List of available domain patterns
     */
    public function getAvailableDomains() {
        return $this->config['domains'] ?? ['*.tomweb.shop'];
    }
    
    /**
     * Get the server IP for A records
     * Loaded from /src/config/available_domains.php
     * 
     * @return string Server IP address
     */
    public function getServerIP() {
        return $this->config['server_ip'] ?? \TomLabs\Core\Env::get('SERVER_IP', '106.51.76.75');
    }
    
    /**
     * Refresh verification status for all custom domains belonging to a user
     * This re-checks DNS and updates the database with current status
     * Only checks custom domains, not Tom domains (which are always trusted)
     * 
     * @param string $user_id User ID to refresh domains for
     * @return int Number of domains refreshed
     */
    public function refreshUserDomains($user_id) {
        // Only check custom domains that might have DNS changes
        $cursor = $this->db->domains->find([
            'user_id' => $user_id,
            'type' => 'custom'
        ]);
        
        $refreshed = 0;
        foreach ($cursor as $domain) {
            try {
                // Re-verify each custom domain
                $this->verifyDomain((string)$domain['_id']);
                $refreshed++;
            } catch (Exception $e) {
                // Continue even if one fails
                error_log("Failed to refresh domain {$domain['domain']}: " . $e->getMessage());
            }
        }
        
        return $refreshed;
    }
    
    /**
     * Check if domain's A record points to our server
     * Uses multiple verification methods for reliability
     */
    public function checkARecord($domain) {
        // Clean the domain
        $domain = strtolower(trim($domain));
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        
        // Remove trailing dots
        $domain = rtrim($domain, '.');
        
        // Validate domain format
        if (!$this->isValidDomain($domain)) {
            return false;
        }
        
        // Method 1: Try gethostbyname (with validation)
        $ip = @gethostbyname($domain);
        
        // gethostbyname returns the domain itself if lookup fails
        if ($ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP)) {
            if ($ip === $this->getServerIP()) {
                return true;
            }
        }
        
        // Method 2: PHP dns_get_record (with error suppression)
        $records = @dns_get_record($domain, DNS_A);
        if ($records && is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && $record['ip'] === $this->getServerIP()) {
                    return true;
                }
            }
        }
        
        // Method 3: Use external DNS resolver (Google DNS 8.8.8.8)
        // This bypasses local DNS cache
        $external_check = $this->checkDNSViaExternal($domain);
        if ($external_check === $this->getServerIP()) {
            return true;
        }
        
        // Method 4: Also check www subdomain
        $www_records = @dns_get_record("www.$domain", DNS_A);
        if ($www_records && is_array($www_records)) {
            foreach ($www_records as $record) {
                if (isset($record['ip']) && $record['ip'] === $this->getServerIP()) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check DNS using external resolver (Google DNS)
     * More reliable than local DNS
     */
    private function checkDNSViaExternal($domain) {
        // Use Google's DNS-over-HTTPS API
        $url = "https://dns.google/resolve?name=" . urlencode($domain) . "&type=A";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json']
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $response) {
            $data = json_decode($response, true);
            
            // Check if there are A records in the response
            if (isset($data['Answer']) && is_array($data['Answer'])) {
                foreach ($data['Answer'] as $answer) {
                    if ($answer['type'] === 1 && isset($answer['data'])) {
                        // Type 1 = A record
                        return $answer['data'];
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Validate domain name format
     */
    private function isValidDomain($domain) {
        // Basic domain validation
        $pattern = '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.[A-Za-z0-9-]{1,63})+$/';
        return preg_match($pattern, $domain) === 1;
    }
    
    /**
     * Verify domain and update status
     */
    public function verifyDomain($domain_id) {
        $domain_doc = $this->db->domains->findOne(['_id' => new MongoDB\BSON\ObjectId($domain_id)]);
        
        if (!$domain_doc) {
            throw new Exception("Domain not found");
        }
        
        $is_verified = $this->checkARecord($domain_doc['domain']);
        
        $this->db->domains->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($domain_id)],
            ['$set' => [
                'verified' => $is_verified,
                'last_checked' => time(),
                'last_ip' => $is_verified ? $this->getServerIP() : null
            ]]
        );
        
        return $is_verified;
    }
    
    /**
     * Add domain to database
     * Updated to accept email directly
     */
    public function addDomain($user_id, $email, $domain, $type) {
        // Clean the domain
        $domain = strtolower(trim($domain));
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        
        // Validate
        if (!$this->isValidDomain($domain)) {
            throw new Exception("Invalid domain format");
        }
        
        // Check if already exists
        if ($this->db->domains->findOne(['domain' => $domain])) {
            throw new Exception("Domain already registered");
        }
        
        // Verify A record for custom domains
        $is_verified = ($type === 'curated' or $type === 'tom') ? true : $this->checkARecord($domain);
        
        return $this->db->domains->insertOne([
            'user_id' => $user_id,
            'email' => $email,
            'domain' => $domain,
            'type' => $type,
            'verified' => $is_verified,
            'in_use' => false,
            'created_at' => time(),
            'last_checked' => time(),
            'last_ip' => $is_verified ? $this->getServerIP() : null
        ]);
    }
    
    /**
     * Get domain usage map for ALL user labs
     * Returns array mapping domain => usage info
     * This is a REUSABLE method - call from anywhere
     * 
     * @param string $user_id User ID to check
     * @return array Domain usage map [domain => ['usage' => 'VS Code Web', 'lab_type' => 'essentials', 'instance_hash' => 'xxx']]
     */
    public function getDomainUsageMap($user_id) {
        $usageMap = [];
        // CRITICAL: Cast to integer for MongoDB query (DB stores as int, Session returns string)
        // Try top-level user_id first, fallback to deploy.user_id
        $allUserLabs = $this->db->machine_labs->find(['user_id' => (int)$user_id]);
        $allUserLabs = iterator_to_array($allUserLabs);
        if (empty($allUserLabs)) {
            $allUserLabs = $this->db->machine_labs->find(['deploy.user_id' => (int)$user_id]);
        }
        
        foreach($allUserLabs as $inst) {
            $lab = $inst['deploy'] ?? [];
            $status = $lab['status'] ?? 'offline';

            $labType = $lab['lab_type'] ?? 'unknown';
            $instanceHash = $lab['instance_hash'] ?? '';
            
            // GENERIC: Check ALL possible domain fields dynamically
            // This works for ANY lab type without hardcoding
            
            // 1. VS Code domain (field: code_domain)
            if (isset($lab['code_domain']) && !empty($lab['code_domain'])) {
                $domain = $lab['code_domain'];
                // Track ALL domains (including .tomweb.shop subdomains)
                $usageMap[$domain] = [
                    'lab_type' => $labType,
                    'usage' => 'VS Code Web',
                    'instance_hash' => $instanceHash,
                    'status' => $status
                ];
            }
            
            // 2. Credentials-based domains (MinIO and future services)
            // CRITICAL: MongoDB returns credentials as BSONDocument object, not array
            $credentials = $lab['credentials'] ?? null;
            
            if ($credentials && (is_array($credentials) || is_object($credentials))) {
                // MinIO Console
                if (isset($credentials['minio_url_console'])) {
                    $domain = $this->extractDomain($credentials['minio_url_console']);
                    if ($domain) {
                        $usageMap[$domain] = [
                            'lab_type' => $labType,
                            'usage' => 'MinIO Console',
                            'instance_hash' => $instanceHash,
                            'status' => $status
                        ];
                    }
                }
                
                // MinIO API
                if (isset($credentials['minio_url_api'])) {
                    $domain = $this->extractDomain($credentials['minio_url_api']);
                    if ($domain) {
                        $usageMap[$domain] = [
                            'lab_type' => $labType,
                            'usage' => 'MinIO API',
                            'instance_hash' => $instanceHash,
                            'status' => $status
                        ];
                    }
                }
                
                // EXTENSIBLE: Add more credential-based services here
                // Just follow the same pattern - works for any future lab type
            }
            
            // 3. Public exposure domains (field: domains[])
            // CRITICAL: MongoDB returns domains as BSONArray object
            $publicDomains = $lab['domains'] ?? null;
            if ($publicDomains && (is_array($publicDomains) || is_object($publicDomains))) {
                foreach($publicDomains as $domain) {
                    if (!empty($domain) && !isset($usageMap[$domain])) {
                        $usageMap[$domain] = [
                            'lab_type' => $labType,
                            'usage' => 'Public Exposure',
                            'instance_hash' => $instanceHash,
                            'status' => $status
                        ];
                    }
                }
            }
            // 4. HTTP Proxies
            $httpProxies = $lab['http_proxies'] ?? null;
            if ($httpProxies && (is_array($httpProxies) || is_object($httpProxies))) {
                foreach($httpProxies as $proxy) {
                    // Convert BSONDocument to array if needed
                    if (is_object($proxy) && method_exists($proxy, 'getArrayCopy')) {
                        $proxy = $proxy->getArrayCopy();
                    }
                    $domain = $proxy['domain'] ?? null;
                    if (!empty($domain) && !isset($usageMap[$domain])) {
                        $usageMap[$domain] = [
                            'lab_type' => $labType,
                            'usage' => 'HTTP Proxy (Port ' . ($proxy['port'] ?? '?') . ')',
                            'instance_hash' => $instanceHash,
                            'status' => $status
                        ];
                    }
                }
            }
        }
        
        return $usageMap;
    }
    
    /**
     * Get usage info for a specific domain
     * Returns null if domain is not in use
     * 
     * @param string $user_id User ID to check
     * @param string $domain Domain to check
     * @return array|null Usage info or null if not in use
     */
    public function getDomainUsage($user_id, $domain) {
        // Type casting handled in getDomainUsageMap
        $usageMap = $this->getDomainUsageMap($user_id);
        return $usageMap[$domain] ?? null;
    }
    
    /**
     * Helper: Extract clean domain from URL
     * @param string $url URL or domain
     * @return string|null Clean domain
     */
    private function extractDomain($url) {
        if (empty($url)) return null;
        $domain = str_replace(['https://', 'http://'], '', $url);
        $domain = rtrim($domain, '/');
        $domain = explode('/', $domain)[0]; // Remove path if any
        return $domain;
    }
}