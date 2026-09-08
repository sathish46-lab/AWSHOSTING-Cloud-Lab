<?php
$user = Session::getUser();
$avatar = Session::getAvatar();
$fullName = $user?->getFullName() ?? '';
$displayName = !empty($fullName) ? $fullName : ($user?->getUsername() ?? 'User');
$username = $user?->getUsername() ?? '';

// Greeting logic
$hour = date('H');
if ($hour < 12) $greeting = "Still here? Respect,";
elseif ($hour < 17) $greeting = "Good afternoon,";
elseif ($hour < 21) $greeting = "Good evening,";
else $greeting = "Burning the midnight oil,";
?>

<div class="container-fluid py-3">

    <!-- User Header -->
    <div class="text-center mb-4 home-fade-in">
        <div class="d-flex align-items-center justify-content-center gap-3 mb-2">
            <div class="home-avatar">
                <img src="<?= $avatar ?>" alt="Profile" class="home-avatar-img">
            </div>
            <div class="text-start">
                <h2 class="fw-bold mb-0 theme-text"><?= $greeting ?> <span class="text-uppercase"><?= htmlspecialchars($displayName) ?></span> 👋</h2>
                <p class="text-body-secondary small mb-0">State of the art laboratories at the hands and homes of every learner!</p>
            </div>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="row justify-content-center mb-4">
        <div class="col-lg-6">
            <div class="input-group input-group-lg rounded-pill blur shadow-sm border border-secondary border-opacity-10">
                <span class="input-group-text bg-transparent border-0 text-body-secondary"><i class="bx bx-search"></i></span>
                <input type="text" class="form-control bg-transparent border-0 shadow-none" placeholder="Search labs & apps..." id="homeSearch">
                <span class="input-group-text bg-transparent border-0 text-body-secondary">⌘K</span>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="d-flex justify-content-center gap-2 mb-4">
        <button class="btn btn-sm rounded-pill px-4 py-2 fw-semibold tab-btn active" data-tab="labs">
            Labs <span class="badge bg-primary rounded-pill ms-1">2</span>
        </button>
        <button class="btn btn-sm rounded-pill px-4 py-2 fw-semibold tab-btn" data-tab="challenges">
            Challenge Labs
        </button>
    </div>

    <!-- LABS Section -->
    <div class="text-center mb-2">
        <small class="text-body-secondary fw-bold text-uppercase letter-spacing-1">Labs</small>
    </div>
    <div class="row justify-content-center g-3 mb-4" id="labsGrid">
        <div class="col-auto">
            <a href="/labs/essentials" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);">
                        <i class="bx bxs-bot text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Essentials Lab</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/labs/minio" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #c72c41 0%, #ee4540 100%);">
                        <i class="bx bxs-data text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">MiniIO S3</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/labs/docker_lab" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #2d3436 0%, #636e72 100%);">
                        <i class="bx bxl-docker text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Docker Lab</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/labs/kali" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #4a4a4a 0%, #2d2d2d 100%);">
                        <i class="bx bxs-terminal text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Kali Linux</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/labs" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%);">
                        <i class="bx bx-plus text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Deploy a lab</span>
                </div>
            </a>
        </div>
    </div>

    <div class="text-center mb-4">
        <a href="#" class="text-body-secondary small text-decoration-none">Removed (3)</a>
    </div>

    <!-- PLATFORM Section -->
    <div class="text-center mb-3">
        <small class="text-body-secondary fw-bold text-uppercase letter-spacing-1">Platform</small>
    </div>
    <div class="row justify-content-center g-3 mb-4" id="platformGrid">
        <!-- Row 1 -->
        <div class="col-auto">
            <a href="/dashboard" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);">
                        <i class="bx bx-dashboard text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Dashboard</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/devices" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #6c5ce7 0%, #a29bfe 100%);">
                        <i class="bx bx-desktop text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">My Devices</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/labs" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #00b894 0%, #55efc4 100%);">
                        <i class="bx bx-server text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Machine Labs</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/challenges" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #e17055 0%, #d63031 100%);">
                        <i class="bx bx-shield-quarter text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Challenge Labs</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/quiz" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%);">
                        <i class="bx bx-check-circle text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Spot Quiz</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #fdcb6e 0%, #f39c12 100%);">
                        <i class="bx bx-code-alt text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Code Arena</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/learn" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #a29bfe 0%, #6c5ce7 100%);">
                        <i class="bx bx-book-reader text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Learn AI</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/roadmaps" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #00cec9 0%, #81ecec 100%);">
                        <i class="bx bx-map text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Roadmaps</span>
                </div>
            </a>
        </div>

        <!-- Row 2 -->
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #e84393 0%, #fd79a8 100%);">
                        <i class="bx bx-note text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Syllabus AI</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);">
                        <i class="bx bx-chat text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Discussions</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #e17055 0%, #fab1a0 100%);">
                        <i class="bx bx-group text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Clubs</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #d63031 0%, #ff7675 100%);">
                        <i class="bx bx-flag text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Clans</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #fdcb6e 0%, #ffeaa7 100%);">
                        <i class="bx bx-bolt text-dark fs-3"></i>
                    </div>
                    <span class="home-app-label">Feeling Lucky</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="#" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #636e72 0%, #b2bec3 100%);">
                        <i class="bx bx-bar-chart text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Leaderboard</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/mcp" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%);">
                        <i class="bx bx-link text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">MCP Connections</span>
                </div>
            </a>
        </div>
        <div class="col-auto">
            <a href="/services" class="text-decoration-none">
                <div class="home-app-card">
                    <div class="home-app-icon" style="background: linear-gradient(135deg, #2d3436 0%, #636e72 100%);">
                        <i class="bx bx-list-ul text-white fs-3"></i>
                    </div>
                    <span class="home-app-label">Services</span>
                </div>
            </a>
        </div>
    </div>
</div>
