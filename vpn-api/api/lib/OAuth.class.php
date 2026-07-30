<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/api/lib/Auth.class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/api/lib/Database.class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/api/lib/User.class.php');
require_once $_SERVER['DOCUMENT_ROOT'].'/api/lib/traits/MongoGetterSetter.trait.php'; 

class OAuth {
    private $db;
    public $collection;
    private $refresh_token = null;
    private $access_token = null;
    private $valid_for = 7200;
    private $username;
    private $user;
    public $data;

    use MongoGetterSetter;

    /**
     * Can construct without refresh token for new session
     * Can construct with refresh token for refresh session
     */
    public function __construct($token = NULL){
        $this->db = Database::getConnection();
        $this->collection = $this->db->session;

        if($token != NULL){
            if($this->startsWith($token, 'a.')){
                $this->access_token = $token;
            } else if($this->startsWith($token, 'r.')){
                $this->refresh_token = $token;
            } else {
                $this->setUsername($token);
            }
        }
    }

    public function setUsername($username){
        $this->username = $username;
        $this->user = new User($this->username);
    }

    public function getUsername(){
        return $this->username;
    }

    public function authenticate(){
        if($this->access_token != null){
            $this->data = $this->collection->findOne(['access_token' => $this->access_token]);
            if($this->data){
                $created_at = $this->data->created_at;
                $expires_at = $created_at + $this->data->valid_for;

                if(time() <= $expires_at){
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    session_regenerate_id(true);
                    $this->username = $_SESSION['username'] = $this->data->username;
                    $_SESSION['token'] = $this->access_token;
                    return true;
                } else {
                   throw new Exception("Expired token");
                }
            } else {
                throw new Exception("Session not found");
            }
        }
    }

    public function newSession($valid_for = 7200, $reference_token = 'auth_grant'){
        if($this->getUsername() == NULL){
            throw new Exception("Username not set for OAuth");
        }
        $this->valid_for = $valid_for;
        $this->access_token = 'a.'.Auth::generateRandomHash(32);
        if($reference_token == 'auth_grant'){
            $this->refresh_token = 'r.'.Auth::generateRandomHash(32);
        } else {
            $this->refresh_token = 'd.'.Auth::generateRandomHash(16);
        }
    
         $this->collection->insertOne(array(
            "username" => $this->getUsername(),
            "access_token" => $this->access_token,
            "refresh_token" => $this->refresh_token,
            "valid_for" => $this->valid_for,
            "reference_token" => $reference_token,
            "created_at" => time()
        )
        );
        
        return array(
            "access_token" => $this->access_token,
            "valid_for" => $this->valid_for,
            "refresh_token" => $this->refresh_token,
            "reference_token" => $reference_token,
            "type" => 'api'
        );
    }

    
    public function refreshAccess(){
        if($this->refresh_token != NULL and !$this->startsWith($this->refresh_token, 'd.')){
            $this->data = $this->collection->findOne(['refresh_token' => $this->refresh_token]);
            if($this->data){
                $this->username = $this->getUsername();
                if($this->isValid()){
                    return $this->newSession(7200, $this->refresh_token);
                } else {
                    throw new Exception("Expired token");
                }
            } else {
                throw new Exception("Session not found");
            }
        } else {
            throw new Exception("Invalid request");
        }
    }

    private function startsWith ($string, $startString){
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }

    private function isValid() {
        $created_at = $this->data->created_at;
        $expires_at = $created_at + $this->data->valid_for;

        return time() <= $expires_at;
    }
}