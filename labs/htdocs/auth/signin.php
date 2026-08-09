<?php
require_once '../src/load.php';

// Force full page reload if accessed via HTMX
if (isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] == 'true') {
    header('HX-Redirect: /signin');
    exit;
}

// Prevent BFCache and caching of login page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

use Auth\GoogleAuth;

$google = new GoogleAuth();
$metadata = http(get_config('google_oauth')['metadata_url']);

// Handle Google Callback
if (isset($_GET['code'])) {
    $state = $_GET['state'] ?? null;
    $user = $google->handleCallback($metadata, $_GET['code'], $state);
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['username'] = $user['username'];
        $_SESSION['auth_status'] = Constants::STATUS_LOGGEDIN;
        header('Location: /home');
        exit;
    } else {
        header('Location: /finish-signup');
        exit;
    }
}

// Handle Standard Login
$show2faForm = false;
if (isset($_POST['email']) && isset($_POST['password'])) {
    // CSRF validation
    if (!Session::validateCsrf($_POST['_csrf_token'] ?? '')) {
        $loginError = "Invalid security token. Please try again.";
    } else {
        $authResult = UserSession::authenticate($_POST['email'], $_POST['password']);
        if ($authResult === "2fa_required") {
            $show2faForm = true;
        } elseif ($authResult === true) {
            header("Location: /home");
            exit;
        } else {
            $loginError = Session::get('login_error') ?: "Invalid credentials";
        }
    }
}

$google_url = $google->getAuthUrl($metadata);
define('IS_LOGIN_PAGE', true);
Session::$pageTitle = "Sign In";
Session::set('seo_description', 'Sign in to Tom Labs to access your isolated environments and deploy scalable database services.');
Session::set('seo_keywords', 'Sign In, Tom Labs Login, Virtual Labs, Secure Access');
?>
<!DOCTYPE html>
<html lang="en" data-coreui-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="description" content="<?= Session::get('seo_description') ?>">
    <meta name="keywords" content="<?= Session::get('seo_keywords') ?>">
    <title><?= Session::$pageTitle ?></title>
    <link rel="icon" type="image/png" href="<?= Session::cdn3('logo/favicon.png') ?>">
    
    <!-- CoreUI & Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.0.2/dist/css/coreui.min.css" rel="stylesheet">
    
    <!-- Fonts and Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href='https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <style>
        body { 
            margin: 0; padding: 0; 
            background-color: #0f172a; 
            color: white; 
            font-family: 'Ubuntu', sans-serif; 
            overflow-x: hidden;
            background-image: radial-gradient(circle at top right, rgba(56, 189, 248, 0.15), transparent 500px),
                              radial-gradient(circle at bottom left, rgba(251, 146, 60, 0.15), transparent 500px);
        }
        a { text-decoration: none; }
    </style>
</head>
<body data-no-boost="true" hx-boost="false">
<script>
// Safari BFCache fix — force reload when page is restored from back-forward cache
window.addEventListener('pageshow', function(e) {
    if (e.persisted) { window.location.reload(); }
});
</script>


<div class="min-vh-100 d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 d-none d-lg-block pe-lg-5 text-white" style="text-shadow: 0 2px 10px rgba(0,0,0,0.5);">
                <img src="<?= Session::cdn3('logo/logo.png') ?>" width="80" class="mb-4" alt="Logo" style="filter: drop-shadow(0 0 10px rgba(15,159,248,0.5));">
                <h1 id="animated-heading" class="display-4 fw-bolder mb-4" style="line-height: 1.1; letter-spacing: -1px; transition: opacity 0.5s ease-in-out;">
                    Build <span style="color: #fb923c;">Faster.</span><br>
                    Scale <span style="color: #38bdf8;">Smarter.</span>
                </h1>
                
                <div class="p-4 rounded-4" style="background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                    <i class='bx bxs-quote-alt-left fs-2 mb-3 opacity-75' style="color: #fb923c;"></i>
                    <p class="fs-5 fst-italic text-white mb-4" style="line-height: 1.6; text-shadow: 0 1px 2px rgba(0,0,0,0.8);">
                        "Tom Labs has fundamentally transformed how we spin up and tear down our virtual environments. It's not just a tool; it's our entire engineering foundation."
                    </p>
                    <a href="#" onclick="event.preventDefault(); window.open('https://sathish46.in', '_blank'); setTimeout(() => { window.open('https://sathish46.selfmade.fun', '_blank'); }, 800);" class="d-flex align-items-center gap-3 text-decoration-none text-light" style="transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                        <img src="<?= Session::cdn3('author/sathish.png') ?>" width="50" height="50" class="rounded-circle border border-2" style="border-color: #9ca3af; object-fit: cover;" alt="Avatar">
                        <div>
                            <div class="fw-bold fs-6 text-white" style="text-shadow: 0 1px 2px rgba(0,0,0,0.8);">Sathish46 🔥</div>
                            <div class="small" style="color: rgba(255, 255, 255, 0.9); text-shadow: 0 1px 2px rgba(0,0,0,0.8);">CEO & Founder, Tom Labs 😎</div>
                        </div>
                    </a>
                </div>
            </div>

            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    const headings = [
                        { line1: 'Build <span style="color: #fb923c;">Faster.</span>', line2: 'Scale <span style="color: #38bdf8;">Smarter.</span>' },
                        { line1: 'Innovate <span style="color: #fb923c;">Now.</span>', line2: 'Deploy <span style="color: #38bdf8;">Anywhere.</span>' },
                        { line1: 'Code <span style="color: #fb923c;">Securely.</span>', line2: 'Ship <span style="color: #38bdf8;">Instantly.</span>' }
                    ];
                    let currentIndex = 0;
                    const headingBlock = document.getElementById("animated-heading");
                    
                    setInterval(() => {
                        headingBlock.style.opacity = 0;
                        setTimeout(() => {
                            currentIndex = (currentIndex + 1) % headings.length;
                            headingBlock.innerHTML = headings[currentIndex].line1 + '<br>' + headings[currentIndex].line2;
                            headingBlock.style.opacity = 1;
                        }, 500);
                    }, 3000);
                });
            </script>

            <div class="col-md-7 col-lg-4">
                <div class="card shadow-lg border-0 rounded-4 p-4">
                    <div class="card-body">
                        <div class="text-center mb-4 d-lg-none">
                            <img src="<?= Session::cdn3('logo/logo.png') ?>" width="50" class="mb-3" alt="Tom Labs Logo">
                        </div>
                        
                        <h3 class="fw-bold mb-4 text-body">Sign in</h3>

                        <?php if (isset($loginError)): ?>
                            <div class="alert alert-danger small py-2"><?= htmlspecialchars($loginError) ?></div>
                        <?php endif; ?>

                        <?php if ($show2faForm): ?>
                            <!-- 2FA OTP Form -->
                            <div class="text-center mb-4">
                                <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle mb-3" style="width:60px; height:60px;">
                                    <i class='bx bx-check-shield fs-1'></i>
                                </div>
                                <h5 class="fw-bold">Two-Factor Authentication</h5>
                                <p class="small text-secondary mb-0">A 6-digit code has been sent to your email.</p>
                                <span class="badge bg-danger rounded-pill mt-2 fs-6 px-3" id="login-timer-2fa">01:00</span>
                            </div>
                            
                            <form id="login-2fa-form">
                                <div class="mb-4">
                                    <input type="text" name="otp" id="login-otp-input" class="form-control form-control-lg text-center fw-bold fs-3 tracking-widest" maxlength="6" placeholder="------" required autocomplete="off" style="letter-spacing: 10px;">
                                </div>
                                <div id="login-2fa-error" class="text-danger small text-center mb-3 d-none fw-bold"></div>
                                <button type="submit" id="login-verify-btn" class="btn btn-warning btn-lg w-100 fw-bold">Verify & Sign In</button>
                            </form>
                            
                            <script>
                                document.addEventListener('DOMContentLoaded', () => {
                                    const timerDisplay = document.getElementById('login-timer-2fa');
                                    const form = document.getElementById('login-2fa-form');
                                    const errorDiv = document.getElementById('login-2fa-error');
                                    const verifyBtn = document.getElementById('login-verify-btn');
                                    const inputOtp = document.getElementById('login-otp-input');
                                    
                                    inputOtp.focus();
                                    
                                    let timeLeft = 60;
                                    const countdown = setInterval(() => {
                                        timeLeft--;
                                        let secs = timeLeft < 10 ? '0' + timeLeft : timeLeft;
                                        timerDisplay.innerText = "00:" + secs;
                                        if (timeLeft <= 0) {
                                            clearInterval(countdown);
                                            inputOtp.disabled = true;
                                            verifyBtn.disabled = true;
                                            errorDiv.innerText = "OTP Expired. Please refresh to try again.";
                                            errorDiv.classList.remove('d-none');
                                        }
                                    }, 1000);
                                    
                                    form.onsubmit = async (e) => {
                                        e.preventDefault();
                                        verifyBtn.disabled = true;
                                        verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...';
                                        errorDiv.classList.add('d-none');
                                        
                                        const formData = new FormData(form);
                                        try {
                                            const res = await fetch('/api/auth/verify_login_2fa', { method: 'POST', body: formData });
                                            const result = await res.json();
                                            if (result.status === 'success') {
                                                clearInterval(countdown);
                                                timerDisplay.classList.replace('bg-danger', 'bg-success');
                                                timerDisplay.innerText = "Verified!";
                                                window.location.href = '/home';
                                            } else {
                                                errorDiv.innerText = result.error;
                                                errorDiv.classList.remove('d-none');
                                                verifyBtn.disabled = false;
                                                verifyBtn.innerText = 'Verify & Sign In';
                                            }
                                        } catch (err) {
                                            errorDiv.innerText = "Server Error.";
                                            errorDiv.classList.remove('d-none');
                                            verifyBtn.disabled = false;
                                            verifyBtn.innerText = 'Verify & Sign In';
                                        }
                                    };
                                });
                            </script>
                        <?php else: ?>
                            <!-- Standard Login Form -->
                            <form method="POST">
                                <?= Session::csrfField() ?>
                                <div class="mb-3">
                                    <label class="small fw-bold text-secondary mb-1">USERNAME OR EMAIL</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent border-end-0">
                                            <i class="bx bx-user"></i>
                                        </span>
                                        <input type="text" name="email" class="form-control border-start-0 ps-0" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between">
                                        <label class="small fw-bold text-secondary mb-1">PASSWORD</label>
                                        <a href="/forgot" data-no-boost="true" rel="external" class="small text-warning text-decoration-none">Forgot?</a>
                                    </div>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent border-end-0">
                                            <i class="bx bx-lock-alt"></i>
                                        </span>
                                        <input type="password" name="password" class="form-control border-start-0 ps-0" required>
                                    </div>
                                </div>

                                <div class="form-check form-switch mb-4">
                                    <input class="form-check-input" type="checkbox" id="remember">
                                    <label class="form-check-label small text-warning" for="remember">Remember me</label>
                                </div>

                                <button type="submit" class="btn btn-warning btn-lg w-100 mb-3 fw-bold">
                                    Sign in
                                </button>
                            </form>
                        <?php endif; ?>

                        <div class="d-flex align-items-center my-4">
                            <hr class="flex-grow-1 opacity-25">
                            <span class="mx-3 small text-secondary">or sign in with</span>
                            <hr class="flex-grow-1 opacity-25">
                        </div>

                        <a href="<?= $google_url ?>" class="btn btn-outline-secondary btn-lg w-100 d-flex align-items-center justify-content-center fw-semibold">
                            <img src="https://www.gstatic.com/images/branding/product/1x/gsa_512dp.png" width="18" class="me-2" alt="Google Logo">
                            Google
                        </a>

                        <p class="text-center mt-4 mb-0 small text-secondary">
                            Don't have an account? <a href="/signup" class="text-warning fw-bold text-decoration-none">Register now</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>