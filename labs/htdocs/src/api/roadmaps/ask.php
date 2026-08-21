<?php
/**
 * Roadmap AI Assist - Ask API
 * Triggers AI streaming for roadmap context
 */
require_once __DIR__ . '/../../load.php';
require_once __DIR__ . '/../../lib/core/RabbitClient.class.php';

header('Content-Type: application/json');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();

$input = json_decode(file_get_contents('php://input'), true);
$query = $input['query'] ?? '';
$roadmapId = $input['roadmap_id'] ?? '';
$sessionId = $input['session_id'] ?? uniqid('sess_');
$messageId = $input['message_id'] ?? uniqid('msg_');
$aiModel = $input['ai_model'] ?? 'gemini';

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => 'Query is required']);
    exit;
}

if (empty($roadmapId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Roadmap ID is required']);
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();

    // Verify roadmap access
    $roadmap = $db->ai_roadmaps->findOne([
        '_id' => new MongoDB\BSON\ObjectId($roadmapId),
        '$or' => [
            ['user_id' => $userId],
            ['visibility' => 'public']
        ]
    ]);

    if (!$roadmap) {
        http_response_code(404);
        echo json_encode(['error' => 'Roadmap not found']);
        exit;
    }

    // Build roadmap context for AI
    $sections = [];
    if (isset($roadmap['sections'])) {
        $rawSections = $roadmap['sections'];
        if ($rawSections instanceof MongoDB\Model\BSONArray) $rawSections = iterator_to_array($rawSections, false);
        if (is_array($rawSections)) {
            foreach ($rawSections as $s) {
                $sArr = (array)$s;
                $topics = $sArr['topics'] ?? [];
                if ($topics instanceof MongoDB\Model\BSONArray) $topics = iterator_to_array($topics, false);
                $topicList = [];
                if (is_array($topics)) {
                    foreach ($topics as $t) {
                        $tArr = (array)$t;
                        $items = $tArr['items'] ?? [];
                        if ($items instanceof MongoDB\Model\BSONArray) $items = iterator_to_array($items, false);
                        $itemList = [];
                        if (is_array($items)) {
                            foreach ($items as $it) {
                                $itArr = (array)$it;
                                $itemList[] = [
                                    'text' => (string)($itArr['text'] ?? $itArr['title'] ?? ''),
                                    'type' => (string)($itArr['type'] ?? 'learning')
                                ];
                            }
                        }
                        $topicList[] = [
                            'title' => (string)($tArr['title'] ?? ''),
                            'items' => $itemList
                        ];
                    }
                }
                $sections[] = [
                    'title' => (string)($sArr['title'] ?? ''),
                    'topics' => $topicList
                ];
            }
        }
    }

    $tags = [];
    if (isset($roadmap['tags'])) {
        $rawTags = $roadmap['tags'];
        if ($rawTags instanceof MongoDB\Model\BSONArray) $rawTags = iterator_to_array($rawTags, false);
        if (is_array($rawTags)) $tags = array_map('strval', $rawTags);
    }

    $roadmapContext = [
        'title' => (string)($roadmap['title'] ?? ''),
        'level' => (string)($roadmap['level'] ?? ''),
        'hours' => (int)($roadmap['hours'] ?? 0),
        'tags' => $tags,
        'sections' => $sections
    ];

    // Persist user message
    $ts = time();
    $db->ai_chat_history->updateOne(
        ['user_id' => $userId, 'roadmap_id' => (string)$roadmapId],
        ['$push' => [
            'messages' => [
                '$each' => [
                    ['role' => 'user', 'content' => $query, 'timestamp' => $ts]
                ]
            ]
        ]],
        ['upsert' => true]
    );

    $rabbit = new RabbitClient();

    // Check for deterministic answers first
    $qLower = strtolower(trim($query));

    // Deterministic: roadmap overview
    if (preg_match('/(roadmap\s+overview|what\s+is\s+this\s+roadmap|summarize|summary)/i', $qLower)) {
        $answer = "## " . $roadmapContext['title'] . "\n\n";
        $answer .= "**Level:** " . $roadmapContext['level'] . " | **Hours:** " . $roadmapContext['hours'] . "h\n\n";
        if (!empty($tags)) $answer .= "**Tags:** " . implode(', ', $tags) . "\n\n";
        $answer .= "### Sections:\n";
        foreach ($sections as $i => $sec) {
            $answer .= ($i + 1) . ". **" . $sec['title'] . "**\n";
            foreach ($sec['topics'] as $t) {
                $answer .= "   - " . $t['title'] . "\n";
            }
        }

        // Persist AI response
        $db->ai_chat_history->updateOne(
            ['user_id' => $userId, 'roadmap_id' => (string)$roadmapId],
            ['$push' => [
                'messages' => [
                    '$each' => [
                        ['role' => 'model', 'content' => $answer, 'timestamp' => $ts + 1, 'tool' => 'Roadmap Overview']
                    ]
                ]
            ]],
            ['upsert' => true]
        );

        $rabbit->sendMessage([
            'type' => 'tool_execution',
            'message_id' => $messageId,
            'tool_name' => 'Roadmap Overview',
            'tool_output' => 'Generated locally'
        ], "ai_stream.{$sessionId}");

        $rabbit->sendMessage([
            'type' => 'text_delta',
            'data' => $answer
        ], "ai_stream.{$sessionId}");

        $rabbit->sendMessage([
            'type' => 'stream_end',
            'source' => 'local',
            'usage' => [
                'source' => 'local',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cached_tokens' => 0,
                'total_tokens' => 0,
                'cache_hit_percent' => 0,
                'tool_name' => 'Roadmap Overview'
            ]
        ], "ai_stream.{$sessionId}");

        echo json_encode([
            'status' => 'success',
            'routed' => 'deterministic',
            'session_id' => $sessionId,
            'message_id' => $messageId
        ]);
        exit;
    }

    // Deterministic: what topics are in section X
    if (preg_match('/(what|list|show)\s+(topics|items|content)\s+(in|of|for)\s+(section\s+)?(\d+)/i', $qLower, $m)) {
        $secIdx = (int)$m[5] - 1;
        if (isset($sections[$secIdx])) {
            $sec = $sections[$secIdx];
            $answer = "## " . $sec['title'] . "\n\n";
            foreach ($sec['topics'] as $t) {
                $answer .= "- **" . $t['title'] . "**\n";
                foreach ($t['items'] as $it) {
                    $answer .= "  - " . $it['text'] . " (" . $it['type'] . ")\n";
                }
            }

            $db->ai_chat_history->updateOne(
                ['user_id' => $userId, 'roadmap_id' => (string)$roadmapId],
                ['$push' => [
                    'messages' => [
                        '$each' => [
                            ['role' => 'model', 'content' => $answer, 'timestamp' => $ts + 1, 'tool' => 'Section Lookup']
                        ]
                    ]
                ]],
                ['upsert' => true]
            );

            $rabbit->sendMessage([
                'type' => 'tool_execution',
                'message_id' => $messageId,
                'tool_name' => 'Section Lookup',
                'tool_output' => 'Found section: ' . $sec['title']
            ], "ai_stream.{$sessionId}");

            $rabbit->sendMessage([
                'type' => 'text_delta',
                'data' => $answer
            ], "ai_stream.{$sessionId}");

            $rabbit->sendMessage([
                'type' => 'stream_end',
                'source' => 'local',
                'usage' => [
                    'source' => 'local',
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cached_tokens' => 0,
                    'total_tokens' => 0,
                    'cache_hit_percent' => 0,
                    'tool_name' => 'Section Lookup'
                ]
            ], "ai_stream.{$sessionId}");

            echo json_encode([
                'status' => 'success',
                'routed' => 'deterministic',
                'session_id' => $sessionId,
                'message_id' => $messageId
            ]);
            exit;
        }
    }

    // Route to LLM orchestrator
    $job = [
        'session_id' => $sessionId,
        'message_id' => $messageId,
        'user_id' => $userId,
        'roadmap_id' => (string)$roadmapId,
        'query' => $query,
        'ai_model' => $aiModel,
        'context' => ['roadmap' => $roadmapContext],
        'timestamp' => time()
    ];

    $rabbit->sendToQueue('ai_jobs', $job);

    echo json_encode([
        'status' => 'success',
        'routed' => 'llm_orchestrator',
        'session_id' => $sessionId,
        'message_id' => $messageId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process AI request: ' . $e->getMessage()]);
}
