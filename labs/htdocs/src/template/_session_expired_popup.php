<?php if (Session::get('show_session_expired')): ?>
<!-- Standalone Full Page Session Expired View (Plain Login Theme) -->
<link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.0.2/dist/css/coreui.min.css" rel="stylesheet">
<link href='https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<div class="session-expired-page min-vh-100 w-100 d-flex align-items-center justify-content-center py-5" data-no-boost="true" hx-boost="false">
    <div class="container">
        <div class="row justify-content-center align-items-center g-5">
            <!-- Left Column: Branding & Value Proposition -->
            <div class="col-lg-6 d-none d-lg-block pe-lg-4 text-white branding-section">
                <img src="<?= Session::cdn3('logo/logo.png') ?>" width="80" class="mb-4 logo-img" alt="Logo">
                <h1 class="display-4 fw-bolder mb-4 branding-heading">
                    Session <span class="text-orange">Expired.</span><br>
                    Security <span class="text-blue">Active.</span>
                </h1>
                
                <div class="p-4 rounded-4 branding-info-panel">
                    <i class='bx bxs-shield-check fs-2 mb-3 opacity-75'></i>
                    <p class="fs-5 text-white mb-0">
                        Your secure session has timed out to protect your cloud credentials and running laboratory instances. Please authenticate again to resume your work.
                    </p>
                </div>
            </div>

            <!-- Right Column: Card Action -->
            <div class="col-md-8 col-lg-5">
                <div class="card shadow-lg border-0 rounded-4 p-4 text-white login-card">
                    <div class="card-body text-center p-3">
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle mb-3 lock-icon-ring">
                                <i class='bx bxs-lock-open-alt bx-tada display-4 lock-icon'></i>
                            </div>
                            <h3 class="fw-bold mb-2 text-white">Authentication Required</h3>
                            <p class="small text-secondary mb-0">You must be signed in to access this laboratory endpoint.</p>
                        </div>

                        <div class="d-grid gap-3 mt-4 pt-2">
                            <a href="/signin" data-no-boost="true" rel="external" class="btn btn-warning btn-lg fw-bold d-flex align-items-center justify-content-center gap-2 py-3 shadow-sm rounded-3 btn-signin-gradient">
                                <i class='bx bx-log-in-circle fs-4'></i>
                                <span>Sign In to Continue</span>
                            </a>
                            <a href="/" data-no-boost="true" rel="external" class="btn btn-outline-light btn-lg fw-bold d-flex align-items-center justify-content-center gap-2 py-3 rounded-3 btn-outline-glass">
                                <i class='bx bx-home-alt fs-5'></i>
                                <span>Return to Lobby</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
