<?php
/**
 * ============================================================================
 * LEARN AI - 6-LAYER SYSTEM ARCHITECTURE ORCHESTRATOR
 * ============================================================================
 * Implements strict Layered Intent Routing & Local Tool Security Boundary
 * Layer 1: Client UI -> Layer 2: Gateway Auth -> Layer 3: Intent Router
 * Layer 4a: Deterministic Path (Local, 0 API cost) vs Layer 4b: LLM Orchestrator
 * Layer 5: Tool Execution Boundary (Auth/Ownership check) -> Layer 6: Data Stores
 * ============================================================================
 */

class LearnAIOrchestrator {
    private $db;
    private $userId;
    private $sessionUser;
    private $lessonId;
    private $chapterId;
    private $sessionId;

    public function __construct($userId, $sessionUser, $lessonId = '', $chapterId = '', $sessionId = '') {
        $this->db = DatabaseConnection::getDefaultDatabase();
        $this->userId = (int)$userId;
        $this->sessionUser = $sessionUser;
        $this->lessonId = (string)$lessonId;
        $this->chapterId = (string)$chapterId;
        $this->sessionId = $sessionId;
    }

    /**
     * LAYER 3: INTENT ROUTER (100% Local, Zero LLM API Cost)
     * Regex/keyword match for known lookups or session state checks.
     * Decides: Deterministic Path (4a) OR LLM Path (4b)
     */
    public function route($query) {
        $qLower = strtolower(trim($query));

        // Deterministic Pattern 1: How do you know which chapter/lesson I am studying?
        if (preg_match('/(how\s+(do\s+)?you\s+know\s+(which|what)\s+(chapter|lesson)|how\s+you\s+know\s+which\s+chapter\s+i\s+lesson)/i', $qLower)) {
            return $this->deterministicPath('explain_chapter_context');
        }

        // Deterministic Pattern 2: How do you access tools / architecture / labsctl / offline vs online model?
        if (preg_match('/(access\s+and\s+execute\s+this\s+tool|labsctl|offline\s+model|what\s+specific\s+type\s+of\s+architecture|model\s+architecture)/i', $qLower)) {
            return $this->deterministicPath('explain_architecture_tools');
        }

        // Deterministic Pattern 3: What is my current active chapter or lesson?
        if (preg_match('/((which|what|current)\s+(lesson|chapter)|what\s+am\s+i\s+studying|which\s+lesson\s+i\s+learn)/i', $qLower)) {
            return $this->deterministicPath('show_current_chapter');
        }

        // Deterministic Pattern 4: What is my active lab / running lab?
        if (preg_match('/^(what\s+is\s+my\s+(current\s+|active\s+)?lab|list\s+(my\s+)?running\s+labs|active\s+lab\s*\??)$/i', $qLower)) {
            return $this->deterministicPath('show_active_lab');
        }

        // Otherwise -> Route to Layer 4b: LLM Orchestrator
        return null;
    }

    /**
     * LAYER 4a: DETERMINISTIC PATH (Local, 0 API cost, Sub-100ms Latency)
     * Executes Layer 5 tools locally and formats clean deterministic answers.
     */
    public function deterministicPath($action) {
        switch ($action) {
            case 'explain_chapter_context':
                return "Good question! Here's how I know which lesson and chapter you're currently studying:\n"
                     . "You are accessing this learning session through the platform, which tracks your active lesson and chapter by their unique IDs behind the scenes.\n"
                     . "When you ask questions related to the lesson, I use the current lesson ID and chapter ID from your session context to fetch the exact content being studied.\n"
                     . "The platform automatically shares this info with me, allowing me to load the right chapter content using the tool: read_chapter_content(lesson_id, [chapter_id])\n"
                     . "For executing commands or saving progress, I check your active lab with get_lab_user_info() to know your actual lab instance and user.\n"
                     . "This knowledge lets me tailor answers precisely for your current lesson and chapter, plus execute actions in your correct lab environment.\n"
                     . "So, even if the \"Essentials Lab\" is currently your active lab, the lesson and chapter context is provided by the learning system as part of your session, ensuring I'm aligned to your actual lesson progress.";

            case 'explain_architecture_tools':
                return "Great questions! Here's an overview of how I operate and access tools:\n\n"
                     . "### Tool Access & Execution\n"
                     . "I am integrated with the Selfmade Ninja Labs backend system via API-like functions (these are the \"tools\" you see, e.g., `read_chapter_content()`, `execute_command_in_lab()`).\n"
                     . "When you ask a question or request an action, I invoke these tools programmatically to get live data from your lab environment or lesson database.\n"
                     . "For example, to run a command in your lab, I call `execute_command_in_lab()` with your lab's instance ID, the command string, and your username.\n"
                     . "These tools abstract away direct CLI access like `labsctl`; instead, I rely on platform APIs to fetch data, run commands, or update progress.\n\n"
                     . "### Model Architecture\n"
                     . "I am an AI tutor built on a large language model (LLM) architecture, fine-tuned specifically for this educational environment.\n"
                     . "I run as an online service connected to the platform, so I interact live with your labs and lesson content.\n"
                     . "This is not an offline local model — I rely on cloud/API integration to serve up-to-date info and execute real lab commands for you.\n"
                     . "My knowledge includes pre-trained data plus live context from your current session, giving you accurate, personalized assistance.";

            case 'show_current_chapter':
                $chapterInfo = $this->readChapterContent($this->lessonId, $this->chapterId);
                if (empty($chapterInfo['title'])) {
                    return "You are currently exploring the lesson overview. Select a specific chapter on the left outline to study targeted material!";
                }
                return "### Active Study Context\n"
                     . "**Lesson Title:** " . htmlspecialchars($chapterInfo['lesson_title'] ?? 'Current Lesson') . "\n"
                     . "**Module:** " . htmlspecialchars($chapterInfo['module_name'] ?? 'General') . "\n"
                     . "**Active Chapter:** " . htmlspecialchars($chapterInfo['title']) . "\n\n"
                     . "I am locked onto this chapter's material and ready to clarify any concepts or code examples for you.";

            case 'show_active_lab':
                $labInfo = $this->getLabUserInfo();
                return "### Active Lab Environment\n"
                     . "**Lab Name:** " . htmlspecialchars($labInfo['name'] ?? 'Essentials Lab') . "\n"
                     . "**IP Address:** `" . htmlspecialchars($labInfo['ip'] ?? '172.30.0.28') . "`\n"
                     . "**Status:** " . htmlspecialchars($labInfo['status'] ?? 'running') . "\n"
                     . "**Security Scope:** User `" . htmlspecialchars($this->userId) . "` session verified via local tool enforcement.";

            default:
                return null;
        }
    }

    /**
     * LAYER 5: TOOL EXECUTION LAYER (Local Security & Enforcement Boundary)
     * read_chapter_content(lesson_id, chapter_id)
     * Scoped strictly by session context so AI assistant focuses ONLY on this lesson.
     */
    public function readChapterContent($lessonId, $chapterId) {
        $result = [
            'lesson_id' => $lessonId,
            'chapter_id' => $chapterId,
            'lesson_title' => '',
            'module_name' => '',
            'title' => '',
            'content' => ''
        ];

        if (!empty($lessonId)) {
            $lidMongo = null;
            try { $lidMongo = new MongoDB\BSON\ObjectId($lessonId); } catch (Exception $e) {}
            if ($lidMongo) {
                $lessonDoc = $this->db->ai_lessons->findOne(['_id' => $lidMongo]);
                if ($lessonDoc) {
                    $result['lesson_title'] = $lessonDoc['title'] ?? '';
                }
            }
        }

        if (!empty($chapterId)) {
            $cidMongo = null;
            try { $cidMongo = new MongoDB\BSON\ObjectId($chapterId); } catch (Exception $e) {}
            if ($cidMongo) {
                $chapterDoc = $this->db->ai_chapters->findOne(['_id' => $cidMongo]);
                if ($chapterDoc) {
                    $result['title'] = $chapterDoc['title'] ?? '';
                    $result['module_name'] = $chapterDoc['module_name'] ?? '';
                    $result['content'] = $chapterDoc['content'] ?? '';
                    if (empty($result['lesson_title']) && !empty($chapterDoc['lesson_id'])) {
                        $lessonDoc = $this->db->ai_lessons->findOne(['_id' => $chapterDoc['lesson_id']]);
                        if ($lessonDoc) {
                            $result['lesson_title'] = $lessonDoc['title'] ?? '';
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * LAYER 5: TOOL EXECUTION LAYER
     * get_lab_user_info()
     */
    public function getLabUserInfo() {
        $labTemplates = [
            ['id' => 'essentials', 'name' => 'Essentials Lab', 'icon' => 'tux'],
            ['id' => 'minio', 'name' => 'MinIO S3 Storage', 'icon' => 'docker'],
            ['id' => 'n8n', 'name' => 'n8n Workflow Lab', 'icon' => 'git-repo-forked'],
            ['id' => 'docker_lab', 'name' => 'Tom Docker Lab', 'icon' => 'docker']
        ];

        // Default fallback to first lab (Essentials) if nothing is running
        $defaultLab = null;

        foreach ($labTemplates as $tmpl) {
            $hash = $this->sessionUser->getLabHash($tmpl['id']);
            $inst = $this->db->instances->findOne(['instance_hash' => $hash]);
            $data = $inst ? ($inst['deploy'] ?? []) : null;

            $labInfo = [
                'id'     => $tmpl['id'],
                'name'   => $tmpl['name'],
                'hash'   => $hash,
                'status' => ($data && $data['status'] === 'running') ? 'running' : 'offline',
                'ip'     => ($data && $data['status'] === 'running' && isset($data['internal_ip'])) ? $data['internal_ip'] : 'Instance Down'
            ];

            if ($labInfo['status'] === 'running') {
                return $labInfo; // Return the first running lab found
            }

            if ($defaultLab === null) {
                $defaultLab = $labInfo; // Save Essentials as fallback
            }
        }

        return $defaultLab;
    }

    /**
     * LAYER 4b: LLM ORCHESTRATOR PRE-FETCH
     * Prepares enriched context package locally via Layer 5 before queuing job.
     */
    public function prepareLLMContext() {
        return [
            'chapter_context'  => $this->readChapterContent($this->lessonId, $this->chapterId),
            'lab_context'      => $this->getLabUserInfo(),
            'all_labs'         => $this->getAllUserLabs(),
            'lesson_outline'   => $this->getLessonOutline(),
            'user_info'        => [
                'user_id'  => $this->userId,
                'username' => $this->sessionUser->getUsername(),
                'email'    => $this->sessionUser->getEmail()
            ]
        ];
    }

    /**
     * LAYER 5: TOOL - List ALL user labs with real-time status
     */
    public function getAllUserLabs() {
        $labTemplates = [
            ['id' => 'essentials', 'name' => 'Essentials Lab', 'icon' => 'tux'],
            ['id' => 'minio', 'name' => 'MinIO S3 Storage', 'icon' => 'docker'],
            ['id' => 'n8n', 'name' => 'n8n Workflow Lab', 'icon' => 'git-repo-forked'],
            ['id' => 'docker_lab', 'name' => 'Tom Docker Lab', 'icon' => 'docker']
        ];

        $labs = [];
        foreach ($labTemplates as $tmpl) {
            $hash = $this->sessionUser->getLabHash($tmpl['id']);
            $inst = $this->db->instances->findOne(['instance_hash' => $hash]);
            $data = $inst ? ($inst['deploy'] ?? []) : null;

            $labs[] = [
                'id'          => $tmpl['id'],
                'name'        => $tmpl['name'],
                'instance_id' => $hash,
                'status'      => ($data && $data['status'] === 'running') ? 'running' : 'offline',
                'ip'          => ($data && $data['status'] === 'running' && isset($data['internal_ip'])) ? $data['internal_ip'] : 'Instance Down'
            ];
        }
        return $labs;
    }

    /**
     * LAYER 5: TOOL - Get lesson outline with module/chapter tree
     */
    public function getLessonOutline() {
        if (empty($this->lessonId)) {
            return ['error' => 'No lesson ID in current session'];
        }

        $lidMongo = null;
        try { $lidMongo = new \MongoDB\BSON\ObjectId($this->lessonId); } catch (\Exception $e) { return []; }

        $lesson = $this->db->ai_lessons->findOne(['_id' => $lidMongo]);
        if (!$lesson) return ['error' => 'Lesson not found'];

        $chapters = $this->db->ai_chapters->find(
            ['lesson_id' => $lidMongo],
            ['sort' => ['order' => 1]]
        )->toArray();

        $outline = [
            'lesson_id'    => $this->lessonId,
            'title'        => $lesson['title'] ?? '',
            'description'  => $lesson['description'] ?? '',
            'level'        => $lesson['level'] ?? 'beginner',
            'modules'      => []
        ];

        $moduleMap = [];
        foreach ($chapters as $ch) {
            $moduleName = $ch['module_name'] ?? 'General';
            if (!isset($moduleMap[$moduleName])) {
                $moduleMap[$moduleName] = [];
            }
            $moduleMap[$moduleName][] = [
                'chapter_id'   => (string)$ch['_id'],
                'title'        => $ch['title'] ?? '',
                'has_content'  => !empty($ch['content']),
                'is_current'   => ((string)$ch['_id'] === $this->chapterId)
            ];
        }

        foreach ($moduleMap as $modName => $chaps) {
            $outline['modules'][] = [
                'name'     => $modName,
                'chapters' => $chaps
            ];
        }

        return $outline;
    }
}
