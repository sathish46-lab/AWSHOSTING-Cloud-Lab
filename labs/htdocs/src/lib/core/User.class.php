<?php
// src/lib/core/User.class.php
class User {
    private $user;
    private $db;

    public function __construct($email) {
        $this->db = DatabaseConnection::getDefaultDatabase();
        $this->user = $this->db->users->findOne(['email' => $email]);
    }

    public function setUiPreference($key, $value) {
        if (!isset($this->user['ui_preferences'])) {
            $this->user['ui_preferences'] = [];
        }
        $this->user['ui_preferences'][$key] = $value;
    }

    public function __call($method, $args) {
        if (substr($method, 0, 3) == "get") {
            // Converts "getUserId" to "user_id"
            $property = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));
            
            // Try snake_case first (e.g., first_name)
            if (isset($this->user[$property])) {
                return $this->user[$property];
            }
            
            // Try raw camelCase-to-lowercase (e.g., username)
            $rawProperty = strtolower(substr($method, 3));
            if (isset($this->user[$rawProperty])) {
                return $this->user[$rawProperty];
            }
        }
        return null;
    }

    public function getUserId() {
        if (isset($this->user['user_id'])) {
            return (int)$this->user['user_id'];
        }
        if (isset($this->user['_id'])) {
            return (string)$this->user['_id'];
        }
        return null;
    }

    public function getId() {
        if (isset($this->user['_id'])) {
            return (string)$this->user['_id'];
        }
        return $this->getUserId();
    }

    public function getFullName() {
        $first = $this->getFirstName();
        $last = $this->getLastName();
        
        if (!$first && !$last) return null;
        return trim(($first ?? '') . ' ' . ($last ?? ''));
    }

    public function getLabHash($labName) {
        $email = $this->getEmail(); 
        $salt = "8b51626f3a468904e8b6f83747f2fcf1"; 
        
        if (!$email) return md5("guest" . $labName . $salt);
        return md5($email . $labName . $salt);
    }

    public function getLabData($labName) {
        $hash = $this->getLabHash($labName);
        $inst = $this->db->instances->findOne(['instance_hash' => $hash]);
        return $inst ? ($inst['deploy'] ?? []) : null;
    }
}