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
            // User selected a specific IP
            $result = $this->collection->findOneAndUpdate(
                ['ip_addr' => $selectedIp, 'status' => 'available'],
                ['$set' => [
                    'status'     => 'reserved',
                    'user_id'    => $userId,
                    'email'      => $email,
                    'reserved_at'=> time(),
                ]],
                ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
        } else {
            // Auto-assign next available
            $result = $this->collection->findOneAndUpdate(
                ['status' => 'available'],
                ['$set' => [
                    'status'     => 'reserved',
                    'user_id'    => $userId,
                    'email'      => $email,
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
    public function release($ip, $email) {
        $result = $this->collection->updateOne(
            ['ip_addr' => $ip, 'email' => $email],
            ['$set' => ['status' => 'available'], '$unset' => ['user_id' => '', 'email' => '', 'reserved_at' => '']]
        );
        error_log("IPManager: Released IP $ip");
        return $result;
    }

    /**
     * Get all IPs reserved by a user
     */
    public function getUserIPs($email) {
        return $this->collection->find(['email' => $email, 'status' => 'reserved'])->toArray();
    }

    /**
     * Initialize IP pool (range: .11 to .254)
     */
    public function initializePool($start = 11, $end = 254) {
        $this->collection->deleteMany([]);
        
        $bulk = [];
        for ($i = $start; $i <= $end; $i++) {
            $bulk[] = [
                'ip_addr'    => $this->ip_prefix . $i,
                'ip_numeric' => $i,
                'status'     => 'available',
                'user_id'    => null,
                'email'      => null,
                'reserved_at'=> null,
            ];
        }
        
        if (!empty($bulk)) {
            $this->collection->insertMany($bulk);
            error_log("IPManager: Initialized IP pool with " . count($bulk) . " addresses");
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
