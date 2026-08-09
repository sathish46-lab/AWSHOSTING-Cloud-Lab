<?php
$titleParts = explode(' / ', Session::$pageTitle);
$labHash = Session::get('full_instance_hash');
$challengeHash = Session::get('challenge_instance_hash');

// Always prepend Home if it's not there
if (strtolower(trim($titleParts[0])) !== 'home') {
    array_unshift($titleParts, 'Home');
}

$count = count($titleParts);
$hasLabsContext = false;
$hasChallengesContext = false;

foreach ($titleParts as $index => $part) {
    $isLast = ($index === $count - 1);
    $displayPart = trim($part);
    $url = null;
    $lowerPart = strtolower($displayPart);

    // Check context
    if (stripos($lowerPart, 'lab') !== false) {
        $hasLabsContext = true;
    }
    if (stripos($lowerPart, 'challenge') !== false) {
        $hasChallengesContext = true;
    }

    // 1. Quiz Hub Context Mapping (High Priority Context)
    $quizParent = Session::get('parent_topic');
    $quizSubtopic = Session::get('current_subtopic');
    $quizCategory = Session::get('current_topic');

    if (strcasecmp($lowerPart, 'quiz') === 0) {
        $url = '/quiz';
    } elseif (strcasecmp($lowerPart, 'spot quiz') === 0) {
        $quiz = Session::get('current_quiz');
        $url = $quiz ? "/quiz/v/" . $quiz['hash'] : '/quiz';
    } elseif (($quizParent && strcasecmp(trim($displayPart), trim($quizParent['title'])) === 0) || 
              ($quizCategory && strcasecmp(trim($displayPart), trim($quizCategory['title'])) === 0)) {
        $cat = $quizParent ?? $quizCategory;
        $url = "/quiz/" . ($cat['id'] ?? $cat['_id']);
    } elseif ($quizSubtopic && strcasecmp(trim($displayPart), trim($quizSubtopic['title'])) === 0) {
        $catId = $quizParent ? ($quizParent['id'] ?? $quizParent['_id']) : ($quizCategory ? ($quizCategory['id'] ?? $quizCategory['_id']) : 'all');
        $url = "/quiz/$catId/Recent/" . ($quizSubtopic['id'] ?? $quizSubtopic['_id']);
    
    // 2. Standard Global Mappings (Lower Priority Keywords)
    } elseif (stripos($lowerPart, 'home') !== false) {
        $url = '/';
    } elseif (stripos($lowerPart, 'dashboard') !== false) {
        if ($hasLabsContext && $labHash) {
            $url = "/labs/dashboard/$labHash";
        } elseif ($hasChallengesContext && $challengeHash) {
            $url = "/challenges/dashboard/$challengeHash";
        } else {
            $url = '/dashboard';
        }
    } elseif ($lowerPart === 'lab' || $lowerPart === 'labs') {
        $url = '/labs';
        $displayPart = 'Labs';
    } elseif ($lowerPart === 'challenge' || $lowerPart === 'challenges') {
        $url = '/challenges';
        $displayPart = 'Challenges';
    } elseif ($lowerPart === 'service' || $lowerPart === 'services') {
        $url = '/services';
        $displayPart = 'Services';
    } elseif (stripos($lowerPart, 'mysql server') !== false) {
        $url = '/services/mysql';
        $displayPart = 'MySQL Server';
    } elseif (stripos($lowerPart, 'mariadb server') !== false) {
        $url = '/services/mariadb';
        $displayPart = 'MariaDB Server';
    } elseif (stripos($lowerPart, 'postgresql server') !== false) {
        $url = '/services/postgresql';
        $displayPart = 'PostgreSQL Server';
    } elseif (stripos($lowerPart, 'mongodb server') !== false) {
        $url = '/services/mongodb';
        $displayPart = 'MongoDB Server';
    } elseif (stripos($lowerPart, 'rabbitmq server') !== false) {
        $url = '/services/rabbitmq';
        $displayPart = 'RabbitMQ Server';
    } elseif (stripos($lowerPart, 'redis server') !== false) {
        $url = '/services/redis';
        $displayPart = 'Redis Server';
    } elseif (stripos($lowerPart, 'device') !== false) {
        $url = '/devices';
    } elseif (stripos($lowerPart, 'network') !== false) {
        $url = '/network';
    } elseif (stripos($lowerPart, 'domain') !== false) {
        if ($hasLabsContext && $labHash) {
            $url = "/labs/domains/$labHash";
        } else {
            $url = '/domains';
        }
    } elseif (stripos($lowerPart, 'pref') !== false && $hasLabsContext && $labHash) {
        $url = "/labs/preferences/$labHash";
    } elseif (stripos($lowerPart, 'account') !== false) {
        $url = '/account';
    } elseif (stripos($lowerPart, 'ssl') !== false) {
        $url = '/ssl';
    } elseif (stripos($lowerPart, 'achieve') !== false && $challengeHash) {
        $url = "/challenges/achievements/$challengeHash";
    } elseif (stripos($lowerPart, 'leader') !== false && $challengeHash) {
        $url = "/challenges/leaderboard/$challengeHash";
    }

    $href = $url ? $url : '#';
    $boostAttr = ($href === '/' || $href === '/home' || stripos($href, '/logout') !== false) ? ' hx-boost="false"' : '';
    
    if ($isLast) {
        echo '<li class="breadcrumb-item active"><a href="' . $href . '"' . $boostAttr . ' class="text-decoration-none fw-semibold" style="color: var(--cui-body-color); font-size: 0.85rem;">' . htmlspecialchars($displayPart) . '</a></li>';
    } else {
        echo '<li class="breadcrumb-item"><a href="' . $href . '"' . $boostAttr . ' class="text-decoration-none fw-medium" style="color: var(--cui-secondary-color); font-size: 0.85rem;">' . htmlspecialchars($displayPart) . '</a></li>';
    }
}
?>
