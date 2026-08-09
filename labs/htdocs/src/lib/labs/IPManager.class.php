<?php
namespace TomLabs\Labs;

use Exception;
use DatabaseConnection;
use MongoDB\Operation\FindOneAndUpdate;

class IPManager {
    private $db;
    private $collection;
    protected $ip_prefix;

    public function __construct() {
        $this->ip_prefix = get_config('tunnel_ip');
        $this->db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
        $this->collection = $this->db->ip_registry;
    }

    /**
     * Reserve an IP for a user
     * Works for devices, labs, instances — anything
     */
    public function reserve($email, $userId = null, $selectedIp = null) {
        if ($selectedIp) {
            // User selected a specific IP - allow if available OR already reserved by same user
            // Never allow .0 (network) or .1 (server gateway)
            $lastOctet = (int) substr(strrchr($selectedIp, '.'), 1);
            if ($lastOctet <= 1) {
                throw new Exception("IP $selectedIp is reserved (server/network address)");
            }
            $result = $this->collection->findOneAndUpdate(
                ['ip_addr' => $selectedIp, '$or' => [
                    ['status' => 'available'],
                    ['status' => 'reserved', 'email' => $email]
                ]],
                ['$set' => [
                    'status'     => 'reserved',
                    'user_id'    => $userId,
                    'email'      => $email,
                    'reserved_to'=> $email,
                    'reserved_at'=> time(),
                ]],
                ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
        } else {
            // Auto-assign next available — skip .0 and .1 (reserved for server)
            $result = $this->collection->findOneAndUpdate(
                ['status' => 'available', 'ip_numeric' => ['$gt' => 1]],
                ['$set' => [
                    'status'     => 'reserved',
                    'user_id'    => $userId,
                    'email'      => $email,
                    'reserved_to'=> $email,
                    'reserved_at'=> time(),
                ]],
                [
                    'sort' => ['ip_numeric' => 1],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
            );
        }

        if (!$result) {
            throw new Exception("No available IPs in pool!");
        }

        return $result['ip_addr'];
    }

    /**
     * Release an IP back to the pool
     */
    public function release($ip, $email = null) {
        $match = ['ip_addr' => $ip, 'status' => 'reserved'];
        if ($email) {
            $match['email'] = $email;
        }
        $result = $this->collection->updateOne(
            $match,
            ['$set' => ['status' => 'available'], '$unset' => [
                'user_id' => '', 'email' => '', 'reserved_to' => '', 'reserved_at' => '',
                'service_type' => '', 'label' => '', 'last_deploy' => '', 'allocated_to' => '',
                'resource_type' => '', 'resource_id' => '', 'device_name' => '', 'device_type' => '',
            ]]
        );
        error_log("IPManager: Released IP $ip (matched={$result->getMatchedCount()})");
        return $result;
    }

    /**
     * Get all IPs reserved by a user
     */
    public function getUserIPs($email) {
        return $this->collection->find(['email' => $email, 'status' => 'reserved'])->toArray();
    }

    /**
     * Initialize IP pool (range: .2 to .65534)
     * Skips .0 (network address) and .1 (server gateway)
     */
    public function initializePool($start = 2, $end = 65534) {
        $this->collection->deleteMany([]);
        
        $bulk = [];
        for ($i = $start; $i <= $end; $i++) {
            $bulk[] = [
                'ip_addr'    => $this->ip_prefix . $i,
                'ip_numeric' => $i,
                'status'     => 'available',
                'user_id'    => null,
                'email'      => null,
                'reserved_to'=> null,
                'reserved_at'=> null,
            ];
        }
        
        // Reserve .1 for server (WireGuard gateway)
        $bulk[] = [
            'ip_addr'    => $this->ip_prefix . '1',
            'ip_numeric' => 1,
            'status'     => 'reserved',
            'user_id'    => 0,
            'email'      => 'system@tomweb.in',
            'reserved_to'=> 'server',
            'label'      => 'WireGuard Server (wg0)',
            'reserved_at'=> time(),
        ];
        
        if (!empty($bulk)) {
            $this->collection->insertMany($bulk);
            error_log("IPManager: Initialized IP pool with " . count($bulk) . " addresses (.2-.65534 available, .1 reserved for server)");
        }
    }

    /**
     * Get stats
     */
    public function getStats() {
        $total = $this->collection->countDocuments([]);
        $reserved = $this->collection->countDocuments(['status' => 'reserved']);
        return [
            'total'     => $total,
            'reserved'  => $reserved,
            'available' => $total - $reserved,
        ];
    }
}
