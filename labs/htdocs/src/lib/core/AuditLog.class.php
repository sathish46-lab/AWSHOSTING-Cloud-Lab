<?php
/**
 * Audit Log — Write-through audit trail for all mutating operations.
 * Stores who did what, when, and from where.
 */
class AuditLog {
    
    /**
     * Log an audit event.
     * 
     * @param string $action Action performed (create, update, delete, login, logout, etc.)
     * @param string $entityType Entity type (user, instance, service_mysql, vpn_device, etc.)
     * @param string|null $entityId Entity identifier (user_id, instance_hash, etc.)
     * @param array $details Additional details about the operation
     * @param string|null $userId User who performed the action (auto-detected if null)
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $details = [],
        ?string $userId = null
    ): void {
        try {
            // Auto-detect user if not provided
            if ($userId === null && class_exists('Session')) {
                $user = Session::getUser();
                if ($user && method_exists($user, 'getUserId')) {
                    $userId = (string) $user->getUserId();
                } elseif (!empty($_SESSION['user_id'])) {
                    $userId = (string) $_SESSION['user_id'];
                } elseif (!empty($_SESSION['username'])) {
                    $userId = $_SESSION['username'];
                }
            }
            
            $entry = [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_id' => $userId,
                'ip_address' => self::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'details' => $details,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ];
            
            $db = DatabaseConnection::getDefaultDatabase();
            $db->audit_log->insertOne($entry);
            
        } catch (Exception $e) {
            // Audit logging should never break the application
            error_log("AUDIT LOG ERROR: " . $e->getMessage());
        }
    }
    
    /**
     * Get client IP address (supports proxies).
     */
    private static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Query audit log entries.
     * 
     * @param array $filter MongoDB filter
     * @param int $limit Max results
     * @param int $skip Offset
     * @return array
     */
    public static function query(array $filter = [], int $limit = 100, int $skip = 0): array {
        try {
            $db = DatabaseConnection::getDefaultDatabase();
            $cursor = $db->audit_log->find($filter)
                ->sort(['created_at' => -1])
                ->skip($skip)
                ->limit($limit);
            return iterator_to_array($cursor);
        } catch (Exception $e) {
            error_log("AUDIT LOG QUERY ERROR: " . $e->getMessage());
            return [];
        }
    }
}
