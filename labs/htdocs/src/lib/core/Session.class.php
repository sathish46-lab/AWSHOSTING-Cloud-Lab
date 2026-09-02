<?php

/**
 * Session class
 * Static helper for page/session state.
 */
class Session
{
    /* ----------------------------------------------------------------------
     * Page presentation fields
     * -------------------------------------------------------------------- */
    public static $pageTitle          = 'Welcome to Tom Lab ';
    public static $subLogo            = '';
    public static $meta               = array();      // meta tags
    public static $customCss          = array();      // list of CSS files
    public static $customJs          = array();      // list of JS files
    public static $localPack          = null;         // local messages, etc.
    public static $ConsoleLogs        = array();      // console log entries

    /* ----------------------------------------------------------------------
     * Environment / version info
     * -------------------------------------------------------------------- */
    public static $csrfToken          = '';
    // public static $version            = '';
    public static $fullVersion        = '';
    public static $versionDescription = '';
    public static $environment        = '';           // local / beta / prod
    public static $cacheCDN           = null;         // CDN base url

    /* ----------------------------------------------------------------------
     * User / auth flags
     * -------------------------------------------------------------------- */
    public static $userSession        = null;         // instance of UserSession or similar
    public static $authStatus         = null;         // STATUS_DEFAULT / STATUS_LOGGEDIN
    public static $isModerator        = false;
    public static $isSuperUser        = false;
    public static $privileges         = null;         // array of privileges
    public static $privilegesGroup    = null;

    /* ----------------------------------------------------------------------
     * Generic key/value storage
     * -------------------------------------------------------------------- */
    public static $property           = array();      // arbitrary shared data

    /* ----------------------------------------------------------------------
     * CSRF Protection
     * -------------------------------------------------------------------- */

    /**
     * Generate or return existing CSRF token for the current session.
     */
    public static function csrfToken()
    {
        if (empty(self::$csrfToken)) {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            self::$csrfToken = $_SESSION['csrf_token'];
        }
        return self::$csrfToken;
    }

    /**
     * Validate a submitted CSRF token against the session token.
     */
    public static function validateCsrf($token)
    {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Output a hidden CSRF input field for forms.
     */
    public static function csrfField()
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::csrfToken()) . '">';
    }

    /* ----------------------------------------------------------------------
     * Basic key/value API
     * -------------------------------------------------------------------- */

    /**
     * Trigger a premium notification toast.
     * Usage: Session::toast("Device added!", "success");
     * 
     * @param string $message The message body
     * @param string $type success|error|warning|info
     */
    public static function toast($message, $type = 'success')
    {
        self::set("toast_$type", $message);
    }

    /**
     * Set a shared value.
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function set($key, $value)
    {
        self::$property[$key] = $value;
    }

    /**
     * Get a shared value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function get($key, $default = false)
    {
        if (isset(self::$property[$key])) {
            return self::$property[$key];
        }
        return $default;
    }

    /* ----------------------------------------------------------------------
     * Meta tags
     * -------------------------------------------------------------------- */

    /**
     * Add a meta tag (string or array).
     *
     * @param mixed $tag
     */
    public static function addMetaTag($tag)
    {
        if (!self::get('meta-processed')) {
            if (is_array($tag)) {
                foreach ($tag as $t) {
                    self::$meta[] = $t;
                }
            } else {
                self::$meta[] = $tag;
            }
        } else {
            trigger_error(
                'Unable to add meta tags after Session::loadMaster() has been called',
                E_USER_WARNING
            );
        }
    }

    /**
     * Calculate the time taken to render the page in microseconds.
     *
     * @return string Formatted string in milliseconds/microseconds
     */
    public static function getRenderTime()
    {
        if (!defined('PAGE_START_TIME')) {
            return '0ms';
        }
        
        $endTime = microtime(true);
        $duration = $endTime - PAGE_START_TIME;

        // Convert to milliseconds and format to 2 decimal places
        return number_format($duration * 1000, 2) . ' ms';
    }

    /* ----------------------------------------------------------------------
     * User / auth helpers
     * -------------------------------------------------------------------- */

    /**
     * Get stored user session object.
     *
     * @return mixed
     */
    public static function getUserSession()
    {
        return self::$userSession;
    }

    /**
     * Example wrapper to return the user model from UserSession.
     *
     * @return mixed|null
     */
    public static function getUser()
    {
        if (!self::$userSession) {
            return null;
        }
        if (method_exists(self::$userSession, 'getUser')) {
            return self::$userSession->getUser();
        }
        return null;
    }

    /**
     * Return current auth status.
     *
     * @return mixed
     */
    public static function getAuthStatus()
    {
        return self::$authStatus;
    }

    /* ----------------------------------------------------------------------
     * Navigation inclusion helpers
     * -------------------------------------------------------------------- */

    public static function getNav()
    {
        // Fixed path: src/lib/core -> src/lib -> src/template
        include __DIR__ . '/../../template/_nav.php'; 
    }

    public static function getSiteNav()
    {
        include __DIR__ . '/../../template/_sitenav.php';
    }

    
    public static function getVersion() {
        global $git_version;
        
        // Priority 1: Manual override from env.json
        $manual_version = get_config('asset_version');
        if (!empty($manual_version)) {
            return $manual_version;
        }
        
        // Priority 2: The real Git hash from load.php
        if (!empty($git_version) && $git_version !== '1.0.0') {
            return $git_version;
        }

        // Priority 3: File timestamp (for local dev without git)
        $basePath = realpath(__DIR__ . '/../../'); 
        $file = $basePath . '/htdocs/js/app.js';
        if (file_exists($file)) {
            return filemtime($file); 
        }
        
        // Priority 4: Final fallback
        return $git_version ?? '1.0.0'; 
    }

    /**
     * Build a cache-busted URL using the real Git Hash.
     */
    public static function cacheCDN($url)
    {
        $version = self::getVersion();
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'v=' . $version;
    }
    /**
     * Global CDN helper for MinIO S3 assets.
     * Usage: Session::cdn('icons/ubuntu.png')
     */
    public static function cdn3($path) {
        $config = get_config('s3');
        if (!$config) {
            // Fallback to local if S3 isn't configured
            return "/assets/" . ltrim($path, '/');
        }
        
        // Construct the direct public URL
        // Format: https://endpoint/bucket/path
        return rtrim($config['endpoint'], '/') . '/' . $config['bucket'] . '/' . ltrim($path, '/');
    }

    /* ----------------------------------------------------------------------
     * Master layout and footer
     * -------------------------------------------------------------------- */

    public static function loadMaster()
    {
        if (self::get('master_rendered', false)) {
            // Master layout already started rendering.
            // If we are called again, just output the error directly if needed.
            if (self::get('brokenPage', false)) {
                self::loadTemplate('_error');
            }
            return;
        }
        self::set('master_rendered', true);

        if (self::getAuthStatus() !== Constants::STATUS_LOGGEDIN && !defined('IS_LOGIN_PAGE') && !defined('IS_LANDING_PAGE')) {
            self::handleSessionExpired();
        }

        // --- HTMX SPA Interception ---
        // If this is an HTMX request (and specifically a boosted one to be safe, though HX-Request suffices), 
        // we skip the master layout (header, sidebar, footer) and just return the content.
        if (isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] == 'true') {
            if (defined('IS_LANDING_PAGE') || defined('IS_LOGIN_PAGE') || defined('IS_HOME_PAGE')) {
                header('HX-Redirect: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            // Send the title so HTMX can update the browser tab automatically
            if (!empty(self::$pageTitle)) {
                echo "<title>" . htmlspecialchars(self::$pageTitle) . "</title>";
            }

            // Breadcrumb removed — new header uses greeting text instead
            // echo '<ol id="main-breadcrumb" class="breadcrumb my-0" hx-swap-oob="true">';
            // include __DIR__ . '/../../template/partials/_breadcrumb.php';
            // echo '</ol>';
            
            // Output specific page content FIRST (like SNA)
            if (!self::get('brokenPage', false)) {
                self::generatePageBody();
            } else {
                self::loadTemplate('_error');
            }

            // Send footer data via OOB AFTER content (like SNA)
            if (!self::get('footer', false) && !defined('IS_HOME_PAGE') && !self::get('show_session_expired', false)) {
                self::generateFooter(true);
            }

            // Build htmx-page-bootstrap JSON for breadcrumb + page metadata (at END like SNA)
            $pageTitle = self::$pageTitle ?? 'Dashboard';
            $titleParts = array_map('trim', explode(' / ', $pageTitle));
            $labHash = self::get('full_instance_hash');
            $challengeHash = self::get('challenge_instance_hash');
            $breadcrumbs = [];
            $pathSoFar = '';
            $hasLabsContext = false;
            $hasChallengesContext = false;

            foreach ($titleParts as $index => $part) {
                $lowerPart = strtolower($part);
                $url = null;

                if (stripos($lowerPart, 'lab') !== false) $hasLabsContext = true;
                if (stripos($lowerPart, 'challenge') !== false) $hasChallengesContext = true;

                $quizParent = self::get('parent_topic');
                $quizSubtopic = self::get('current_subtopic');
                $quizCategory = self::get('current_topic');

                if (strcasecmp($lowerPart, 'quiz') === 0) {
                    $url = '/quiz';
                } elseif (strcasecmp($lowerPart, 'spot quiz') === 0) {
                    $quiz = self::get('current_quiz');
                    $url = $quiz ? "/quiz/v/" . $quiz['hash'] : '/quiz';
                } elseif (($quizParent && strcasecmp($part, trim($quizParent['title'])) === 0) ||
                          ($quizCategory && strcasecmp($part, trim($quizCategory['title'])) === 0)) {
                    $cat = $quizParent ?? $quizCategory;
                    $url = "/quiz/" . ($cat['id'] ?? $cat['_id']);
                } elseif ($quizSubtopic && strcasecmp($part, trim($quizSubtopic['title'])) === 0) {
                    $catId = $quizParent ? ($quizParent['id'] ?? $quizParent['_id']) : ($quizCategory ? ($quizCategory['id'] ?? $quizCategory['_id']) : 'all');
                    $url = "/quiz/$catId/Recent/" . ($quizSubtopic['id'] ?? $quizSubtopic['_id']);
                } elseif (stripos($lowerPart, 'home') !== false) {
                    $url = '/';
                } elseif (stripos($lowerPart, 'dashboard') !== false) {
                    if ($hasLabsContext && $labHash) $url = "/labs/dashboard/$labHash";
                    elseif ($hasChallengesContext && $challengeHash) $url = "/challenges/dashboard/$challengeHash";
                    else $url = '/dashboard';
                } elseif ($lowerPart === 'lab' || $lowerPart === 'labs') {
                    $url = '/labs';
                } elseif ($lowerPart === 'challenge' || $lowerPart === 'challenges') {
                    $url = '/challenges';
                } elseif ($lowerPart === 'service' || $lowerPart === 'services') {
                    $url = '/services';
                } elseif (stripos($lowerPart, 'mysql server') !== false) { $url = '/services/mysql';
                } elseif (stripos($lowerPart, 'mariadb server') !== false) { $url = '/services/mariadb';
                } elseif (stripos($lowerPart, 'postgresql server') !== false) { $url = '/services/postgresql';
                } elseif (stripos($lowerPart, 'mongodb server') !== false) { $url = '/services/mongodb';
                } elseif (stripos($lowerPart, 'rabbitmq server') !== false) { $url = '/services/rabbitmq';
                } elseif (stripos($lowerPart, 'redis server') !== false) { $url = '/services/redis';
                } elseif (stripos($lowerPart, 'device') !== false) { $url = '/devices';
                } elseif (stripos($lowerPart, 'network') !== false) { $url = '/network';
                } elseif (stripos($lowerPart, 'domain') !== false) {
                    if ($hasLabsContext && $labHash) $url = "/labs/domains/$labHash";
                    else $url = '/domains';
                } elseif (stripos($lowerPart, 'pref') !== false && $hasLabsContext && $labHash) {
                    $url = "/labs/preferences/$labHash";
                } elseif (stripos($lowerPart, 'account') !== false) { $url = '/account';
                } elseif (stripos($lowerPart, 'ssl') !== false) { $url = '/ssl';
                } elseif (stripos($lowerPart, 'achieve') !== false && $challengeHash) {
                    $url = "/challenges/achievements/$challengeHash";
                } elseif (stripos($lowerPart, 'leader') !== false && $challengeHash) {
                    $url = "/challenges/leaderboard/$challengeHash";
                }

                if ($url === null) {
                    $pathSoFar .= '/' . $lowerPart;
                    $url = $pathSoFar;
                }

                $breadcrumbs[] = ['name' => $part, 'uri' => $url];
            }

            $pageBootstrap = [
                'breadcrumbs' => $breadcrumbs,
                'title' => $pageTitle,
                'uri' => $_SERVER['REQUEST_URI'] ?? '/',
                'pageInit' => false,
            ];
            echo '<script id="htmx-page-bootstrap" type="application/json">' . json_encode($pageBootstrap, JSON_UNESCAPED_SLASHES) . '</script>';
            return; // Exit here, bypassing _master.php completely!
        }
        
        // This was the specific line causing your error
        include __DIR__ . '/../../template/_master.php';
    }

    public static function handleSessionExpired()
    {
        setcookie('show_session_expired', '1', time() + 30, '/');
        self::set('show_session_expired', true);
        if (isset($_SESSION)) {
            $_SESSION['show_session_expired'] = true;
        }

        if (isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] == 'true') {
            header('HX-Trigger: {"tomNotify": {"message": "Your session has expired. Please sign in again.", "title": "Authentication Required", "type": "warning"}}');
            echo '<div class="alert alert-warning border-0 rounded-4 p-3 my-2 d-flex align-items-center gap-3 shadow-sm" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3) !important;">
                <i class="bx bx-error-circle fs-3 text-warning"></i>
                <div>
                    <strong class="text-white d-block">Session Expired</strong>
                    <span class="small text-secondary">Your authentication session has timed out. <a href="/signin" class="text-warning fw-bold text-decoration-underline" data-no-boost="true">Sign in</a> to continue.</span>
                </div>
            </div>
            <script>
            if (window.TomNotify) {
                TomNotify.show("Your session has expired. Please sign in again.", "Authentication Required", "warning", 5000);
            }
            setTimeout(function() {
                window.location.href = "/";
            }, 2000);
            </script>';
            exit;
        } else {
            header('Location: /');
            exit;
        }
    }

    public static function generateFooter($isOob = false)
    {
        include __DIR__ . '/../../template/_footer.php';
    }

    /* ----------------------------------------------------------------------
     * Template helpers
     * -------------------------------------------------------------------- */

    public static function loadTemplate($template, $general = false, $customFile = null)
    {
        $customFile = self::getCurrentFile($customFile);

        if ($template === '_error') {
            include __DIR__ . '/../../template/' . $template . '.php';
            return;
        }

        if ($general) {
            $path = __DIR__ . '/../../template/' . $template . '.php';
            if (!file_exists($path)) {
                throw new TemplateUnavailableException('Template not found: ' . $template);
            }
            include $path;
        } else {
            $path = __DIR__ . '/../../template/' . $customFile . '/' . $template . '.php';
            if (!file_exists($path)) {
                throw new TemplateUnavailableException(
                    'Template not found: ' . $template . ' in ' . $customFile
                );
            }
            include $path;
        }
    }

    public static function templateExists($template, $general = false, $customFile = null)
    {
        $customFile = self::getCurrentFile($customFile);

        if ($template === Constants::TEMPLATE_ERROR) {
            return true;
        }

        if ($general) {
            return file_exists(__DIR__ . '/../../template/' . $template . '.php');
        }

        return file_exists(
            __DIR__ . '/../../template/' . $customFile . '/' . $template . '.php'
        );
    }

    /**
     * Load the standard error page inside the master layout.
     */
    public static function loadErrorPage()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        self::set('brokenPage', true);
        self::set('footer', false);
        self::loadMaster();
    }

    /* ----------------------------------------------------------------------
     * Utility helpers
     * -------------------------------------------------------------------- */

    /**
     * Generate a pseudo‑random hash (Base64 encoded).
     *
     * @param int $length Length in bytes before encoding
     * @return string
     */
    public static function generatePseudoRandomHash($length = 10)
    {
        $bytes = openssl_random_pseudo_bytes($length);
        return base64_encode($bytes);
    }

    
    /**
     * Updated: Preserves folder structure for template loading
     */
    public static function generatePageBody()
    {
        $self = $_SERVER['PHP_SELF'];
        $relPath = str_replace(['/app/', '.php'], '', $self);
        $templateRoot = __DIR__ . '/../../template/pages/'; 

        try {
            if (self::getAuthStatus() == Constants::STATUS_LOGGEDIN) {
                // Try specific file first
                $file = $templateRoot . $relPath . '.php';
                if (file_exists($file)) {
                    include $file;
                    return;
                }
                
                // Try category fallback (e.g., if on quiz/quiz_hub, try quiz.php)
                if (strpos($relPath, '/') !== false) {
                    $parts = explode('/', $relPath);
                    $catFile = $templateRoot . $parts[0] . '.php';
                    if (file_exists($catFile)) {
                        include $catFile;
                        return;
                    }
                }

                // Fallback to dashboard
                if (file_exists($templateRoot . 'dashboard.php')) {
                    include $templateRoot . 'dashboard.php';
                } else {
                    echo '<div class="alert alert-danger">Error: Template not found for ' . htmlspecialchars($relPath) . '</div>';
                }
            } else {
                // Not logged in: Trigger session expired handler cleanly without query params
                self::handleSessionExpired();
            }
        } catch (Throwable $e) {
            // Gracefully catch any exceptions/errors thrown during page rendering
            // and display the error card inline without breaking the master layout!
            self::set('error_exception', $e);
            self::set('brokenPage', true);
            self::loadTemplate('_error');
        }
    }

    /**
     * Return the current script name without extension, or
     * the base name of a provided path.
     *
     * @param string|null $file
     * @return string
     */
    public static function getCurrentFile($file = null)
    {
        if ($file === null) {
            $path = str_replace(['/app/', '.php'], '', $_SERVER['PHP_SELF']);
            // For nested paths like quiz/quiz_hub, return the first part for nav highlights
            if (strpos($path, '/') !== false) {
                $parts = explode('/', $path);
                return $parts[0];
            }
            return $path;
        }
        return basename($file, '.php');
    }
    /* ----------------------------------------------------------------------
     * Environment helpers (instance‑style)
     * -------------------------------------------------------------------- */

    /**
     * Get the configured environment string.
     *
     * @return string
     */
    public function getEnvironment()
    {
        return $this->environment;
    }

    public function isBeta()
    {
        return $this->environment === 'beta';
    }

    public function isProd()
    {
        return $this->environment === 'prod';
    }

    public function isAlpha()
    {
        return $this->environment === 'alpha';
    }

    public static function getProcessorCount() {
    $ncpu = Cache::get('processor_count');
    if ($ncpu) {
        return $ncpu;
    } else {
        $cpus = @file_get_contents('/sys/devices/system/cpu/online'); 
        if (!$cpus) return 1;

        $parts = explode('-', trim($cpus));
        // For "0-1", parts[1] is "1". (int)1 + 1 = 2 cores.
        $ncpu = isset($parts[1]) ? (int)$parts[1] + 1 : 1; 
        
        Cache::set('processor_count', $ncpu);
        return $ncpu;
    }
}
    public function getEvironment(){
        return $this->environment;
    }
    /* ----------------------------------------------------------------------
     * Custom Assets (CSS/JS)
     * -------------------------------------------------------------------- */

    /**
     * Add a custom CSS file to the page.
     * @param string $css Path to the CSS file
     */
    public static function addCustomCss($css)
    {
        self::$customCss[] = $css;
    }

    /**
     * Add a custom JS file to the page.
     * @param string $js Path to the JS file
     */
    public static function addCustomJs($js)
    {
        self::$customJs[] = $js;
    }

    public static function url($route) {

        // This allows you to change the routing in one place later
        $routes = [
            'signin'    => '/signin',
            'signup'    => '/signup',
            'logout'    => '/logout',
            'home'      => '/home',
            'quiz'      => '/quiz',
            'quiz_view' => '/quiz/%',
            'dashboard' => '/dashboard',
            'challenges' => '/challenges',
            'verify' => '/verify'
        ];
        return $routes[$route] ?? '/';
    }

    /**
     * Get the current user's avatar with a robust fallback system.
     * Checks database/session and verifies physical file existence.
     *
     * @return string Valid URL to an avatar image
     * 
     * Get dynamic user avatar with unique fallback.
     * Fixes the remote URL override bug.
     */
    public static function getAvatar()
    {
        $user = self::getUser();
        
        // 1. Professional default pool
        $defaultAvatars = [
            self::cdn3('avatars/avatar1.png'), self::cdn3('avatars/avatar2.png'),
            self::cdn3('avatars/avatar3.png'), self::cdn3('avatars/avatar4.png'),
            self::cdn3('avatars/avatar5.png'), self::cdn3('avatars/avatar6.png'),
            self::cdn3('avatars/avatar7.png'), self::cdn3('avatars/avatar8.png'),
            self::cdn3('avatars/avatar9.png'), self::cdn3('avatars/avatar10.png')
        ];

        // 2. Load the actual user profile link
        $avatarUrl = $user?->getAvatarUrl() ?? $_SESSION['user_avatar'] ?? null;

        if (!empty($avatarUrl)) {
            // CHECK: Is it a remote URL (like Google) or a routed system path (MinIO/S3)?
            if (strpos($avatarUrl, 'http') === 0 || strpos($avatarUrl, '/system/') === 0) {
                // Remote URL or routed system path is always valid
                return htmlspecialchars($avatarUrl); 
            }
            
            // CHECK: Is it a valid physical local file?
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . $avatarUrl)) {
                // Local file exists
                return htmlspecialchars($avatarUrl);
            }
        }

        // 3. DYNAMIC FALLBACK: Consistent "random" choice based on User ID
        $seedValue = $user ? $user->getUserId() : session_id();
        $index = abs(crc32($seedValue)) % count($defaultAvatars);

        return $defaultAvatars[$index];
    }

    /**
     * Get avatar for any specific username (used for lesson authors, public cards, etc.)
     */
    public static function getAvatarForUsername($username)
    {
        $currentUser = self::getUser();
        $currentUsername = $currentUser ? $currentUser->getUsername() : null;
        if (empty($username) || ($currentUsername && $username === $currentUsername)) {
            return self::getAvatar();
        }
        static $avatarCache = [];
        if (isset($avatarCache[$username])) {
            return $avatarCache[$username];
        }

        $defaultAvatars = [
            self::cdn3('avatars/avatar1.png'), self::cdn3('avatars/avatar2.png'),
            self::cdn3('avatars/avatar3.png'), self::cdn3('avatars/avatar4.png'),
            self::cdn3('avatars/avatar5.png'), self::cdn3('avatars/avatar6.png'),
            self::cdn3('avatars/avatar7.png'), self::cdn3('avatars/avatar8.png'),
            self::cdn3('avatars/avatar9.png'), self::cdn3('avatars/avatar10.png')
        ];

        try {
            $db = DatabaseConnection::getDefaultDatabase();
            $userDoc = $db->users->findOne(['username' => $username]);
            if (!$userDoc && strpos($username, '@') !== false) {
                $userDoc = $db->users->findOne(['email' => $username]);
            }

            if ($userDoc && !empty($userDoc['avatar_url'])) {
                $avatarUrl = $userDoc['avatar_url'];
                if (strpos($avatarUrl, 'http') === 0 || strpos($avatarUrl, '/system/') === 0 || file_exists($_SERVER['DOCUMENT_ROOT'] . $avatarUrl)) {
                    $avatarCache[$username] = htmlspecialchars($avatarUrl);
                    return $avatarCache[$username];
                }
            }

            $seedValue = $userDoc ? ($userDoc['user_id'] ?? $userDoc['_id'] ?? $username) : $username;
            $index = abs(crc32((string)$seedValue)) % count($defaultAvatars);
            $avatarCache[$username] = $defaultAvatars[$index];
            return $avatarCache[$username];
        } catch (\Exception $e) {
            $index = abs(crc32((string)$username)) % count($defaultAvatars);
            return $defaultAvatars[$index];
        }
    }

    /**
     * Generates a unique CSS hue-rotate based on the user's ID
     * 
     * Generates a unique color shift for default avatars
     */
    public static function getAvatarStyle() {
        $user = self::getUser();
        
        // If the user HAS a real profile (Google/Uploaded/MinIO), do not change the color with hue-rotate
        if ($user && $user->getAvatarUrl()) {
            $url = $user->getAvatarUrl();
            if (strpos($url, 'http') === 0 || strpos($url, '/system/') === 0 || strpos($url, '/uploads/') === 0) {
                return ""; 
            }
        }

        $seed = $user ? $user->getUserId() : session_id();
        $hue = abs(crc32($seed)) % 360;
        return "filter: hue-rotate({$hue}deg);";
    }
}
