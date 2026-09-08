<?php
/**
 * Structured Logger — JSON-formatted logs with request tracing.
 * 
 * Usage:
 *   Logger::info('User logged in', ['user_id' => 123]);
 *   Logger::error('DB connection failed', ['host' => 'localhost'], $exception);
 *   Logger::critical('Security breach detected', [], $exception);
 * 
 * Output (one JSON object per line):
 *   {"timestamp":"2025-01-15T10:30:00+05:30","level":"info","message":"User logged in","context":{"user_id":123},"request_id":"abc123","ip":"1.2.3.4","uri":"/api/login","method":"POST"}
 */
class Logger {
    
    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    private static ?string $requestId = null;
    private static ?string $logFile = null;
    private static string $minLevel = self::LEVEL_DEBUG;
    
    private static array $levelPriority = [
        self::LEVEL_DEBUG   => 0,
        self::LEVEL_INFO    => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR   => 3,
        self::LEVEL_CRITICAL => 4,
    ];
    
    /**
     * Initialize logger (call once per request).
     */
    public static function init(): void {
        self::$requestId = self::generateRequestId();
        
        // Determine log file from config
        if (class_exists('Session') && method_exists('Session', 'getEnvironment')) {
            $env = Session::getEnvironment();
        } else {
            $env = 'local';
        }
        
        $configLog = '';
        if (function_exists('get_config')) {
            $configLog = get_config('app_log') ?? '';
        }
        
        if (!empty($configLog)) {
            self::$logFile = $configLog;
        } elseif ($env === 'production') {
            self::$logFile = '/var/log/labs/app.log';
        } else {
            self::$logFile = sys_get_temp_dir() . '/labs_app.log';
        }
        
        // Ensure log directory exists
        $dir = dirname(self::$logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    
    /**
     * Get or generate request ID.
     */
    public static function getRequestId(): string {
        if (self::$requestId === null) {
            self::$requestId = self::generateRequestId();
        }
        return self::$requestId;
    }
    
    /**
     * Set minimum log level.
     */
    public static function setMinLevel(string $level): void {
        self::$minLevel = $level;
    }
    
    // --- Convenience methods ---
    
    public static function debug(string $message, array $context = []): void {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }
    
    public static function info(string $message, array $context = []): void {
        self::log(self::LEVEL_INFO, $message, $context);
    }
    
    public static function warning(string $message, array $context = []): void {
        self::log(self::LEVEL_WARNING, $message, $context);
    }
    
    public static function error(string $message, array $context = [], ?Throwable $exception = null): void {
        if ($exception !== null) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }
        self::log(self::LEVEL_ERROR, $message, $context);
    }
    
    public static function critical(string $message, array $context = [], ?Throwable $exception = null): void {
        if ($exception !== null) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    /**
     * Core log method — writes structured JSON.
     */
    public static function log(string $level, string $message, array $context = []): void {
        // Check minimum level
        $currentPriority = self::$levelPriority[$level] ?? 0;
        $minPriority = self::$levelPriority[self::$minLevel] ?? 0;
        if ($currentPriority < $minPriority) {
            return;
        }
        
        // Initialize if needed
        if (self::$logFile === null) {
            self::init();
        }
        
        // Auto-detect user
        $userId = null;
        if (class_exists('Session')) {
            $user = Session::getUser();
            if ($user && method_exists($user, 'getUserId')) {
                $userId = (string) $user->getUserId();
            } elseif (!empty($_SESSION['username'])) {
                $userId = $_SESSION['username'];
            }
        }
        
        $entry = [
            'timestamp' => (new DateTime())->format('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'request_id' => self::getRequestId(),
            'user_id' => $userId,
            'ip' => self::getClientIp(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        ];
        
        // Remove null values for cleaner logs
        $entry = array_filter($entry, fn($v) => $v !== null);
        
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Write to file (atomic append)
        @error_log($json . "\n", 3, self::$logFile);
        
        // Also write to stderr in CLI
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, $json . "\n");
        }
    }
    
    /**
     * Generate a unique request ID for tracing.
     */
    private static function generateRequestId(): string {
        return bin2hex(random_bytes(8));
    }
    
    /**
     * Get client IP address.
     */
    private static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }
}
