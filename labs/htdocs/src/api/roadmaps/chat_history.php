<?php
/**
 * Roadmap AI Assist - Chat History API
 * Returns chat history for a given roadmap
 */
require_once __DIR__ . '/../../load.php';

header('Content-Type: text/html');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    http_response_code(401);
    echo '<div class="text-center p-3 text-danger small">Unauthorized.</div>';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$roadmapId = $_GET['roadmap_id'] ?? '';

if (empty($roadmapId)) {
    echo '';
    exit;
}

try {
    $db = DatabaseConnection::getDefaultDatabase();

    $chatDoc = $db->ai_chat_history->findOne([
        'user_id' => $userId,
        'roadmap_id' => (string)$roadmapId
    ]);

    $messages = [];
    if ($chatDoc && isset($chatDoc['messages'])) {
        foreach ($chatDoc['messages'] as $m) {
            $msg = (array)$m;
            $role = $msg['role'] ?? 'user';
            if ($role === 'system_summary') continue;
            $messages[] = [
                'role' => $role,
                'content' => $msg['content'] ?? '',
                'timestamp' => (int)($msg['timestamp'] ?? 0),
                'usage' => isset($msg['usage']) ? (array)$msg['usage'] : null,
                'tools' => isset($msg['tools']) ? (array)$msg['tools'] : null
            ];
        }
    }

    usort($messages, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    $html = '';
    foreach ($messages as $msg) {
        $contentHtml = nl2br(htmlspecialchars($msg['content']));
        $rawMd = htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8');

        if ($msg['role'] === 'user') {
            $html .= '<div class="message-row user-row ms-auto">
                <div class="msg-bubble">
                    <p class="m-0" data-raw-md="' . $rawMd . '">' . $contentHtml . '</p>
                </div>
            </div>';
        } else {
            $usageAttrs = '';
            if (!empty($msg['usage'])) {
                $u = $msg['usage'];
                $usageAttrs = sprintf(' data-input-tokens="%d" data-output-tokens="%d" data-cached-tokens="%d" data-total-tokens="%d"',
                    (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0),
                    (int)($u['cached_tokens'] ?? 0), (int)($u['total_tokens'] ?? 0));
            }

            $html .= '<div class="message-row ai-row"' . $usageAttrs . '>
                <div class="msg-avatar"><img src="/assets/logo/logo.png" style="width:30px;" alt="AI"></div>
                <div class="msg-content-wrapper d-flex flex-column" style="max-width:85%;width:100%;">
                    <div class="msg-bubble w-100 ai-transparent-bubble" style="background:transparent!important;border:none!important;box-shadow:none!important;padding:0!important;">
                        <p class="m-0" data-raw-md="' . $rawMd . '">' . $contentHtml . '</p>
                    </div>
                </div>
            </div>';
        }
    }

    echo $html;

} catch (Exception $e) {
    echo '<div class="text-center p-3 text-danger small">Failed to load chat history.</div>';
}
