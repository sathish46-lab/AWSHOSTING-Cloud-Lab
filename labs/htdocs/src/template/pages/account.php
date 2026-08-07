<?php
$user = Session::getUser();
$username = $user?->getUsername() ?? 'Guest';
$firstName = $user?->getFirstName() ?? '';
$lastName = $user?->getLastName() ?? '';
$displayName = trim($firstName . ' ' . $lastName) ?: $username;
?>

<div id="activityApp" class="container-fluid px-3 py-3">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold m-0">Activity & Analytics</h4>
            <p class="text-body-secondary small mb-0 mt-1">Your account activity, visualized.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="/<?= htmlspecialchars($username) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                <i class="bx bx-arrow-back me-1"></i> Back to Profile
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="actRefreshBtn">
                <i class="bx bx-refresh me-1"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Stat Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm act-stat-card">
                <div class="card-body p-3 text-center">
                    <div class="text-body-secondary small mb-1"><i class="bx bx-history me-1"></i>Total Actions</div>
                    <div class="fs-4 fw-bold" id="actStatTotal">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm act-stat-card">
                <div class="card-body p-3 text-center">
                    <div class="text-body-secondary small mb-1"><i class="bx bx-calendar me-1"></i>Active Days</div>
                    <div class="fs-4 fw-bold" id="actStatActiveDays">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm act-stat-card">
                <div class="card-body p-3 text-center">
                    <div class="text-body-secondary small mb-1"><i class="bx bx-week me-1"></i>This Week</div>
                    <div class="fs-4 fw-bold" id="actStatThisWeek">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm act-stat-card">
                <div class="card-body p-3 text-center">
                    <div class="text-body-secondary small mb-1"><i class="bx bx-trending-up me-1"></i>Top Action</div>
                    <div class="fs-4 fw-bold text-truncate" id="actStatTopAction" style="font-size:1rem;">—</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="card border-0 shadow-sm act-chart-card">
                <div class="card-body p-3">
                    <h6 class="fw-bold mb-3"><i class="bx bx-pie-chart-alt me-1 text-primary"></i> Action Breakdown</h6>
                    <div style="height:220px;"><canvas id="actPieChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card border-0 shadow-sm act-chart-card">
                <div class="card-body p-3">
                    <h6 class="fw-bold mb-3"><i class="bx bx-bar-chart-alt-2 me-1 text-primary"></i> Hourly Activity</h6>
                    <div style="height:220px;"><canvas id="actBarChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Timeline + Security Row -->
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-bottom p-3">
                    <h6 class="fw-bold mb-2"><i class="bx bx-list-ul me-1 text-primary"></i> Activity Timeline</h6>
                    <!-- Filters -->
                    <div class="d-flex flex-wrap act-filter-bar">
                        <select class="form-select form-select-sm" id="actFilterAction" style="width:auto;">
                            <option value="">All Actions</option>
                            <option value="create">Create</option>
                            <option value="update">Update</option>
                            <option value="delete">Delete</option>
                            <option value="trash">Trash</option>
                            <option value="restore">Restore</option>
                            <option value="permanent_delete">Permanent Delete</option>
                            <option value="change_password">Password Change</option>
                        </select>
                        <select class="form-select form-select-sm" id="actFilterEntity" style="width:auto;">
                            <option value="">All Types</option>
                            <option value="instance">Instance</option>
                            <option value="vpn_device">VPN Device</option>
                            <option value="service_mysql">MySQL</option>
                            <option value="user">User</option>
                        </select>
                        <input type="text" class="form-control form-control-sm" id="actSearchInput" placeholder="Search..." style="width:160px;">
                    </div>
                </div>
                <div class="card-body p-3" id="actTimeline">
                    <div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                </div>
                <div class="card-footer bg-transparent border-top p-2 d-flex justify-content-between align-items-center">
                    <small class="text-body-secondary" id="actPageInfo">Page 1 of 1</small>
                    <div class="d-flex act-pager">
                        <button class="btn btn-sm btn-outline-secondary" id="actPrevPage" disabled><i class="bx bx-chevron-left"></i></button>
                        <button class="btn btn-sm btn-outline-secondary" id="actNextPage" disabled><i class="bx bx-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm act-security-card">
                <div class="card-header bg-transparent border-bottom p-3">
                    <h6 class="fw-bold mb-0"><i class="bx bx-shield-quarter me-1 text-warning"></i> Security Events</h6>
                </div>
                <div class="card-body p-3" id="actSecurityFeed">
                    <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                </div>
            </div>
        </div>
    </div>

</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.initActivityPage === 'function') {
        window.initActivityPage();
    }
});
</script>
