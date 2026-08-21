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
 *   mcp             — MCP Inspector and OAuth endpoints
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

    // When true, loadConfig() skips file cache and reads directly from DB
    private static $forceDbLoad = false;

    /**
     * Load feature config — cache file first (0.001ms), fallback to DB.
     */
    private static function loadConfig(): void {
        if (self::$cache !== null) return;

        // 1. Try file cache first (fast path — no DB needed)
        if (!self::$forceDbLoad && class_exists('\Cache')) {
            $cached = \Cache::get('feature_flags');
            if (is_array($cached) && isset($cached['master_switches'])) {
                $cachedAt = $cached['_cached_at'] ?? 0;
                if ((time() - $cachedAt) < 300) {
                    self::$cache = $cached;
                    return;
                }
            }
        }

        // 2. Load from DB (cache miss, stale, or forced rebuild)
        self::$cache = [
            'master_switches' => [],
            'global_overrides' => [],
            'lab_matrix' => [],
            'mcp_settings' => []
        ];

        try {
            $db = \DatabaseConnection::getDefaultDatabase();

            if ($db) {
                // Load master kill switches
                $masterDoc = $db->global_settings->findOne(['_id' => 'master_switches']);
                self::$cache['master_switches'] = ($masterDoc && is_object($masterDoc) && method_exists($masterDoc, 'getArrayCopy')) ? $masterDoc->getArrayCopy() : ((array)$masterDoc ?: []);

                // Load global overrides
                $globalDoc = $db->global_settings->findOne(['_id' => 'lab_features']);
                self::$cache['global_overrides'] = ($globalDoc && is_object($globalDoc) && method_exists($globalDoc, 'getArrayCopy')) ? $globalDoc->getArrayCopy() : ((array)$globalDoc ?: []);

                // Load lab feature matrix
                $matrixDoc = $db->global_settings->findOne(['_id' => 'lab_feature_matrix']);
                self::$cache['lab_matrix'] = ($matrixDoc && is_object($matrixDoc) && method_exists($matrixDoc, 'getArrayCopy')) ? $matrixDoc->getArrayCopy() : ((array)$matrixDoc ?: []);

                // Load MCP-specific settings
                $mcpDoc = $db->global_settings->findOne(['_id' => 'mcp_settings']);
                self::$cache['mcp_settings'] = ($mcpDoc && is_object($mcpDoc) && method_exists($mcpDoc, 'getArrayCopy')) ? $mcpDoc->getArrayCopy() : ((array)$mcpDoc ?: []);
            }
        } catch (\Exception $e) {
            // Silently fallback to defaults if DB fails
        }

        // 3. Write file cache (with staleness marker)
        if (class_exists('\Cache')) {
            self::$cache['_cached_at'] = time();
            \Cache::set('feature_flags', self::$cache);
        }
    }

    /**
     * Force rebuild the file cache from DB.
     * Called by toggle_feature.php after admin makes changes.
     */
    public static function rebuildCache(): void {
        self::$forceDbLoad = true;
        self::$cache = null;
        self::loadConfig();
        self::$forceDbLoad = false;
    }

    /**
     * Check if MCP is enabled (platform-level, not per-lab).
     * Master switch OFF = disabled for everyone.
     * Global override ON = enabled for everyone.
     * Default = enabled.
     */
    public static function isMcpEnabled(): bool {
        self::loadConfig();

        // 1. Master kill switch — if explicitly false, MCP is off
        if (isset(self::$cache['master_switches']['mcp']) && self::$cache['master_switches']['mcp'] === false) {
            return false;
        }

        // 2. Global override — if explicitly true, MCP is on
        if (isset(self::$cache['global_overrides']['mcp']) && self::$cache['global_overrides']['mcp'] === true) {
            return true;
        }

        // 3. Default: enabled for all authenticated users
        return true;
    }

    /**
     * Check if MCP is restricted to admin users only.
     */
    public static function isMcpAdminOnly(): bool {
        self::loadConfig();
        return !empty(self::$cache['mcp_settings']['admin_only']);
    }

    /**
     * Check if current user can access MCP (master switch + admin-only check).
     * Returns true if access is allowed, false otherwise.
     */
    public static function canAccessMcp(): bool {
        // 1. Master switch must be ON
        if (!self::isMcpEnabled()) {
            return false;
        }

        // 2. If admin-only mode, check user role
        if (self::isMcpAdminOnly()) {
            if (class_exists('\Session') && \Session::getAuthStatus() === \Constants::STATUS_LOGGEDIN) {
                $user = \Session::getUser();
                if ($user && $user->getRole() === 'superuser') {
                    return true;
                }
            }
            return false; // Not logged in or not superuser
        }

        return true;
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
                $userDoc = $user->getLabFeatures();
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
