<?php
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set("memory_limit","256M");
require_once($_SERVER['DOCUMENT_ROOT']."/api/REST.api.php");
require_once($_SERVER['DOCUMENT_ROOT']."/api/lib/Database.class.php");
require_once($_SERVER['DOCUMENT_ROOT']."/api/lib/Signup.class.php");
require_once($_SERVER['DOCUMENT_ROOT']."/api/lib/User.class.php");
require_once($_SERVER['DOCUMENT_ROOT']."/api/lib/Auth.class.php");
require_once($_SERVER['DOCUMENT_ROOT']."/api/lib/Wireguard.class.php");


if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
    $_SERVER['HTTPS'] = 'on';
}

class API extends REST {
    
    public $data = "";
    
    private $db = NULL;
    private $current_call;
    private $auth = null;
    
    public function __construct(){
        parent::__construct();                 
        $this->db = Database::getConnection();
    }
    
    /*
    * Public method for access api.
    * This method dynmically call the method based on the query string
    *
    */
    public function processApi(){
        $func = strtolower(trim(str_replace("/","",$_REQUEST['rquest'])));
        if((int)method_exists($this,$func) > 0){
            $this->$func();
        }
        else {
            if(isset($_GET['namespace'])){
                // Whitelist allowed namespaces to prevent LFI
                $allowedNamespaces = ['wireguard', 'vpn'];
                $namespace = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['namespace']);
                if (!in_array($namespace, $allowedNamespaces)) {
                    $this->response($this->json(['error' => 'invalid_namespace']), 403);
                    return;
                }
                $dir = $_SERVER['DOCUMENT_ROOT'].'/api/apis/'.$namespace;
                $file = $dir.'/'.$func.'.php';
                // Verify resolved path stays within apis directory
                $realDir = realpath($dir);
                $realApisDir = realpath($_SERVER['DOCUMENT_ROOT'].'/api/apis');
                if ($realDir === false || $realApisDir === false || strpos($realDir, $realApisDir) !== 0) {
                    $this->response($this->json(['error' => 'invalid_namespace']), 403);
                    return;
                }
                if(file_exists($file)){
                    include $file;
                    $this->current_call = Closure::bind(${$func}, $this, get_class());
                    $this->$func();
                } else {
                    $this->response($this->json(['error'=>'method_not_found']),404);
                }
            } else {
                //we can even process functions without namespace here.
                $this->response($this->json(['error'=>'method_not_found']),404);
            }
        }
    }

    public function auth(){
        $headers = getallheaders();
        
        if(isset($headers['Authorization'])){
            $token = explode(' ', $headers['Authorization']);
            $this->auth = new Auth($token[1]);
        }
    }

    public function isAuthenticated(){
    $headers = array_change_key_case(getallheaders(), CASE_UPPER);
    $config_json = file_get_contents('/var/www/env.json');
    $config = json_decode($config_json, true);
    
    // 1. Check for X-API-KEY header
    $api_secret = $config['api_secret'] ?? '';
    if (!empty($api_secret) && isset($headers['X-API-KEY']) && $headers['X-API-KEY'] === $api_secret) {
        return true;
    }

    // 2. Fallback to standard OAuth session check
    if($this->auth == null){
        return false;
    }
    return ($this->auth->getOAuth()->authenticate() && isset($_SESSION['username']));
}

    public function getUsername(){
        return $_SESSION['username'];
    }

    public function die($e){
        $data = [
            "error" => $e->getMessage(),
            "type" => "death"
        ];
        $response_code = 400;
        if($e->getMessage() == "Expired token" || $e->getMessage() == "Unauthorized"){
            $response_code = 403;
        }

        if($e->getMessage() == "Not found"){
            $response_code = 404;
        }
        $data = $this->json($data);
        $this->response($data,$response_code);
    }

    public function __call($method, $args){
        if(is_callable($this->current_call)){
            return call_user_func_array($this->current_call, $args);
        } else {
            $this->response($this->json(['error'=>'methood_not_callable']),404);
        }
    }
    
    /*************API SPACE START*******************/
    
    private function test(){
        if (!$this->isAuthenticated()) {
            $this->response($this->json(['error' => 'unauthorized']), 403);
            return;
        }
        $data = $this->json(getallheaders());
        $this->response($data,200);
    }
    
    private function gen_hash(){
        if (!$this->isAuthenticated()) {
            $this->response($this->json(['error' => 'unauthorized']), 403);
            return;
        }
        $st = microtime(true);
        if(isset($this->_request['pass'])){
            $cost = (int)$this->_request['cost'];
            $options = [
                "cost" => $cost
            ];
            $hash = password_hash($this->_request['pass'], PASSWORD_BCRYPT, $options);
            $data = [
                "hash" => $hash,
                "info" => password_get_info($hash),
                "verified" => password_verify($this->_request['pass'], $hash),
                "time_in_ms" => microtime(true) - $st
            ];
            $data = $this->json($data);
            $this->response($data,200);
        }
    }
    
    private function verify_hash(){
        if (!$this->isAuthenticated()) {
            $this->response($this->json(['error' => 'unauthorized']), 403);
            return;
        }
        if(isset($this->_request['pass']) and isset($this->_request['hash'])){
            $hash = $this->_request['hash'];
            $data = [
                "hash" => $hash,
                "info" => password_get_info($hash),
                "verified" => password_verify($this->_request['pass'], $hash),
            ];
            $data = $this->json($data);
            $this->response($data,200);
        }
    }
    /*************API SPACE END*********************/
    
    /*
    Encode array into JSON
    */
    private function json($data){
        if(is_array($data)){
            return json_encode($data, JSON_PRETTY_PRINT);
        } else {
            return "{}";
        }
    }
    
}

function startsWith ($string, $startString){
    $len = strlen($startString);
    return (substr($string, 0, $len) === $startString);
}

// Initiiate Library

$api = new API;
try {
    $api->auth();
    $api->processApi();
} catch (Exception $e){
    $api->die($e);
}

?>
