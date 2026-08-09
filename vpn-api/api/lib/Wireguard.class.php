<?php
// Fix for CLI/Terminal compatibility
if (!isset($_SERVER['DOCUMENT_ROOT']) or empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/api/lib/Database.class.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/lib/IPNetwork.class.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Carbon\Carbon;

class Wireguard{
    public $db;

    public $device;
    private static $ALLOWED_DEVICES = ['wg0'];

    public function __construct($device){
        if (!in_array($device, self::$ALLOWED_DEVICES)) {
            throw new \InvalidArgumentException("Invalid WireGuard device: " . $device);
        }
        $this->device = $device;
        $this->db = Database::getConnection();
    }

    //TODO: Need to check if the code presensts some data leak threats.
    public function getCIDR() {
        $device = escapeshellarg($this->device);
        $cmd = "sudo cat /etc/wireguard/{$this->device}.conf | head -n 3";
        $line = trim(shell_exec($cmd));
        $lines = explode(PHP_EOL, $line);
        foreach ($lines as $line) {
            $line = explode('=', $line);
            if(trim($line[0]) == "Address"){
                return trim($line[1]);
            }
        }
        
    }

    public function getPeers() {
        $device = escapeshellarg($this->device);
        $cmd = "sudo wg show {$this->device}";
        $output = trim(shell_exec($cmd));
        $result = explode(PHP_EOL, $output);
        $interface_out = array_slice($result, 0, 4);
        $peers_out = array_slice($result, 5);
        $peers = array();
        $interface = array();
        $peer_count = -1;
        foreach($interface_out as $value) {
            $value = trim($value);
            $data = explode(':', $value);
            $interface[trim($data[0])] = trim($data[1]);
        }
        $interface['allowed ips'] = $this->getCIDR();
        
        foreach($peers_out as $value) {
            $value = trim($value);
            if(strlen($value) > 1){
                if(startsWith($value, 'peer')){
                    $peer_count++;
                }
                $data = explode(':', $value);
                $peers[$peer_count][trim($data[0])] = trim($data[1]);
            }
        }
        
        return [
            'interface' => $interface,
            'peers' => $peers
        ];
    }

    public function removePeer($public, $reserved){
        $ipnet = new IPNetwork($this->getCIDR(), $this->device);
        $escaped_public = escapeshellarg($public);
        $escaped_device = escapeshellarg($this->device);
        $cmd = "sudo wg set {$this->device} peer {$escaped_public} remove";
        $result = 0;
        system($cmd, $result);
        system("sudo wg-quick save {$this->device}");
        if($result == 0){
            return $ipnet->unallocateIP($public, $reserved);
        }
    }

    public function addPeer($public, $email, $reserved, $ip=null){
        if(!$this->hasPeer($public)){
            $ipnet = new IPNetwork($this->getCIDR(), $this->device);
            $next_ip = $ipnet->getNextIP($email, $ip);
            $escaped_public = escapeshellarg($public);
            $escaped_next_ip = escapeshellarg("{$next_ip}/32");
            $cmd = "sudo wg set {$this->device} peer {$escaped_public} allowed-ips {$escaped_next_ip}";
            system($cmd, $result);
            system("sudo wg-quick save {$this->device}", $result1);
            if($result == 0 and $result1 == 0){
                return $ipnet->allocateIP($next_ip, $email, $public, boolval($reserved));
            } else {
                return false;
            }
        } else {
            throw new Exception("Peer already exists");
        }
    }

    public function hasPeer($public){
        return count($this->getPeer($public)) >= 1;
    }

    public function getPeer($public){
        $escaped_public = escapeshellarg($public);
        $cmd = "sudo wg show {$this->device} | grep -A4 {$escaped_public}";
        $output = trim(shell_exec($cmd));
        $result = explode(PHP_EOL, $output);
        $peer = array();
        $peer_count = 0;
        foreach($result as $value){
            if(!empty($value)){
                $value = trim($value);
                if(startsWith($value, 'peer:')){
                    $peer_count++;
                    if($peer_count >= 2){
                        break;
                    }
                }
                $data = explode(': ', $value);
                $peer[$data[0]] = $data[1];
            }
        }
        return $peer;
    }

    public function reserve($ip, $email){
        $ipnet = new IPNetwork($this->getCIDR(), $this->device);
        return $ipnet->reserveIP($email, $ip, true);
    }

    public function unreserve($ip, $email){
        $ipnet = new IPNetwork($this->getCIDR(), $this->device);
        // This now triggers the 'reserve=false' logic that clears the email
        return $ipnet->reserveIP($email, $ip, false); 
    }
    // Add this inside the Wireguard class
    public function getAllPeersByEmail($email) {
        $ipnet = new IPNetwork($this->getCIDR(), $this->device);
        // Fetch all documents from the 'networks' collection
        $all = $ipnet->getAll(); 
        
        // Filter specifically for the logged-in user's email
        return array_filter($all, function($item) use ($email) {
            return isset($item['email']) && $item['email'] === $email;
        });
    }

    /**
     * Initialize VPN service - no longer needs network sync.
     * IP allocation now uses ip_registry directly.
     */
    public function initialize($token = '09b693f6-e6b4-4cdc-a7c6-14a337fae61d') {
        return true;
    }
    
}