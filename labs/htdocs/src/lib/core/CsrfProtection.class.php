<?php
/**
 * CSRF Protection helper for API routes.
 * Checks X-CSRF-Token header or _csrf_token POST field against session token.
 */
class CsrfProtection {
    
    /**
     * Validate CSRF token from request. Returns true if valid.
     * Checks X-CSRF-Token header first, then _csrf_token POST field.
     */
    public static function validate(): bool {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['_csrf_token']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? null;
        
        if ($token === null) {
            return false;
        }
        
        return Session::validateCsrf($token);
    }
    
    /**
     * Require valid CSRF token. Sends 403 and exits if invalid.
     */
    public static function require(): void {
        if (!self::validate()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'error' => 'Invalid CSRF token']);
            exit;
        }
    }
    
    /**
     * Get the current CSRF token for use in forms/headers.
     */
    public static function token(): string {
        return Session::csrfToken();
    }
}
