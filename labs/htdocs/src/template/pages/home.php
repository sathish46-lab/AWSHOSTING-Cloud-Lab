<?php
$user = Session::getUser();
$userName = $user ? $user->getUserName() : "User";
$avatar = Session::getAvatar();

// Greeting logic
$hour = date('H');
if ($hour < 12) $greeting = "Morning, champ, ⚡";
elseif ($hour < 17) $greeting = "Afternoon, champ, ⚡";
elseif ($hour < 21) $greeting = "Evening, champ, ⚡";
else $greeting = "Night, champ, ⚡";
?>



<div class="container-fluid min-vh-100 d-flex flex-column align-items-center justify-content-center py-2 position-relative overflow-hidden">

    <!-- User Header Section -->
    <div class="text-center mb-3 home-fade-in">
        <div class="home-avatar">
            <img src="<?= $avatar ?>" alt="Profile" class="home-avatar-img">
            <div class="home-avatar-dot"></div>
        </div>
        <?php 
        $fullName = $user->getFullName();
        $displayTitle = !empty($fullName) ? $fullName : $user->getUsername();
        ?>
        <h1 class="home-greeting-title">
            <?= $greeting ?> <span class="theme-text text-uppercase"><?= htmlspecialchars($displayTitle) ?></span> ✨!
        </h1>
        <p class="home-greeting-sub">State of the art laboratories at the hands and homes of every learner!</p>
        <div class="d-flex flex-column align-items-center">
            <span class="home-plan-badge">
                <i class='bx bxs-check-circle'></i> Pro Plan
            </span>
            <a href="/logout" class="home-signout-link">
                <i class='bx bx-log-out'></i> Sign out
            </a>
        </div>
    </div>

    <!-- Main Navigation Grid -->
    <div class="launcher-grid mb-4">
        <a href="/dashboard" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-speedometer"></use></svg>
            </div>
            <span class="launcher-label">Dashboard</span>
            <span class="launcher-sub">Your control center</span>
        </a>
        <a href="/labs" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-devices"></use></svg>
            </div>
            <span class="launcher-label">Machine Labs</span>
            <span class="launcher-sub">Deploy & practice</span>
        </a>
        <a href="/challenges" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-shield-alt"></use></svg>
            </div>
            <span class="launcher-label">Challenge Labs</span>
            <span class="launcher-sub">CTF & security</span>
        </a>
        <a href="/quiz" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-task"></use></svg>
            </div>
            <span class="launcher-label">Spot Quiz</span>
            <span class="launcher-sub">Test your knowledge</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-code"></use></svg>
            </div>
            <span class="launcher-label">Code Arena</span>
            <span class="launcher-sub">Solve & compete</span>
        </a>
        <a href="/learn" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-education"></use></svg>
            </div>
            <span class="launcher-label">Learn AI</span>
            <span class="launcher-sub">AI-powered lessons</span>
        </a>
        <a href="/roadmaps" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-map"></use></svg>
            </div>
            <span class="launcher-label">Roadmaps</span>
            <span class="launcher-sub">Learning paths</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-chat-bubble"></use></svg>
            </div>
            <span class="launcher-label">Discussions</span>
            <span class="launcher-sub">Ask & answer</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-people"></use></svg>
            </div>
            <span class="launcher-label">Clubs</span>
            <span class="launcher-sub">Find your people</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-calendar"></use></svg>
            </div>
            <span class="launcher-label">Events</span>
            <span class="launcher-sub">Hackathons & more</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-notes"></use></svg>
            </div>
            <span class="launcher-label">Syllabus AI</span>
            <span class="launcher-sub">Exam prep & study</span>
        </a>
        <a href="/devices" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-laptop"></use></svg>
            </div>
            <span class="launcher-label">My Devices</span>
            <span class="launcher-sub">Manage connections</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-bolt"></use></svg>
            </div>
            <span class="launcher-label">Feeling Lucky</span>
            <span class="launcher-sub">Personalized picks</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-chart-line"></use></svg>
            </div>
            <span class="launcher-label">Leaderboard</span>
            <span class="launcher-sub">Rankings & stats</span>
        </a>
        <a href="#" class="launcher-card card blur hvr-grow">
            <div class="launcher-icon-wrapper">
                <svg class="launcher-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-flag-alt"></use></svg>
            </div>
            <span class="launcher-label">Clans</span>
            <span class="launcher-sub">Team up for CTF</span>
        </a>
    </div>
</div>
