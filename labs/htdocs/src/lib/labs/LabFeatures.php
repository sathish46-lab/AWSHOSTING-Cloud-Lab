<?php
namespace TomLabs\Labs;

/**
 * LabFeatures
 *
 * Single source of truth for which features are enabled per lab type.
 *
 * How to use:
 *   - To add a new lab type: add a new entry to LAB_FEATURES below.
 *   - To disable a feature globally for ALL labs: set the master kill switch
 *     in Constants.class.php (e.g. FEATURE_HTTP_PROXIES = false).
 *   - To disable a feature for ONE lab only: remove it from that lab's array here.
 *
 * Available features:
 *   always_on       — Keep instance running permanently (no auto-expire)
 *   http_proxies    — Reverse-proxy ports to custom domains over HTTP
 *   startup_script  — Run a custom init.sh on every (re)deploy
 *   vnc_password    — Show VNC Password field in Preferences (gui_essentials)
 */
class LabFeatures {

    /**
     * Fallback per-lab supported feature list if not found in DB.
     */
    private const FALLBACK_LAB_FEATURES = [
        'essentials' => ['always_on', 'http_proxies', 'startup_script', 'expose_web'],
        'minio'      => ['always_on', 'startup_script'],
        'n8n'        => ['always_on', 'startup_script'],
        'docker_lab' => ['always_on', 'startup_script'],
        'gui_essentials' => ['always_on', 'startup_script', 'vnc_password']
    ];

    private const FALLBACK_DEFAULT = ['always_on'];
    
    // In-memory cache to prevent multiple DB queries per request
    private static $cache = null;

    private static function loadConfig(): void {
        if (self::$cache !== null) return;
        
        self::$cache = [
            'master_switches' => [],
            'global_overrides' => [],
            'lab_matrix' => []
        ];

        try {
            $db = \DatabaseConnection::getDefaultDatabase();
            
            // Load master kill switches
            $masterDoc = $db->global_settings->findOne(['_id' => 'master_switches']);
            self::$cache['master_switches'] = ($masterDoc && is_object($masterDoc) && method_exists($masterDoc, 'getArrayCopy')) ? $masterDoc->getArrayCopy() : ((array)$masterDoc ?: []);

            // Load global overrides
            $globalDoc = $db->global_settings->findOne(['_id' => 'lab_features']); // keeping this id for backward compatibility
            self::$cache['global_overrides'] = ($globalDoc && is_object($globalDoc) && method_exists($globalDoc, 'getArrayCopy')) ? $globalDoc->getArrayCopy() : ((array)$globalDoc ?: []);

            // Load lab feature matrix
            $matrixDoc = $db->global_settings->findOne(['_id' => 'lab_feature_matrix']);
            self::$cache['lab_matrix'] = ($matrixDoc && is_object($matrixDoc) && method_exists($matrixDoc, 'getArrayCopy')) ? $matrixDoc->getArrayCopy() : ((array)$matrixDoc ?: []);
            
        } catch (\Exception $e) {
            // Silently fallback to defaults if DB fails
        }
    }

    /**
     * Get the supported feature list for a lab type.
     */
    public static function getSupportedFeatures(string $labType): array {
        self::loadConfig();
        
        // 1. Check DB Lab Matrix
        if (!empty(self::$cache['lab_matrix']) && isset(self::$cache['lab_matrix'][$labType])) {
            $labFeatures = self::$cache['lab_matrix'][$labType];
            return (is_object($labFeatures) && method_exists($labFeatures, 'getArrayCopy')) ? $labFeatures->getArrayCopy() : (array)$labFeatures;
        }

        // 2. Fallback to hardcoded defaults
        return self::FALLBACK_LAB_FEATURES[$labType] ?? self::FALLBACK_DEFAULT;
    }

    public static function supports(string $labType, string $feature): bool {
        self::loadConfig();

        // 1. Master Kill Switches (DB) - if false, turn off for everyone
        if (isset(self::$cache['master_switches'][$feature]) && self::$cache['master_switches'][$feature] === false) {
            return false;
        }
        
        // Backward compatibility with Constants (if DB is not configured yet)
        if (!isset(self::$cache['master_switches'][$feature])) {
            if ($feature === 'always_on' && defined('\Constants::FEATURE_ALWAYS_ON') && !\Constants::FEATURE_ALWAYS_ON) return false;
            if ($feature === 'http_proxies' && defined('\Constants::FEATURE_HTTP_PROXIES') && !\Constants::FEATURE_HTTP_PROXIES) return false;
            if ($feature === 'startup_script' && defined('\Constants::FEATURE_STARTUP_SCRIPT') && !\Constants::FEATURE_STARTUP_SCRIPT) return false;
        }

        // 2. Check Global DB Overrides (enabled for ALL users/labs)
        if (!empty(self::$cache['global_overrides']) && isset(self::$cache['global_overrides'][$feature]) && self::$cache['global_overrides'][$feature] === true) {
            return true;
        }

        // 3. Check User-Specific Overrides (enabled for this specific user for ALL labs)
        if (class_exists('\Session') && \Session::getAuthStatus() === \Constants::STATUS_LOGGEDIN) {
            $user = \Session::getUser();
            if ($user) {
                $userDoc = $user->getLabFeatures(); // __call maps to lab_features
                $userFeatures = ($userDoc && is_object($userDoc) && method_exists($userDoc, 'getArrayCopy')) ? $userDoc->getArrayCopy() : ((array)$userDoc ?: []);
                
                if (!empty($userFeatures) && isset($userFeatures[$feature]) && $userFeatures[$feature] === true) {
                    return true;
                }
            }
        }

        // 4. Per-lab config map (from DB or fallback)
        return in_array($feature, self::getSupportedFeatures($labType), true);
    }
}
