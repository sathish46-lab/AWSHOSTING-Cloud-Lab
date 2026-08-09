<?php

require_once __DIR__ . '/Database.class.php';
require_once __DIR__ . '/../../vendor/autoload.php';

class IPNetwork {
    private $db;
    private $collection;
    public $cidr;
    public $wgdevice;
    
    public function __construct($cidr, $wgdevice){
        $this->cidr = $cidr;
        $this->wgdevice = $wgdevice;
        $this->db = Database::getConnection();
        // Single source of truth: ip_registry in tom_labs_db
        $this->collection = $this->db->ip_registry;
    }

    public function getNextIP($email=null, $ip = null){
        $opt_config = json_decode(@file_get_contents('/opt/labs-control-panel/config.json'), true) ?: [];
        $tunnel_prefix = $opt_config['tunnel_ip'] ?? '172.31.0.';
        $prefix = $tunnel_prefix;

        // Protected IPs: .0 (network) and .1 (gateway/server)
        $protected_ips = [];
        for($i=0; $i<=1; $i++) {
            $protected_ips[] = $prefix . $i;
        }

        $base_query = [
            "status" => "available",
            "ip_addr" => [
                '$regex' => '^' . str_replace('.', '\.', $prefix),
                '$nin' => $protected_ips
            ]
        ];

        if($ip && $email){
            // Try to find if this specific user has this specific IP reserved
            $result = $this->collection->findOne(array_merge($base_query, [
                "status" => "reserved",
                "ip_addr" => $ip,
                'reserved_to' => $email,
            ]), ["sort" => ['ip_numeric' => 1]]);

            if(!$result){
                // If not found, find the first available IP
                $result = $this->collection->findOne($base_query, ["sort" => ['ip_numeric' => 1]]);
            }
        } else {
            // Find the first available IP
            $result = $this->collection->findOne($base_query, ["sort" => ['ip_numeric' => 1]]);
        }

        if (!$result) {
            throw new Exception("No available IPs found in the allowed range");
        }

        return $result['ip_addr'];
    }

    public function allocateIP($ip, $email, $public_key, $reserved){
        try {
            $result = $this->collection->updateOne([
                'ip_addr' => $ip
            ], [
                '$set' => [
                    'status' => 'reserved',
                    'email' => $email,
                    'reserved_to' => $email,
                ]
            ]);
            return $ip;
        } catch (Exception $e) {
            return false;
        }
    }

    public function reserveIP($email, $ip, $reserve=true){
        try {
            if ($reserve) {
                $updateData = ['$set' => [
                    'status' => 'reserved',
                    'reserved_to' => $email,
                    'email' => $email,
                ]];
            } else {
                $updateData = ['$set' => [
                    'status' => 'available',
                    'reserved_to' => null,
                    'email' => null,
                ]];
            }

            $result = $this->collection->updateOne([
                'ip_addr' => $ip
            ], $updateData);
            
            return boolval($result->getModifiedCount());
        } catch (Exception $e) {
            return false;
        }
    }

    public function unallocateIP($public_key, $reserved){
        try {
            // Find the IP by looking up public_key in devices collection
            $devices = $this->db->devices;
            $device = $devices->findOne(['public_key' => $public_key]);
            
            if (!$device) {
                return false;
            }
            
            $ip = $device['assigned_ip'] ?? null;
            if (!$ip) {
                return false;
            }

            if($reserved){
                // Keep reservation but remove allocation
                $result = $this->collection->updateOne([
                    'ip_addr' => $ip
                ], [
                    '$set' => [
                        'status' => 'reserved',
                    ]
                ]);
            } else {
                // FULL RELEASE: Clear everything
                $result = $this->collection->updateOne([
                    'ip_addr' => $ip
                ], [
                    '$set' => [
                        'status' => 'available',
                        'email' => null,
                        'reserved_to' => null,
                    ]
                ]);
            }
            return $result->isAcknowledged();
        } catch (Exception $e) {
            return false;
        }
    }

    public function getAll(){
        return iterator_to_array($this->collection->find([
            'status' => 'reserved',
            'email' => ['$ne' => null, '$exists' => true]
        ]));
    }
}
