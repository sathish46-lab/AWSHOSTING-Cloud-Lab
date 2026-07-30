<?php
/**
 * Dashboard Template - Pre-rendered for Performance
 */
$user = Session::getUser();
$userId = (int)$user->getUserId();
$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_db');
$uiPrefs = $user ? ($user->getUiPreferences() ?? []) : [];
$activeContinueTab = $uiPrefs['active_continue_tab'] ?? 'setup';

// 1. Fetch Labs
$activeLabsCount = $db->machine_labs->countDocuments(['user_id' => $userId, 'deploy.status' => 'running']);
$labsLimit = 5;
$deployedLabs = $db->machine_labs->find(['user_id' => $userId, 'deploy.status' => 'running'], ['sort' => ['created_at' => -1]]);

$labsList = [];
foreach ($deployedLabs as $lab) {
    $deploy = $lab['deploy'] ?? [];
    $labsList[] = [
        'name' => ucfirst($deploy['lab_type'] ?? 'Lab'),
        'ip' => $deploy['internal_ip'] ?? 'Unknown',
        'status' => $deploy['status'] ?? 'unknown',
        'hash' => $deploy['instance_hash'] ?? '',
        'type' => $deploy['lab_type'] ?? 'unknown'
    ];
}

// 1.5 Fetch Challenge Labs
$username = $user->getUsername();
$challengeLabs = $db->challenge_instances->find(['username' => $username, 'status' => 'running'], ['sort' => ['created_at' => -1]]);

$challengesConfig = [];
$challengesConfigPath = __DIR__ . '/../../config/challenges.json';
if (file_exists($challengesConfigPath)) {
    $challengesConfig = json_decode(file_get_contents($challengesConfigPath), true) ?? [];
}

$challengeLabsList = [];
foreach ($challengeLabs as $clab) {
    $cId = $clab['challenge_id'] ?? '';
    $configKey = str_replace('_', '-', $cId);
    
    $cMeta = null;
    foreach ($challengesConfig as $c) {
        if (($c['lab_id'] ?? '') === $configKey) {
            $cMeta = $c;
            break;
        }
    }

    $cName = $cMeta['name'] ?? ucwords(str_replace('-', ' ', $configKey));
    
    // Find difficulty from tags
    $cDiff = 'Unknown';
    if (!empty($cMeta['tags']) && is_array($cMeta['tags'])) {
        foreach ($cMeta['tags'] as $tag) {
            $tText = strtolower($tag['text'] ?? '');
            if (in_array($tText, ['easy', 'medium', 'hard', 'extreme'])) {
                $cDiff = $tText;
                break;
            }
        }
    }

    // Map difficulty to a specific color
    $diffColor = '#2ed573'; // default green
    switch (strtolower($cDiff)) {
        case 'medium': $diffColor = '#ffa502'; break; // orange
        case 'hard':   $diffColor = '#ff4757'; break; // red
        case 'extreme':$diffColor = '#2f3542'; break; // dark
    }

    $challengeLabsList[] = [
        'name' => $cName,
        'difficulty' => $cDiff,
        'diffColor' => $diffColor,
        'image' => $cMeta['image'] ?? '/assets/Background_Img/challenges/shadow.png',
        'ip' => $clab['internal_ip'] ?? 'Unknown',
        'status' => $clab['status'] ?? 'unknown',
        'hash' => $clab['instance_hash'] ?? '',
        'type' => $cId
    ];
}

// 2. Fetch Domains
$domainCount = $db->domains->countDocuments(['user_id' => ['$in' => [(string)$userId, $userId]]]);
$domainsLimit = 20;
$domains = $db->domains->find(['user_id' => ['$in' => [(string)$userId, $userId]]], ['sort' => ['created_at' => -1]]);

// 3. Fetch User profile and stats dynamically
$userEmail = $user->getEmail();
$username = $user->getUsername();
$avatar = Session::getAvatar();

$userStats = $db->user_stats->findOne(['user_email' => $userEmail]);
$zeal = $userStats['zeal'] ?? 0;
$jolt = $userStats['jolt'] ?? 0;

$finishedQuizzes = $db->quiz_attempts->countDocuments(['user_email' => $userEmail]);

// 3.5 Fetch additional stats for Welcome Banner
$challengesSolved = $db->challenge_submissions->countDocuments(['user_email' => $userEmail, 'status' => 'solved']);
$codeSolved = $db->quiz_attempts->countDocuments(['user_email' => $userEmail, '$expr' => ['$eq' => ['$score', '$total']]]);
$lessonsCompleted = $db->user_lessons->countDocuments(['user_email' => $userEmail, 'completed' => true]);
$achievementsCount = $db->user_achievements->countDocuments(['user_email' => $userEmail]);

// 4. Dynamic Recent Activity Aggregator
$activitiesList = [];

// A. Fetch Quiz Attempts
$attempts = $db->quiz_attempts->find(
    ['user_email' => $userEmail],
    ['sort' => ['attempted_at' => -1], 'limit' => 5]
);
foreach ($attempts as $a) {
    $time = isset($a['attempted_at']) ? (int)$a['attempted_at'] : time();
    $activitiesList[] = [
        'timestamp' => $time,
        'icon' => "bx bx-award fs-6",
        'color' => "#f1c40f",
        'bg' => "rgba(241, 196, 15, 0.15)",
        'border' => "rgba(241, 196, 15, 0.30)",
        'text' => "Completed Quiz: scored <strong>" . $a['score'] . "/" . $a['total'] . "</strong>"
    ];
}

// B. Fetch Deployed Labs
$labs = $db->machine_labs->find(
    ['user_id' => $userId],
    ['sort' => ['created_at' => -1], 'limit' => 5]
);
    foreach ($labs as $l) {
    $deploy = $l['deploy'] ?? [];
    $time = isset($deploy['created_at']) ? (int)$deploy['created_at'] : time();
    $isStopped = isset($deploy['status']) && $deploy['status'] === 'stopped';
    
    $activitiesList[] = [
        'timestamp' => $time,
        'icon' => $isStopped ? "bx bx-stop-circle fs-6" : "bx bx-server fs-6",
        'color' => $isStopped ? "#636e72" : "#10ac84",
        'bg' => $isStopped ? "rgba(99, 110, 114, 0.15)" : "rgba(16, 172, 132, 0.15)",
        'border' => $isStopped ? "rgba(99, 110, 114, 0.30)" : "rgba(16, 172, 132, 0.30)",
        'text' => ($isStopped ? "Stopped" : "Deployed") . " <strong>" . ucfirst($deploy['lab_type'] ?? 'sandbox') . "</strong> Lab"
    ];
}

// C. Fetch Domains
$doms = $db->domains->find(
    ['user_id' => ['$in' => [(string)$userId, $userId]]],
    ['sort' => ['created_at' => -1], 'limit' => 5]
);
    foreach ($doms as $d) {
    $time = isset($d['created_at']) ? (int)$d['created_at'] : time();
    $activitiesList[] = [
        'timestamp' => $time,
        'icon' => "bx bx-globe fs-6",
        'color' => "#2e86de",
        'bg' => "rgba(46, 134, 222, 0.15)",
        'border' => "rgba(46, 134, 222, 0.30)",
        'text' => "Mapped domain: <strong>" . htmlspecialchars($d['domain']) . "</strong>"
    ];
}

// D. Fetch SSH Keys
$keys = $db->ssh_keys->find(
    ['user_id' => ['$in' => [(string)$userId, $userId]]],
    ['sort' => ['created_at' => -1], 'limit' => 3]
);
foreach ($keys as $k) {
    $time = isset($k['created_at']) ? (int)$k['created_at'] : time();
    $activitiesList[] = [
        'timestamp' => $time,
        'icon' => "bx bx-key fs-6",
        'color' => "#e74c3c",
        'bg' => "rgba(231, 76, 60, 0.15)",
        'border' => "rgba(231, 76, 60, 0.30)",
        'text' => "Added SSH Key: <strong>" . htmlspecialchars($k['title']) . "</strong>"
    ];
}

// Sort all activities by timestamp DESC
usort($activitiesList, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

// Slice top 5
$activitiesList = array_slice($activitiesList, 0, 5);

// Helper function to format activity elapsed time
if (!function_exists('formatActivityTime')) {
    function formatActivityTime($timestamp) {
        $diff = time() - $timestamp;
        if ($diff < 0) return 'Just now';
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return round($diff / 60) . 'm ago';
        if ($diff < 86400) return round($diff / 3600) . 'h ago';
        if ($diff < 604800) return round($diff / 86400) . 'd ago';
        return date('M j', $timestamp);
    }
}

// Premium, highly motivational and situation-based dynamic greetings based on hour of the day
$hour = (int)date('H');
$quotes = [];

if ($hour >= 0 && $hour < 5) {
    // Late Night / Early Hours (12 AM - 5 AM)
    $quotes = [
        "The quiet hours suit you, {$username}!",
        "Deep focus mode active. Keep grinding, {$username}!",
        "While the world sleeps, the legends build. Keep it up, {$username}!",
        "Night owl grind is where the magic happens, {$username}!",
        "Quiet night, burning screen. Leveling up your craft, {$username}.",
        "The dark hours reveal the brightest minds. Keep going, {$username}!",
        "Silence around, brilliant code inside. You've got this, {$username}!",
        "Pushing boundaries while others dream. Keep building, {$username}!",
        "Late night commits lead to daylight success. Stay strong, {$username}!",
        "Under the cover of night, masters refine their craft, {$username}!"
    ];
} elseif ($hour >= 5 && $hour < 9) {
    // Early Morning (5 AM - 9 AM)
    $quotes = [
        "Rise and grind, {$username}! A fresh day to conquer.",
        "The early bird gets the code. Let's make today count, {$username}!",
        "Fresh mind, clean code. Good morning, {$username}!",
        "Start your day with intent, {$username}. You've got this!",
        "A new dawn, a new chance to exceed limits, {$username}!",
        "Fuel your ambition early today, {$username}!",
        "Wake up, level up. Today is your day, {$username}!",
        "Clean slate, infinite potential. Good morning, {$username}!",
        "Seize the day before it begins. Rise and shine, {$username}!",
        "Your future is written in the steps you take this morning, {$username}!"
    ];
} elseif ($hour >= 9 && $hour < 12) {
    // Morning (9 AM - 12 PM)
    $quotes = [
        "Peak productivity hour! Crush your goals, {$username}!",
        "Let's build something beautiful today, {$username}!",
        "Code compiled, goals set. Let's conquer the day, {$username}!",
        "Stay focused, stay inspired. You're doing amazing, {$username}!",
        "Hustle mode engaged. Stay sharp, {$username}!",
        "Success isn't given; it is earned line by line, {$username}!",
        "Maintain the energy, master your tasks today, {$username}!",
        "Unlocking peak performance. Let's win, {$username}!",
        "Your dedication is your superpower. Stay on track, {$username}!",
        "Focus on progress, not perfection. Keep moving, {$username}!"
    ];
} elseif ($hour >= 12 && $hour < 17) {
    // Afternoon (12 PM - 5 PM)
    $quotes = [
        "Keep the momentum going, {$username}! Halfway there.",
        "Stay fueled, stay sharp. You are doing great, {$username}!",
        "Afternoon energy check! Ready to crush the rest of the day, {$username}?",
        "One line of code at a time. Keep building, {$username}!",
        "No midday slump can stop a true legend. Power through, {$username}!",
        "Stay persistent. Great things take time and focus, {$username}!",
        "Unleash your drive. The afternoon is yours to own, {$username}!",
        "Success is built on afternoon consistency. Keep pushing, {$username}!",
        "Keep your eyes on the prize. You are closer than you think, {$username}!",
        "Let your passion drive your productivity this afternoon, {$username}!"
    ];
} elseif ($hour >= 17 && $hour < 21) {
    // Evening (5 PM - 9 PM)
    $quotes = [
        "Evening inspiration! Reflect on your wins today, {$username}.",
        "The day is winding down, but your potential is endless, {$username}!",
        "Sunset logic. Time to polish your masterworks, {$username}!",
        "Great work today, {$username}. Keep the flame burning!",
        "Passion projects come alive in the evening. Enjoy the build, {$username}!",
        "Relax the mind, but keep the spark alive, {$username}!",
        "Evening commits are the sweetest. Happy coding, {$username}!",
        "Reflect on how far you've come today. Proud of you, {$username}!",
        "Turn the sunset into your canvas. Keep creating, {$username}!",
        "Consistency in the evening builds character for a lifetime, {$username}!"
    ];
} else {
    // Night (9 PM - 12 AM)
    $quotes = [
        "One more pus, {$username}! You're almost there.",
        "Winding down or leveling up? Either way, you're a legend, {$username}!",
        "Under the stars, code shines the brightest, {$username}!",
        "Refining your craft under the night sky, {$username}. Keep going!",
        "Night mode: ON. Let your focus run free, {$username}.",
        "The night belongs to the creators. Keep designing, {$username}!",
        "End your day on a high note. One last check, {$username}!",
        "Fueling the midnight spark. Keep the dream alive, {$username}!",
        "Excellence is a habit, even under the night sky, {$username}!",
        "Rest well soon, but be proud of the grind tonight, {$username}!"
    ];
}

// Select a completely random quote on every page load
$greetingText = $quotes[array_rand($quotes)];
$greetingText = str_replace($username, '<span class="text-primary">' . htmlspecialchars($username) . '</span>', $greetingText);
?>

<div class="col-lg-11 mx-auto w-100 pb-3">
<div class="p-3 p-lg-3">

    <!-- Welcome Banner + Clan Card -->
    <div class="row mb-3 g-3">
        <div class="col-lg-8">
            <div class="card blur p-3 h-100 position-relative overflow-hidden">
                <!-- Static XP growth curve (SNA style) -->
                <div class="position-absolute" style="bottom: 0; left: 0; right: 0; height: 70%; opacity: 0.3; pointer-events: none; z-index: 0;">
                    <svg viewBox="0 0 800 220" preserveAspectRatio="none" style="width: 100%; height: 100%;">
                        <defs>
                            <linearGradient id="xpGrowthGrad" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%" stop-color="#ff4757" />
                                <stop offset="40%" stop-color="#ffa502" />
                                <stop offset="100%" stop-color="#ff7f50" />
                            </linearGradient>
                        </defs>
                        <path d="M 0,190 Q 100,180 200,175 T 400,160 T 600,120 T 800,80 L 800,220 L 0,220 Z" fill="rgba(255, 110, 50, 0.08)"></path>
                        <path d="M 0,190 Q 100,180 200,175 T 400,160 T 600,120 T 800,80" fill="none" stroke="url(#xpGrowthGrad)" stroke-width="2.5" filter="drop-shadow(0px 2px 6px rgba(255, 100, 50, 0.35))"></path>
                    </svg>
                </div>
                
                <div class="card-body p-3 d-flex flex-column position-relative z-2">
                    <!-- Greeting row -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?= $avatar ?>" alt="Profile" class="rounded-circle flex-shrink-0" style="width: 44px; height: 44px; object-fit: cover;">
                            <div>
                                <h6 class="fw-bold mb-0"><?= $greetingText ?></h6>
                                <small class="text-body-secondary"><?= $finishedQuizzes ?> lessons in progress — keep going!</small>
                            </div>
                        </div>
                        <a href="/profile" class="btn btn-sm btn-secondary rounded-pill flex-shrink-0 d-none d-md-inline-flex align-items-center gap-1 px-3 profile-pill">
                            <i class='bx bx-user'></i> Profile
                        </a>
                    </div>

                    <!-- Currency balances -->
                    <div class="d-flex gap-3 mb-2">
                        <div class="d-flex align-items-center gap-1">
                            <i class='bx bxs-hot' style="color:#f9a825;"></i>
                            <span class="fw-bold" style="font-size:1.1rem;"><?= number_format($zeal) ?></span>
                            <small class="text-body-secondary">zeal</small>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <i class='bx bxs-zap' style="color:#7c3aed;"></i>
                            <span class="fw-bold" style="font-size:1.1rem;"><?= number_format($jolt) ?></span>
                            <small class="text-body-secondary">jolt</small>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <i class='bx bxs-medal' style="color:rgb(var(--cui-primary-rgb));"></i>
                            <span class="fw-bold">#<?= $userRank ?? 3 ?></span>
                            <small class="text-body-secondary">Rank</small>
                        </div>
                    </div>

                    <!-- Activity stats -->
                    <div class="d-flex gap-1 flex-wrap mb-3">
                        <span class="badge bg-success rounded-pill px-3 py-1">
                            <i class='bx bx-check-double me-1'></i> <?= number_format($finishedQuizzes) ?> Quizzes
                        </span>
                        <span class="badge bg-danger rounded-pill px-3 py-1">
                            <i class='bx bx-diamond me-1'></i> <?= number_format($challengesSolved) ?> Challenges
                        </span>
                        <span class="badge bg-primary rounded-pill px-3 py-1">
                            <i class='bx bx-code-block me-1'></i> <?= number_format($codeSolved) ?> Code Solved
                        </span>
                        <span class="badge bg-info rounded-pill px-3 py-1">
                            <i class='bx bx-book-reader me-1'></i> <?= number_format($lessonsCompleted) ?> Lessons
                        </span>
                        <span class="badge bg-warning rounded-pill px-3 py-1">
                            <i class='bx bx-medal me-1'></i> <?= number_format($achievementsCount) ?> Achievements
                        </span>
                    </div>

                    <!-- Shortcut buttons -->
                    <div class="d-flex gap-1 flex-wrap">
                        <a href="/learn" class="badge rounded-pill shortcut-pill shortcut-pill-primary">
                            <i class='bx bxs-brain'></i> AI Learning <span class="rounded-pill shortcut-pill-count"><?= number_format($lessonsCompleted) ?></span>
                        </a>
                        <a href="/labs" class="badge rounded-pill shortcut-pill shortcut-pill-success">
                            <i class='bx bx-desktop'></i> Labs <span class="rounded-pill shortcut-pill-count"><?= number_format($activeLabsCount) ?></span>
                        </a>
                        <a href="#" class="badge rounded-pill shortcut-pill shortcut-pill-info">
                            <i class='bx bx-code-alt'></i> Code Arena <span class="rounded-pill shortcut-pill-count"><?= number_format($codeSolved) ?></span>
                        </a>
                        <a href="#" class="badge rounded-pill shortcut-pill shortcut-pill-warning">
                            <i class='bx bx-map-alt'></i> Roadmaps
                        </a>
                        <a href="/quiz" class="badge rounded-pill shortcut-pill shortcut-pill-danger">
                            <i class='bx bx-check-square'></i> Quizzes <span class="rounded-pill shortcut-pill-count"><?= number_format($finishedQuizzes) ?></span>
                        </a>
                        <a href="#" class="badge rounded-pill shortcut-pill shortcut-pill-neutral">
                            <i class='bx bx-chat'></i> Discuss
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clan Card -->
        <div class="col-lg-4">
            <div class="card blur p-0 h-100 position-relative overflow-hidden clan-card">
                <div class="position-relative p-3 h-100 d-flex flex-column" style="z-index: 1;">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="rounded-circle overflow-hidden flex-shrink-0" style="width: 44px; height: 44px; border: 2px solid rgba(255,255,255,0.3);">
                            <span class="fw-bold text-white d-flex align-items-center justify-content-center w-100 h-100" style="background: rgba(0,0,0,0.45);">ZB</span>
                        </div>
                        <div class="rounded px-2 py-1" style="background: rgba(0,0,0,0.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);">
                            <h6 class="fw-bold mb-0" style="color: #fff;">Zero Byte</h6>
                            <small style="color: rgba(255,255,255,0.7);">@<?= $username ?></small>
                        </div>
                    </div>
                    <div class="rounded p-2 mb-2 flex-grow-1" style="background: rgba(0,0,0,0.35); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bxs-hot" style="font-size: 13px; color:#f9a825;"></i>
                                    <small class="fw-bold" style="color:#fff;">15,941</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Zeal</small>
                            </div>
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bxs-user-detail" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">2</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Members</small>
                            </div>
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bxs-award" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">98</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Badges</small>
                            </div>
                        </div>
                        <div class="row g-2 text-center mt-1">
                            <div class="col-6">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bx-check-square" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">35</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Missions</small>
                            </div>
                            <div class="col-6">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bx-desktop" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">17/56</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Labs Done</small>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
                        <i class="bx bx-group"></i> View Clan
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3">
        <!-- Main Content Area -->
        <div class="col-lg-8">
            <!-- Continue Learning (tabbed) -->
            <div class="card blur p-3 mb-3 continue-learning-card">
                    <!-- Title & Switch Tabs -->
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <h5 class="fw-bold mb-0">Continue Learning</h5>
                            <small class="text-body-secondary">Pick up where you left off</small>
                        </div>
                    </div>
                    <ul class="nav dashboard-tabs mb-3" id="dashboard-tabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link <?= $activeContinueTab === 'setup' ? 'active' : '' ?>" data-tab="setup" type="button" onclick="switchContinueTab('setup')">
                                <i class='bx bx-desktop me-1'></i> Your Setup
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link <?= $activeContinueTab === 'activity' ? 'active' : '' ?>" data-tab="activity" type="button" onclick="switchContinueTab('activity')">
                                <i class='bx bx-time-five me-1'></i> Your Activity
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link <?= $activeContinueTab === 'recommended' ? 'active' : '' ?>" data-tab="recommended" type="button" onclick="switchContinueTab('recommended')">
                                <i class='bx bx-star me-1'></i> Recommended
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Contents Panes -->
                    <div id="continue-tab-panes">
                        
                        <!-- Pane 1: Your Activity -->
                        <div class="continue-tab-pane <?= $activeContinueTab === 'activity' ? '' : 'd-none' ?>" id="continue-pane-activity">
                            <div class="row g-3">
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex align-items-start gap-2 mb-2">
                                                <div class="rounded p-2 flex-shrink-0" style="background: rgba(var(--cui-success-rgb), 0.1);">
                                                    <i class="bx bx-book" style="color: rgb(var(--cui-success-rgb));"></i>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <p class="fw-bold mb-0 small text-truncate" title="Secure Ports and Port Security">Secure Ports and Port Security...</p>
                                                    <small class="text-body-secondary" style="font-size:0.7rem;">Beginner · 2/2 chapters</small>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1 flex-wrap mb-2">
                                                <span class="badge bg-success rounded-pill">ports</span>
                                                <span class="badge bg-success rounded-pill">port security</span>
                                            </div>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-bold" style="font-size:0.75rem; color: rgb(var(--cui-success-rgb));">20%</small>
                                                    <small class="text-body-secondary" style="font-size:0.65rem;">May 5</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar" style="width: 20%; background: rgb(var(--cui-success-rgb));"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex align-items-start gap-2 mb-2">
                                                <div class="rounded p-2 flex-shrink-0" style="background: rgba(var(--cui-warning-rgb), 0.1);">
                                                    <i class="bx bx-code-alt" style="color: rgb(var(--cui-warning-rgb));"></i>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <p class="fw-bold mb-0 small text-truncate" title="Designing and Managing AI Learning">Designing and Managing AI Le...</p>
                                                    <small class="text-body-secondary" style="font-size:0.7rem;">Intermediate · 7/4 chapters</small>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1 flex-wrap mb-2">
                                                <span class="badge bg-warning rounded-pill">ai-assistant</span>
                                                <span class="badge bg-warning rounded-pill">database-design</span>
                                            </div>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-bold" style="font-size:0.75rem; color: rgb(var(--cui-warning-rgb));">35%</small>
                                                    <small class="text-body-secondary" style="font-size:0.65rem;">Apr 25</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar" style="width: 35%; background: rgb(var(--cui-warning-rgb));"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex align-items-start gap-2 mb-2">
                                                <div class="rounded p-2 flex-shrink-0" style="background: rgba(var(--cui-success-rgb), 0.1);">
                                                    <i class="bx bx-book" style="color: rgb(var(--cui-success-rgb));"></i>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <p class="fw-bold mb-0 small text-truncate" title="Secure Headers: A Beginner's Guide">Secure Headers: A Beginner's...</p>
                                                    <small class="text-body-secondary" style="font-size:0.7rem;">Beginner · 5/3 chapters</small>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1 flex-wrap mb-2">
                                                <span class="badge bg-success rounded-pill">HTTP</span>
                                                <span class="badge bg-success rounded-pill">security headers</span>
                                            </div>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-bold" style="font-size:0.75rem; color: rgb(var(--cui-success-rgb));">63%</small>
                                                    <small class="text-body-secondary" style="font-size:0.65rem;">Apr 21</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar" style="width: 63%; background: rgb(var(--cui-success-rgb));"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex align-items-start gap-2 mb-2">
                                                <div class="rounded p-2 flex-shrink-0" style="background: rgba(var(--cui-success-rgb), 0.1);">
                                                    <i class="bx bx-book" style="color: rgb(var(--cui-success-rgb));"></i>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <p class="fw-bold mb-0 small text-truncate" title="Application Security Development">Application Security Develop...</p>
                                                    <small class="text-body-secondary" style="font-size:0.7rem;">Beginner · 9/9 chapters</small>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1 flex-wrap mb-2">
                                                <span class="badge bg-success rounded-pill">appsec</span>
                                                <span class="badge bg-success rounded-pill">secure coding</span>
                                            </div>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-bold" style="font-size:0.75rem; color: rgb(var(--cui-success-rgb));">20%</small>
                                                    <small class="text-body-secondary" style="font-size:0.65rem;">Apr 20</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar" style="width: 20%; background: rgb(var(--cui-success-rgb));"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex align-items-start gap-2 mb-2">
                                                <div class="rounded p-2 flex-shrink-0" style="background: rgba(var(--cui-warning-rgb), 0.1);">
                                                    <i class="bx bx-code-alt" style="color: rgb(var(--cui-warning-rgb));"></i>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <p class="fw-bold mb-0 small text-truncate" title="WebSockets, STOMP, Message Queues">WebSockets, STOMP, Message...</p>
                                                    <small class="text-body-secondary" style="font-size:0.7rem;">Intermediate · 3/3 chapters</small>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1 flex-wrap mb-2">
                                                <span class="badge bg-warning rounded-pill">WebSocket</span>
                                                <span class="badge bg-warning rounded-pill">STOMP</span>
                                            </div>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-bold" style="font-size:0.75rem; color: rgb(var(--cui-warning-rgb));">20%</small>
                                                    <small class="text-body-secondary" style="font-size:0.65rem;">Apr 20</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar" style="width: 20%; background: rgb(var(--cui-warning-rgb));"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mt-2">
                                <a class="text-decoration-none small" href="/learn?tab=continue">
                                    View all <i class='bx bx-chevron-right align-middle'></i>
                                </a>
                            </div>
                        </div>

                        <!-- Pane 2: Your Setup (DYNAMIC!) -->
                        <!-- Pane 2: Your Setup (DYNAMIC!) -->
                        <div class="continue-tab-pane <?= $activeContinueTab === 'setup' ? '' : 'd-none' ?>" id="continue-pane-setup">
                            <div class="row g-3">
                                <!-- Connected Devices Card -->
                                <div class="col-12 col-md-6">
                                    <div class="liquid-rim simple-whitebg p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div>
                                                <h6 class="fw-bold mb-0">Connected Devices</h6>
                                                <small class="text-body-secondary">Sandbox Instances</small>
                                            </div>
                                            <div class="text-end">
                                                <span class="fw-bold fs-4"><?= sprintf("%02d", $activeLabsCount) ?></span><span class="text-body-secondary small fw-semibold">/<?= $labsLimit ?></span>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-column device-list">
                                            <?php if ($activeLabsCount > 0): ?>
                                                <?php foreach ($labsList as $lab): ?>
                                                <?php 
                                                    $bgMap = ['essentials' => '#e95420', 'minio' => '#1a8cff', 'n8n' => '#ff6b81'];
                                                    $bgColor = $bgMap[$lab['type']] ?? '#1a8cff';
                                                    $bgRgbaMap = ['essentials' => '233,84,32', 'minio' => '26,140,255', 'n8n' => '255,107,129'];
                                                    $bgRgba = $bgRgbaMap[$lab['type']] ?? '26,140,255';
                                                    $typeIconMap = ['essentials' => 'bxl-tux', 'minio' => 'bx-cube', 'n8n' => 'bx-git-repo-forked'];
                                                    $iconClass = $typeIconMap[$lab['type']] ?? 'bxl-ubuntu';
                                                ?>
                                                <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.08);">
                                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(<?= $bgRgba ?>, 0.15); border: 1px solid rgba(<?= $bgRgba ?>, 0.30);">
                                                            <i class='bx <?= $iconClass ?>' style="color: <?= $bgColor ?>;"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="fw-bold mb-0 small text-truncate"><?= htmlspecialchars($lab['name']) ?></p>
                                                            <small class="text-body-secondary" style="font-size:0.7rem;">ONLINE</small>
                                                        </div>
                                                    </div>
                                                    <div class="text-end d-flex align-items-center gap-2 flex-shrink-0">
                                                        <div>
                                                            <div class="fw-bold font-monospace" style="font-size:0.8rem;"><?= htmlspecialchars($lab['ip']) ?></div>
                                                            <small class="text-body-secondary" style="font-size:0.6rem;">INTERNAL IP</small>
                                                        </div>
                                                        <button class="btn btn-sm btn-link p-0 text-body-secondary hover-text-primary transition-all btn-copy" data-copy="<?= htmlspecialchars(addslashes($lab['ip'])) ?>">
                                                            <i class="bx bx-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="d-flex align-items-center justify-content-between py-2">
                                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(233, 84, 32, 0.15); border: 1px solid rgba(233, 84, 32, 0.30);">
                                                            <i class='bx bxl-tux' style="color: #e95420;"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="fw-bold mb-0 small">Essentials Lab</p>
                                                            <small class="text-body-secondary" style="font-size:0.7rem;">ONLINE</small>
                                                        </div>
                                                    </div>
                                                    <div class="text-end d-flex align-items-center gap-2 flex-shrink-0">
                                                        <div>
                                                            <div class="fw-bold font-monospace" style="font-size:0.8rem;">172.30.0.28</div>
                                                            <small class="text-body-secondary" style="font-size:0.6rem;">INTERNAL IP</small>
                                                        </div>
                                                        <button class="btn btn-sm btn-link p-0 text-body-secondary hover-text-primary transition-all btn-copy" data-copy="172.30.0.28">
                                                            <i class="bx bx-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex justify-content-center mt-2">
                                            <a class="d-view-more" href="/devices">View All</a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Linked Domains Card -->
                                <div class="col-12 col-md-6">
                                    <div class="liquid-rim simple-whitebg p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div>
                                                <h6 class="fw-bold mb-0">Linked Domains</h6>
                                                <small class="text-body-secondary">Active DNS Records</small>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-success fw-bold fs-4"><?= sprintf("%02d", $domainCount) ?></span><span class="text-body-secondary small fw-semibold">/<?= $domainsLimit ?></span>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-column domain-list">
                                            <?php if ($domainCount > 0): ?>
                                                <?php foreach ($domains as $d): ?>
                                                <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom: 1px solid rgba(var(--cui-body-color-rgb, 255,255,255), 0.08);">
                                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(46, 134, 222, 0.15); border: 1px solid rgba(46, 134, 222, 0.30);">
                                                            <i class='bx bx-globe' style="color: #2e86de;"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="fw-bold mb-0 small text-truncate" title="<?= htmlspecialchars($d['domain']) ?>"><?= htmlspecialchars($d['domain']) ?></p>
                                                            <small class="text-body-secondary" style="font-size:0.7rem;">A RECORD</small>
                                                        </div>
                                                    </div>
                                                    <div class="text-end flex-shrink-0">
                                                        <div class="fw-bold font-monospace" style="font-size:0.8rem;"><?= htmlspecialchars($d['ip_address'] ?? \TomLabs\Core\Env::get('SERVER_IP')) ?></div>
                                                        <small class="text-body-secondary" style="font-size:0.6rem;">IP TARGET</small>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="d-flex align-items-center justify-content-between py-2">
                                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(46, 134, 222, 0.15); border: 1px solid rgba(46, 134, 222, 0.30);">
                                                            <i class='bx bx-globe' style="color: #2e86de;"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="fw-bold mb-0 small">sathish46.selfmade.fun</p>
                                                            <small class="text-body-secondary" style="font-size:0.7rem;">A RECORD</small>
                                                        </div>
                                                    </div>
                                                    <div class="text-end flex-shrink-0">
                                                        <div class="fw-bold font-monospace" style="font-size:0.8rem;"><?= htmlspecialchars(\TomLabs\Core\Env::get('SERVER_IP')) ?></div>
                                                        <small class="text-body-secondary" style="font-size:0.6rem;">IP TARGET</small>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center justify-content-between py-2">
                                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(46, 134, 222, 0.15); border: 1px solid rgba(46, 134, 222, 0.30);">
                                                            <i class='bx bx-globe' style="color: #2e86de;"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <p class="fw-bold mb-0 small">photogram.selfmade.monster</p>
                                                            <small class="text-body-secondary" style="font-size:0.7rem;">A RECORD</small>
                                                        </div>
                                                    </div>
                                                    <div class="text-end flex-shrink-0">
                                                        <div class="fw-bold font-monospace" style="font-size:0.8rem;"><?= htmlspecialchars(\TomLabs\Core\Env::get('SERVER_IP')) ?></div>
                                                        <small class="text-body-secondary" style="font-size:0.6rem;">IP TARGET</small>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex justify-content-center mt-2">
                                            <a class="d-view-more" href="/domains">View All</a>
                                        </div>
                                    </div>
                                </div>
                            </div> <!-- Close Row 1 (Connected Devices & Linked Domains) -->

                            <div class="row g-3 mt-0">
                                <!-- Machine Labs Card -->
                                <div class="col-12 col-md-7">
                                    <div class="liquid-rim simple-whitebg p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="fw-bold mb-0">Machine Labs <span class="badge bg-success rounded-pill ms-1">live</span></h6>
                                            <small class="text-body-secondary">Limit: <?= $activeLabsCount ?>/<?= $labsLimit ?></small>
                                        </div>

                                        <div id="machine-labs-container" class="d-flex flex-column gap-2 machine-labs-list">
                                                 <?php if (!empty($labsList)): ?>
                                                 <?php foreach ($labsList as $lab): ?>
                                                     <?php 
                                                      $bgMap = ['essentials' => '#e95420', 'minio' => '#1a8cff', 'n8n' => '#ff6b81', 'docker_lab' => '#2496ed'];
                                                      $bgColor = $bgMap[$lab['type']] ?? '#1a8cff';
                                                      $bgRgbaMap = ['essentials' => '233,84,32', 'minio' => '26,140,255', 'n8n' => '255,107,129', 'docker_lab' => '36,150,237'];
                                                      $bgRgba = $bgRgbaMap[$lab['type']] ?? '26,140,255';
                                                     $typeIconMap = ['essentials' => 'bxl-tux', 'minio' => 'bx-cube', 'n8n' => 'bx-git-repo-forked', 'docker_lab' => 'bxl-docker'];
                                                     $iconClass = $typeIconMap[$lab['type']] ?? 'bxl-ubuntu';
                                                     $labStatus = strtolower($lab['status']);
                                                     $statusColor = ($labStatus === 'running') ? 'success' : (($labStatus === 'offline') ? 'danger' : 'warning');
                                                     ?>
                                                 <div class="liquid-rim simple-whitebg p-3">
                                                     <div class="d-flex align-items-center justify-content-between w-100">
                                                         <div class="d-flex align-items-center gap-2">
                                                             <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; background: rgba(<?= $bgRgba ?>, 0.15); border: 1px solid rgba(<?= $bgRgba ?>, 0.30);">
                                                                 <i class="bx <?= $iconClass ?>" style="color: <?= $bgColor ?>;"></i>
                                                             </div>
                                                             <div class="d-flex flex-column gap-0.5">
                                                                 <span class="fw-bold small"><?= $lab['name'] ?> Lab</span>
                                                                 <div class="d-flex gap-1 align-items-center">
                                                                     <span class="badge bg-primary rounded-pill fw-bold">beta</span>
                                                                     <span class="badge bg-<?= $statusColor ?> rounded-pill"><?= $labStatus ?></span>
                                                                 </div>
                                                             </div>
                                                         </div>
                                                         <div class="d-flex align-items-center gap-3 text-center">
                                                             <div class="d-flex flex-column align-items-center">
                                                                 <div class="fw-bold small" id="cpu-<?= $lab['hash'] ?>">0.00%</div>
                                                                 <small class="text-body-secondary" style="font-size:0.6rem;">CPU</small>
                                                             </div>
                                                             <div class="d-flex flex-column align-items-center">
                                                                 <div class="fw-bold small" id="mem-<?= $lab['hash'] ?>">0.00%</div>
                                                                 <small class="text-body-secondary" style="font-size:0.6rem;">Mem</small>
                                                             </div>
                                                             <div class="d-flex flex-column align-items-center">
                                                                 <div class="fw-bold small" id="load-<?= $lab['hash'] ?>" style="font-size:0.7rem;">0.00, 0.00, 0.00</div>
                                                                 <small class="text-body-secondary" style="font-size:0.6rem;">Load</small>
                                                             </div>
                                                         </div>
                                                     </div>
                                                     <div class="d-flex justify-content-end w-100 mt-2 gap-2">
                                                         <a href="/labs/dashboard/<?= $lab['hash'] ?>" class="btn btn-sm btn-primary rounded-pill d-flex align-items-center gap-1">
                                                             <i class='bx bx-grid-alt'></i> Dashboard
                                                         </a>
                                                         <button onclick="openCodeModal('<?= $lab['hash'] ?>', '<?= $lab['name'] ?> Lab', '<?= strtolower($lab['status']) ?>')" class="btn btn-sm btn-success rounded-pill d-flex align-items-center gap-1">
                                                             <i class='bx bx-code-alt'></i> Code
                                                         </button>
                                                         <button onclick="openConnectionModal('<?= $lab['hash'] ?>', '<?= $lab['name'] ?> Lab', '<?= strtolower($lab['status']) ?>')" class="btn btn-sm btn-secondary rounded-circle d-flex align-items-center justify-content-center" title="Connection Info">
                                                             <i class='bx bx-info-circle'></i>
                                                         </button>
                                                     </div>
                                                 </div>
                                                 <?php endforeach; ?>
                                                                 <?php else: ?>
                                                 <div class="liquid-rim simple-whitebg p-3">
                                                     <div class="d-flex align-items-center justify-content-between w-100">
                                                         <div class="d-flex align-items-center gap-2">
                                                             <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; background: rgba(233, 84, 32, 0.15); border: 1px solid rgba(233, 84, 32, 0.30);">
                                                                 <i class="bx bxl-tux" style="color: #e95420;"></i>
                                                             </div>
                                                             <div class="d-flex flex-column gap-0.5">
                                                                 <span class="fw-bold small">Essentials Lab</span>
                                                                 <div class="d-flex gap-1 align-items-center">
                                                                     <span class="badge bg-primary rounded-pill">beta</span>
                                                                     <span class="badge bg-success rounded-pill">running</span>
                                                                 </div>
                                                             </div>
                                                         </div>
                                                         <div class="d-flex align-items-center gap-3 text-center">
                                                             <div class="d-flex flex-column align-items-center">
                                                                 <div class="fw-bold small">0.02%</div>
                                                                 <small class="text-body-secondary" style="font-size:0.6rem;">CPU</small>
                                                             </div>
                                                             <div class="d-flex flex-column align-items-center">
                                                                 <div class="fw-bold small">3.76%</div>
                                                                 <small class="text-body-secondary" style="font-size:0.6rem;">Mem</small>
                                                             </div>
                                                             <div class="d-flex flex-column align-items-center">
                                                                 <div class="fw-bold small" style="font-size:0.7rem;">0.00, 0.00, 0.00</div>
                                                                 <small class="text-body-secondary" style="font-size:0.6rem;">Load</small>
                                                             </div>
                                                         </div>
                                                     </div>
                                                     <div class="d-flex justify-content-end w-100 mt-2 gap-2">
                                                         <a href="/labs/dashboard/<?= $user->getLabHash('essentials') ?>" class="btn btn-sm btn-primary rounded-pill d-flex align-items-center gap-1">
                                                             <i class='bx bx-grid-alt'></i> Dashboard
                                                         </a>
                                                         <button onclick="openCodeModal('<?= $user->getLabHash('essentials') ?>', 'Essentials Lab', 'running')" class="btn btn-sm btn-success rounded-pill d-flex align-items-center gap-1">
                                                             <i class='bx bx-code-alt'></i> Code
                                                         </button>
                                                         <button onclick="openConnectionModal('<?= $user->getLabHash('essentials') ?>', 'Essentials Lab', 'running')" class="btn btn-sm btn-secondary rounded-circle d-flex align-items-center justify-content-center" title="Connection Info">
                                                             <i class='bx bx-info-circle'></i>
                                                         </button>
                                                     </div>
                                                 </div>
                                                 <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Challenge Labs Card -->
                                <div class="col-12 col-md-5">
                                    <div class="liquid-rim simple-whitebg p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="fw-bold mb-0">Challenge Labs <span class="badge bg-success rounded-pill ms-1">live</span></h6>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <?php if (!empty($challengeLabsList)): ?>
                                                <?php foreach ($challengeLabsList as $clab): ?>
                                                <div class="liquid-rim simple-whitebg p-3 d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center gap-2" style="min-width: 0;">
                                                        <div class="rounded-circle overflow-hidden flex-shrink-0" style="width: 36px; height: 36px;">
                                                            <img src="<?= htmlspecialchars($clab['image']) ?>" alt="Challenge" class="w-100 h-100 object-fit-cover" onerror="this.src='/assets/Background_Img/challenges/mystery.png';">
                                                        </div>
                                                        <div class="d-flex flex-column justify-content-center min-w-0">
                                                            <span class="fw-bold small text-truncate" title="<?= htmlspecialchars($clab['name']) ?>"><?= htmlspecialchars($clab['name']) ?></span>
                                                            <div class="d-flex gap-1 align-items-center mt-1">
                                                                <span class="badge rounded-pill fw-bold" style="font-size:0.55rem; background: <?= $clab['diffColor'] ?>22; border: 1px solid <?= $clab['diffColor'] ?>45; color: <?= $clab['diffColor'] ?>;"><?= htmlspecialchars(strtolower($clab['difficulty'])) ?></span>
                                                                <span class="badge bg-success rounded-pill fw-bold"><?= htmlspecialchars(strtolower($clab['status'])) ?></span>
        </div>

        <!-- Clan Card -->
        <div class="col-lg-4">
            <div class="card blur p-0 h-100 position-relative overflow-hidden clan-card">
                <div class="position-relative p-3 h-100 d-flex flex-column" style="z-index: 1;">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="rounded-circle overflow-hidden flex-shrink-0" style="width: 44px; height: 44px; border: 2px solid rgba(255,255,255,0.3);">
                            <span class="fw-bold text-white d-flex align-items-center justify-content-center w-100 h-100" style="background: rgba(0,0,0,0.45);">ZB</span>
                        </div>
                        <div class="rounded px-2 py-1" style="background: rgba(0,0,0,0.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);">
                            <h6 class="fw-bold mb-0" style="color: #fff;">Zero Byte</h6>
                            <small style="color: rgba(255,255,255,0.7);">@<?= $username ?></small>
                        </div>
                    </div>
                    <div class="rounded p-2 mb-2 flex-grow-1" style="background: rgba(0,0,0,0.35); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bxs-hot" style="font-size: 13px; color:#f9a825;"></i>
                                    <small class="fw-bold" style="color:#fff;">15,941</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Zeal</small>
                            </div>
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bxs-user-detail" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">2</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Members</small>
                            </div>
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bxs-award" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">98</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Badges</small>
                            </div>
                        </div>
                        <div class="row g-2 text-center mt-1">
                            <div class="col-6">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bx-check-square" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">35</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Missions</small>
                            </div>
                            <div class="col-6">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <i class="bx bx-desktop" style="font-size: 13px; color:rgba(255,255,255,0.8);"></i>
                                    <small class="fw-bold" style="color:#fff;">17/56</small>
                                </div>
                                <small style="font-size:0.6rem;color:rgba(255,255,255,0.6);">Labs Done</small>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
                        <i class="bx bx-group"></i> View Clan
                    </a>
                </div>
            </div>
        </div>
    </div>
                                                    </div>
                                                    <div class="d-flex gap-1 align-items-center flex-shrink-0 ms-1">
                                                        <a href="/challenges/dashboard/<?= $clab['hash'] ?>" class="btn btn-sm btn-success rounded-circle d-flex align-items-center justify-content-center" title="Dashboard">
                                                            <i class='bx bxs-grid-alt'></i>
                                                        </a>
                                                        <a href="/challenges/challenges/<?= $clab['hash'] ?>" class="btn btn-sm btn-primary rounded-circle d-flex align-items-center justify-content-center" title="Challenge">
                                                            <i class='bx bx-target-lock'></i>
                                                        </a>
                                                        <a href="/challenges/leaderboard/<?= $clab['hash'] ?>" class="btn btn-sm btn-info rounded-circle d-flex align-items-center justify-content-center" title="Leaderboard">
                                                            <i class='bx bxs-trophy'></i>
                                                        </a>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="text-center text-body-secondary py-4 small flex-grow-1 d-flex justify-content-center align-items-center">
                                                    No Challenge Labs Running
                                                </div>
                                                <button class="btn btn-sm btn-primary rounded-pill fw-bold py-1.5 transition-all align-self-center mt-auto btn-deploy-challenge">
                                                    Deploy a Challenge Lab
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pane 3: Recommended -->
                        <div class="continue-tab-pane <?= $activeContinueTab === 'recommended' ? '' : 'd-none' ?>" id="continue-pane-recommended">
                            <h6 class="fw-bold mb-3">Recommended For You</h6>
                            <div class="row g-3">
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="rounded p-2" style="background: rgba(var(--cui-primary-rgb), 0.1);">
                                                    <i class='bx bx-code-alt' style="color: rgb(var(--cui-primary-rgb));"></i>
                                                </div>
                                                <span class="badge bg-primary rounded-pill">Next Lesson</span>
                                            </div>
                                            <h6 class="fw-bold mb-1" style="font-size:0.85rem;">Introduction to Cybersecurity for Beginners</h6>
                                            <small class="text-body-secondary">Beginner</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="rounded p-2" style="background: rgba(var(--cui-primary-rgb), 0.1);">
                                                    <i class='bx bx-code-alt' style="color: rgb(var(--cui-primary-rgb));"></i>
                                                </div>
                                                <span class="badge bg-primary rounded-pill">Next Lesson</span>
                                            </div>
                                            <h6 class="fw-bold mb-1" style="font-size:0.85rem;">Elite Ethical Hacking Roadmap: Beginner to...</h6>
                                            <small class="text-body-secondary">Beginner</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="rounded p-2" style="background: rgba(var(--cui-success-rgb), 0.1);">
                                                    <i class='bx bx-terminal' style="color: rgb(var(--cui-success-rgb));"></i>
                                                </div>
                                                <span class="badge bg-success rounded-pill">Practice</span>
                                            </div>
                                            <h6 class="fw-bold mb-1" style="font-size:0.85rem;">Calculate the sum of squares by caching co...</h6>
                                            <small class="text-body-secondary">Easy</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="rounded p-2" style="background: rgba(var(--cui-success-rgb), 0.1);">
                                                    <i class='bx bx-terminal' style="color: rgb(var(--cui-success-rgb));"></i>
                                                </div>
                                                <span class="badge bg-success rounded-pill">Practice</span>
                                            </div>
                                            <h6 class="fw-bold mb-1" style="font-size:0.85rem;">Place stones strategically to cross river ...</h6>
                                            <small class="text-body-secondary">Easy</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <a href="#" class="text-decoration-none d-block h-100">
                                        <div class="liquid-rim simple-whitebg p-3 h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="rounded p-2" style="background: rgba(var(--cui-info-rgb), 0.1);">
                                                    <i class='bx bx-chat' style="color: rgb(var(--cui-info-rgb));"></i>
                                                </div>
                                                <span class="badge bg-primary rounded-pill">Join Discussion</span>
                                            </div>
                                            <h6 class="fw-bold mb-1" style="font-size:0.85rem;">Community Discussions</h6>
                                            <small class="text-body-secondary">Ask questions, share knowledge</small>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

    <!-- Right Sidebar -->
    <div class="col-lg-4">
        <div class="card blur p-3">
            <!-- Recent Activity -->
            <div class="liquid-rim p-3 mb-3">
                <h6 class="fw-bold mb-3">Recent Activity</h6>
                <div class="d-flex flex-column gap-3">
                    <?php if (!empty($activitiesList)): ?>
                        <?php foreach ($activitiesList as $act): ?>
                        <div class="d-flex align-items-center gap-3 small recent-activity-item">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: <?= $act['bg'] ?> !important; border: 1px solid <?= $act['border'] ?> !important;">
                                <i class='<?= $act['icon'] ?>' style="color: <?= $act['color'] ?> !important;"></i>
                            </div>
                            <div class="min-w-0 flex-grow-1">
                                <span class="text-body fw-medium d-block mb-1 activity-text"><?= $act['text'] ?></span>
                                <span class="text-body-secondary opacity-50 activity-time"><?= formatActivityTime($act['timestamp']) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-body-secondary small">
                            <i class="bx bx-history d-block fs-3 mb-1 opacity-20"></i>
                            No recent activity recorded
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Smart Insights -->
            <div class="liquid-rim p-3 mb-3" id="smart-insights-card">
                <h6 class="fw-bold mb-2">Smart Insights</h6>
                <div id="smart-insights-content">
                    <p class="mb-1 small text-body-secondary" id="insights-subtitle">Analyzing your activity...</p>
                    <h5 class="fw-bold mb-0" id="insights-peak-label">
                        <span class="placeholder-glow"><span class="placeholder col-6 rounded"></span></span>
                    </h5>
                </div>
                <div id="insights-bars-container" class="d-flex align-items-end mt-2" style="height: 80px;">
                    <?php for ($i = 0; $i < 24; $i++): ?>
                    <div class="insights-bar" data-hour="<?= $i ?>" style="height: 4%; flex: 1;"></div>
                    <?php endfor; ?>
                </div>
                <div class="position-relative mt-1 w-100 insights-labels">
                    <span class="position-absolute text-body-secondary" style="left: 0; font-size: 0.65rem;">12a</span>
                    <span class="position-absolute text-body-secondary" style="left: 25%; transform: translateX(-50%); font-size: 0.65rem;">6a</span>
                    <span class="position-absolute text-body-secondary" style="left: 50%; transform: translateX(-50%); font-size: 0.65rem;">12p</span>
                    <span class="position-absolute text-body-secondary" style="left: 75%; transform: translateX(-50%); font-size: 0.65rem;">6p</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-1 d-none" id="insights-footer">
                    <span class="small text-body-secondary" id="insights-active-days"></span>
                    <span class="small text-body-secondary" id="insights-last-seen"></span>
                </div>
            </div>

            <!-- Upcoming Events -->
            <div class="liquid-rim p-3 mb-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class='bx bx-calendar-event'></i>
                    <h6 class="fw-bold mb-0">Upcoming Events</h6>
                </div>
                <p class="text-body-secondary small mb-2">No upcoming events</p>
                <a href="#" class="text-decoration-none small text-body-secondary">
                    View all events <i class='bx bx-right-arrow-alt align-middle'></i>
                </a>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Code Info Modal (Simplified IDE Launch) -->
<div class="modal fade" id="codeInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-body-tertiary glass-modal-content">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="modal-title fw-bold text-body mb-0">Code Server Access</h5>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2" id="codeModalLabName">Lab Name</span>
                </div>
                <div id="codeModalLoading" class="text-center py-5">
                    <div class="spinner-grow text-primary" role="status"></div>
                </div>
                <div id="codeModalOffline" class="text-center py-5 d-none">
                    <i class='bx bx-power-off text-danger fs-1 mb-3'></i>
                    <h6 class="text-body fw-bold">Instance is Offline</h6>
                </div>
                <div id="codeModalContent" class="d-none">
                    <div id="codeFields"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-secondary bg-opacity-25 border-0 fw-bold px-4 rounded-pill" data-coreui-dismiss="modal">Dismiss</button>
                <div id="codeModalActionBtn"></div>
            </div>
        </div>
    </div>
</div>

<!-- Technical Connection Info Modal -->
<div class="modal fade" id="connectionInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-body-tertiary glass-modal-content">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="modal-title fw-bold text-body mb-0">Technical Connection Info</h5>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-3 py-2" id="modalLabName">Lab Name</span>
                </div>
                <div id="modalLoading" class="text-center py-5"><div class="spinner-border text-info" role="status"></div></div>
                <div id="modalOffline" class="text-center py-5 d-none">
                    <i class='bx bx-server text-muted fs-1 mb-3'></i>
                    <h6 class="text-body fw-bold">Offline</h6>
                </div>
                <div id="modalContent" class="d-none">
                    <div id="connectionFields"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-secondary bg-opacity-25 border-0 fw-bold px-4 rounded-pill w-100" data-coreui-dismiss="modal">Close Details</button>
            </div>
        </div>
    </div>
</div>
<script>
window.onPageLoad(function() {
    // Initialize premium lab metrics polling
    if (typeof window.initDashboardPolling === 'function') {
        window.initDashboardPolling(<?= json_encode(array_column($labsList, 'hash')) ?>);
    }
    // Initialize Smart Insights activity chart animation
    if (typeof window.initDashboardInsights === 'function') {
        window.initDashboardInsights();
    }
});
</script>